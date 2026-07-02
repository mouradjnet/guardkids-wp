<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Auth\ChildAuth;
use GuardKids\Content\ContentAnalytics;
use GuardKids\Database\ContentCategoryRepository;
use GuardKids\Database\ContentRepository;
use GuardKids\Database\FavoriteRepository;
use GuardKids\Database\HistoryRepository;
use GuardKids\Database\RecommendationRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Endpoints do módulo Mundo Guardião. Leitura/gestão pelos pais (admin) e
 * POST de favorito pela criança (token). Sprint 1: infra, sem curadoria.
 */
final class ContentController
{
    private readonly ContentCategoryRepository $categoriesRepo;
    private readonly ContentRepository $contentRepo;
    private readonly FavoriteRepository $favorites;
    private readonly RecommendationRepository $recommendations;
    private readonly HistoryRepository $history;
    private readonly ChildAuth $auth;

    public function __construct()
    {
        $this->categoriesRepo  = new ContentCategoryRepository();
        $this->contentRepo     = new ContentRepository();
        $this->favorites       = new FavoriteRepository();
        $this->recommendations = new RecommendationRepository();
        $this->history         = new HistoryRepository();
        $this->auth            = new ChildAuth();
    }

    public function categories(WP_REST_Request $req): WP_REST_Response
    {
        return rest_ensure_response(array_map(static fn (array $r): array => [
            'id'          => (int) $r['id'],
            'slug'        => (string) ($r['slug'] ?? ''),
            'name'        => (string) ($r['name'] ?? ''),
            'icon'        => $r['icon'] ?? null,
            'description' => $r['description'] ?? null,
        ], $this->categoriesRepo->all()));
    }

    public function listContents(WP_REST_Request $req): WP_REST_Response
    {
        $category = $req->get_param('category');
        $search   = $req->get_param('search');
        $rows = $this->contentRepo->search(
            is_numeric($category) ? (int) $category : null,
            is_string($search) ? $search : null,
            null,
        );
        return rest_ensure_response(array_map([$this, 'contentToJson'], $rows));
    }

    public function getContent(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $row = $this->contentRepo->findById((int) $req['id']);
        if ($row === null) {
            return new WP_Error('not_found', 'Conteúdo não encontrado.', ['status' => 404]);
        }
        return rest_ensure_response($this->contentToJson($row));
    }

    public function createContent(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $title = trim((string) $req->get_param('title'));
        if ($title === '') {
            return new WP_Error('invalid_payload', 'Título obrigatório.', ['status' => 422]);
        }
        $id = $this->contentRepo->create($this->contentDataFrom($req, $title));
        if ($id === 0) {
            return new WP_Error('db_error', 'Não foi possível salvar.', ['status' => 500]);
        }
        return new WP_REST_Response($this->contentToJson($this->contentRepo->findById($id) ?? []), 201);
    }

    public function updateContent(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id = (int) $req['id'];
        if ($this->contentRepo->findById($id) === null) {
            return new WP_Error('not_found', 'Conteúdo não encontrado.', ['status' => 404]);
        }
        $title = trim((string) $req->get_param('title'));
        if ($title === '') {
            return new WP_Error('invalid_payload', 'Título obrigatório.', ['status' => 422]);
        }
        $this->contentRepo->update($id, $this->contentDataFrom($req, $title));
        return rest_ensure_response($this->contentToJson($this->contentRepo->findById($id) ?? []));
    }

    public function deleteContent(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id = (int) $req['id'];
        if (! $this->contentRepo->delete($id)) {
            return new WP_Error('db_error', 'Falha ao excluir.', ['status' => 500]);
        }
        return rest_ensure_response(['deleted' => true, 'id' => $id]);
    }

    public function analytics(WP_REST_Request $req): WP_REST_Response
    {
        return rest_ensure_response(ContentAnalytics::compute(
            $this->history->all(),
            $this->contentRepo->all(),
            $this->categoriesRepo->all(),
        ));
    }

    public function updateRecommendation(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id = (int) $req['id'];
        if ($this->recommendations->findById($id) === null) {
            return new WP_Error('not_found', 'Recomendação não encontrada.', ['status' => 404]);
        }
        $patch = [];
        $note = $req->get_param('note');
        if (is_string($note)) {
            $patch['note'] = $note;
        }
        $contentId = $req->get_param('content_id');
        if (is_numeric($contentId)) {
            $patch['content_id'] = (int) $contentId;
        }
        if ($patch !== []) {
            $this->recommendations->update($id, $patch);
        }
        return rest_ensure_response(['ok' => true]);
    }

    public function deleteRecommendation(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id = (int) $req['id'];
        if (! $this->recommendations->delete($id)) {
            return new WP_Error('db_error', 'Falha ao excluir.', ['status' => 500]);
        }
        return rest_ensure_response(['deleted' => true, 'id' => $id]);
    }

