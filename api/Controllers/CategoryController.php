<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Database\CategoryRepository;
use GuardKids\License\Gate;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class CategoryController
{
    private readonly CategoryRepository $repo;
    private readonly Gate $gate;

    public function __construct(?Gate $gate = null)
    {
        $this->repo = new CategoryRepository();
        $this->gate = $gate ?? new Gate();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function updateArgs(): array
    {
        return [
            'blocked' => ['type' => 'boolean'],
        ];
    }

    public function index(): WP_REST_Response
    {
        return rest_ensure_response(array_map([$this, 'toJson'], $this->repo->findAll('name')));
    }

    public function update(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        if (! $this->gate->can('categories')) {
            return new WP_Error(
                'plan_limit',
                'Categorias inteligentes é uma feature Premium.',
                ['status' => 402],
            );
        }

        $id = (int) $req['id'];
        $row = $this->repo->findById($id);
        if ($row === null) {
            return new WP_Error('not_found', 'Categoria não encontrada.', ['status' => 404]);
        }

        $blocked = $req->get_param('blocked');
        if ($blocked === null) {
            return new WP_Error('invalid_payload', 'Campo blocked obrigatório.', ['status' => 422]);
        }

        if (! $this->repo->update($id, ['blocked' => $blocked ? 1 : 0])) {
            return new WP_Error('db_error', 'Falha ao atualizar.', ['status' => 500]);
        }
        return rest_ensure_response($this->toJson($this->repo->findById($id) ?? []));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toJson(array $row): array
    {
        return [
            'id'          => (int) ($row['id'] ?? 0),
            'slug'        => (string) ($row['slug'] ?? ''),
            'name'        => (string) ($row['name'] ?? ''),
            'description' => $row['description'] ?? null,
            'icon'        => $row['icon'] ?? null,
            'blocked'     => (bool) ($row['blocked'] ?? false),
        ];
    }
}
