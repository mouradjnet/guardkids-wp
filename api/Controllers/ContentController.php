<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Auth\ChildAuth;
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

    public function contents(WP_REST_Request $req): WP_REST_Response
    {
        return rest_ensure_response(array_map(static fn (array $r): array => [
            'id'          => (int) $r['id'],
            'categoryId'  => isset($r['category_id']) ? (int) $r['category_id'] : null,
            'title'       => (string) ($r['title'] ?? ''),
            'description' => $r['description'] ?? null,
            'url'         => $r['url'] ?? null,
            'type'        => (string) ($r['type'] ?? 'link'),
            'thumbnail'   => $r['thumbnail'] ?? null,
        ], $this->contentRepo->all()));
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