    public function reorderRecommendations(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $ids = $req->get_param('ids');
        if (! is_array($ids)) {
            return new WP_Error('invalid_payload', 'ids obrigatório.', ['status' => 422]);
        }
        $this->recommendations->reorder(array_map('intval', $ids));
        return rest_ensure_response(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function contentToJson(array $row): array
    {
        return [
            'id'               => (int) ($row['id'] ?? 0),
            'categoryId'       => isset($row['category_id']) ? (int) $row['category_id'] : null,
            'title'            => (string) ($row['title'] ?? ''),
            'description'      => $row['description'] ?? null,
            'url'              => $row['url'] ?? null,
            'thumbnail'        => $row['thumbnail'] ?? null,
            'type'             => (string) ($row['type'] ?? 'link'),
            'ageMin'           => (int) ($row['age_min'] ?? 0),
            'ageMax'           => (int) ($row['age_max'] ?? 99),
            'estimatedMinutes' => isset($row['estimated_minutes']) ? (int) $row['estimated_minutes'] : null,
            'level'            => $row['level'] ?? null,
            'tags'             => $row['tags'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contentDataFrom(WP_REST_Request $req, string $title): array
    {
        $strOrNull = static function ($v): ?string {
            return is_string($v) && $v !== '' ? $v : null;
        };
        return [
            'title'             => $title,
            'description'       => $strOrNull($req->get_param('description')),
            'category_id'       => is_numeric($req->get_param('categoryId')) ? (int) $req->get_param('categoryId') : null,
            'url'               => $strOrNull($req->get_param('url')),
            'thumbnail'         => $strOrNull($req->get_param('thumbnail')),
            'type'              => is_string($req->get_param('type')) ? (string) $req->get_param('type') : 'link',
            'age_min'           => is_numeric($req->get_param('ageMin')) ? (int) $req->get_param('ageMin') : 0,
            'age_max'           => is_numeric($req->get_param('ageMax')) ? (int) $req->get_param('ageMax') : 99,
            'estimated_minutes' => is_numeric($req->get_param('estimatedMinutes')) ? (int) $req->get_param('estimatedMinutes') : null,
            'level'             => $strOrNull($req->get_param('level')),
            'tags'              => $strOrNull($req->get_param('tags')),
        ];
    }

    public function favoritesList(WP_REST_Request $req): WP_REST_Response
    {
        return rest_ensure_response(array_map(static fn (array $r): array => [
            'id'        => (int) $r['id'],
            'childId'   => (int) ($r['child_id'] ?? 0),
            'contentId' => (int) ($r['content_id'] ?? 0),
            'createdAt' => $r['created_at'] ?? null,
        ], $this->favorites->all()));
    }

    public function recommendationsList(WP_REST_Request $req): WP_REST_Response
    {
        return rest_ensure_response(array_map(static fn (array $r): array => [
            'id'        => (int) $r['id'],
            'childId'   => (int) ($r['child_id'] ?? 0),
            'contentId' => (int) ($r['content_id'] ?? 0),
            'note'      => $r['note'] ?? null,
            'createdAt' => $r['created_at'] ?? null,
        ], $this->recommendations->all()));
    }

    public function summary(WP_REST_Request $req): WP_REST_Response
    {
        return rest_ensure_response([
            'contents'        => $this->contentRepo->count(),
            'categories'      => $this->categoriesRepo->count(),
            'favorites'       => $this->favorites->count(),
            'recommendations' => $this->recommendations->count(),
            'lastSync'        => null,
        ]);
    }

    public function createRecommendation(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId   = (int) $req->get_param('child_id');
        $contentId = (int) $req->get_param('content_id');
        if ($childId === 0 || $contentId === 0) {
            return new WP_Error('invalid_payload', 'child_id e content_id obrigatórios.', ['status' => 422]);
        }
        $note = $req->get_param('note');
        $id = $this->recommendations->add(
            $childId,
            $contentId,
            (int) get_current_user_id(),
            is_string($note) ? $note : null,
        );
        if ($id === 0) {
            return new WP_Error('db_error', 'Não foi possível salvar.', ['status' => 500]);
        }
        return new WP_REST_Response(['id' => $id], 201);
    }

    public function addFavorite(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        $contentId = (int) $req->get_param('content_id');
        if ($contentId === 0) {
            return new WP_Error('invalid_payload', 'content_id obrigatório.', ['status' => 422]);
        }
        $id = $this->favorites->add($childId, $contentId);
        if ($id === 0) {
            return new WP_Error('db_error', 'Não foi possível salvar.', ['status' => 500]);
        }
        return new WP_REST_Response(['id' => $id], 201);
    }
}
