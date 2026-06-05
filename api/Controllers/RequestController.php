<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Database\RequestRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class RequestController
{
    private readonly RequestRepository $repo;

    public function __construct()
    {
        $this->repo = new RequestRepository();
    }

    public function index(WP_REST_Request $req): WP_REST_Response
    {
        $status = (string) ($req->get_param('status') ?? 'pending');
        $rows = $status === 'all' || $status === ''
            ? $this->repo->findAll('created_at', 'DESC')
            : $this->repo->findByStatus($status);
        return rest_ensure_response(array_map([$this, 'toJson'], $rows));
    }

    public function approve(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        return $this->decide((int) $req['id'], 'approved');
    }

    public function deny(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        return $this->decide((int) $req['id'], 'denied');
    }

    private function decide(int $id, string $decision): WP_REST_Response|WP_Error
    {
        $row = $this->repo->findById($id);
        if ($row === null) {
            return new WP_Error('not_found', 'Pedido não encontrado.', ['status' => 404]);
        }
        if ($row['status'] !== 'pending') {
            return new WP_Error('already_decided', 'Pedido já foi decidido.', ['status' => 409]);
        }
        if (! $this->repo->decide($id, $decision, get_current_user_id())) {
            return new WP_Error('db_error', 'Falha ao salvar.', ['status' => 500]);
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
            'childId'     => (int) ($row['child_id'] ?? 0),
            'kind'        => (string) ($row['kind'] ?? ''),
            'description' => $row['description'] ?? null,
            'highlight'   => $row['highlight'] ?? null,
            'reason'      => $row['reason'] ?? null,
            'status'      => (string) ($row['status'] ?? 'pending'),
            'decidedAt'   => $row['decided_at'] ?? null,
            'decidedBy'   => isset($row['decided_by']) ? (int) $row['decided_by'] : null,
            'createdAt'   => $row['created_at'] ?? null,
        ];
    }
}
