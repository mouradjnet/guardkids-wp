<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Database\SiteRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class SiteController
{
    private readonly SiteRepository $repo;

    public function __construct()
    {
        $this->repo = new SiteRepository();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function createArgs(): array
    {
        return [
            'domain'    => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            'category'  => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'list_type' => ['type' => 'string', 'enum' => ['whitelist', 'blacklist']],
            'applies_to' => ['type' => 'array'],
        ];
    }

    public function index(WP_REST_Request $req): WP_REST_Response
    {
        $list = (string) ($req->get_param('list') ?? 'all');
        $rows = $list === 'all' || $list === ''
            ? $this->repo->findAll('domain')
            : $this->repo->findByList($list);
        return rest_ensure_response(array_map([$this, 'toJson'], $rows));
    }

    public function create(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $domain = (string) $req->get_param('domain');
        if ($domain === '') {
            return new WP_Error('invalid_payload', 'Domínio obrigatório.', ['status' => 422]);
        }

        $appliesTo = $req->get_param('applies_to');
        $id = $this->repo->insert([
            'domain'     => $domain,
            'category'   => $req->get_param('category'),
            'list_type'  => $req->get_param('list_type') ?? 'whitelist',
            'applies_to' => is_array($appliesTo) ? wp_json_encode($appliesTo) : null,
        ]);

        if ($id === 0) {
            return new WP_Error('db_error', 'Não foi possível salvar.', ['status' => 500]);
        }
        return new WP_REST_Response($this->toJson($this->repo->findById($id) ?? []), 201);
    }

    public function destroy(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id = (int) $req['id'];
        if (! $this->repo->delete($id)) {
            return new WP_Error('db_error', 'Falha ao deletar.', ['status' => 500]);
        }
        return rest_ensure_response(['deleted' => true, 'id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toJson(array $row): array
    {
        $appliesTo = $row['applies_to'] ?? null;
        $decoded = $appliesTo ? json_decode((string) $appliesTo, true) : [];
        return [
            'id'        => (int) ($row['id'] ?? 0),
            'domain'    => (string) ($row['domain'] ?? ''),
            'category'  => $row['category'] ?? null,
            'listType'  => (string) ($row['list_type'] ?? 'whitelist'),
            'appliesTo' => is_array($decoded) ? $decoded : [],
            'createdAt' => $row['created_at'] ?? null,
        ];
    }
}
