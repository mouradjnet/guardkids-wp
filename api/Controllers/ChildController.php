<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Database\ChildRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ChildController
{
    private readonly ChildRepository $repo;

    public function __construct()
    {
        $this->repo = new ChildRepository();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function createArgs(): array
    {
        return [
            'name'          => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            'slug'          => ['type' => 'string', 'sanitize_callback' => 'sanitize_title'],
            'age'           => ['type' => 'integer', 'minimum' => 0, 'maximum' => 21],
            'avatar_url'    => ['type' => 'string', 'sanitize_callback' => 'esc_url_raw'],
            'device'        => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'limit_minutes' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1440],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function updateArgs(): array
    {
        $args = $this->createArgs();
        $args['name']['required'] = false;
        return $args;
    }

    public function index(): WP_REST_Response
    {
        return rest_ensure_response(array_map([$this, 'toJson'], $this->repo->findAll('name')));
    }

    public function show(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $row = $this->repo->findById((int) $req['id']);
        if ($row === null) {
            return new WP_Error('not_found', 'Filho não encontrado.', ['status' => 404]);
        }
        return rest_ensure_response($this->toJson($row));
    }

    public function create(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $name = (string) $req->get_param('name');
        if ($name === '') {
            return new WP_Error('invalid_payload', 'Nome obrigatório.', ['status' => 422]);
        }

        $slug = (string) ($req->get_param('slug') ?? sanitize_title($name));

        $id = $this->repo->insert([
            'slug'          => $slug,
            'name'          => $name,
            'age'           => $req->get_param('age'),
            'avatar_url'    => $req->get_param('avatar_url'),
            'device'        => $req->get_param('device'),
            'status'        => 'offline',
            'used_minutes'  => 0,
            'limit_minutes' => $req->get_param('limit_minutes') ?? 60,
        ]);

        if ($id === 0) {
            return new WP_Error('db_error', 'Não foi possível salvar.', ['status' => 500]);
        }

        $created = $this->repo->findById($id);
        return new WP_REST_Response($this->toJson($created ?? []), 201);
    }

    public function update(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id  = (int) $req['id'];
        $row = $this->repo->findById($id);
        if ($row === null) {
            return new WP_Error('not_found', 'Filho não encontrado.', ['status' => 404]);
        }

        $patch = array_filter([
            'name'          => $req->get_param('name'),
            'age'           => $req->get_param('age'),
            'avatar_url'    => $req->get_param('avatar_url'),
            'device'        => $req->get_param('device'),
            'limit_minutes' => $req->get_param('limit_minutes'),
        ], static fn ($v): bool => $v !== null);

        if ($patch !== [] && ! $this->repo->update($id, $patch)) {
            return new WP_Error('db_error', 'Falha ao atualizar.', ['status' => 500]);
        }
        return rest_ensure_response($this->toJson($this->repo->findById($id) ?? []));
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
        return [
            'id'           => (int) ($row['id'] ?? 0),
            'slug'         => (string) ($row['slug'] ?? ''),
            'name'         => (string) ($row['name'] ?? ''),
            'age'          => isset($row['age']) ? (int) $row['age'] : null,
            'avatarUrl'    => $row['avatar_url'] ?? null,
            'device'       => $row['device'] ?? null,
            'status'       => (string) ($row['status'] ?? 'offline'),
            'usedMinutes'  => (int) ($row['used_minutes'] ?? 0),
            'limitMinutes' => (int) ($row['limit_minutes'] ?? 60),
            'createdAt'    => $row['created_at'] ?? null,
            'updatedAt'    => $row['updated_at'] ?? null,
        ];
    }
}
