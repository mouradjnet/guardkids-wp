<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Auth\ChildAuth;
use GuardKids\Database\ChildRepository;
use GuardKids\Database\RequestRepository;
use GuardKids\Database\UsageEventRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Endpoints autoatendimento da criança — auth via X-GuardKids-Token. O childId
 * sai sempre do token (a request não pode escolher — evita escalada).
 */
final class ChildSelfController
{
    private readonly ChildAuth $auth;
    private readonly ChildRepository $children;
    private readonly RequestRepository $requests;
    private readonly UsageEventRepository $events;

    public function __construct()
    {
        $this->auth     = new ChildAuth();
        $this->children = new ChildRepository();
        $this->requests = new RequestRepository();
        $this->events   = new UsageEventRepository();
    }

    public function me(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        $row = $this->children->findById($childId);
        if ($row === null) {
            return new WP_Error('not_found', 'Filho não encontrado.', ['status' => 404]);
        }
        return rest_ensure_response($this->childToJson($row));
    }

    public function requestsIndex(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        $rows = $this->requests->findByChild($childId);
        return rest_ensure_response(array_map([$this, 'requestToJson'], $rows));
    }

    public function requestsCreate(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }

        $kind = (string) $req->get_param('kind');
        if ($kind === '') {
            return new WP_Error('invalid_payload', 'Campo "kind" obrigatório.', ['status' => 422]);
        }

        $description = $req->get_param('description');
        $highlight   = $req->get_param('highlight');
        $reason      = $req->get_param('reason');

        $id = $this->requests->insert([
            'child_id'    => $childId,
            'kind'        => $kind,
            'description' => is_string($description) ? $description : null,
            'highlight'   => is_string($highlight) ? $highlight : null,
            'reason'      => is_string($reason) ? $reason : null,
            'status'      => 'pending',
        ]);
        if ($id === 0) {
            return new WP_Error('db_error', 'Não foi possível salvar.', ['status' => 500]);
        }

        $created = $this->requests->findById($id);
        return new WP_REST_Response($this->requestToJson($created ?? []), 201);
    }

    public function eventsCreate(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }

        $type = (string) $req->get_param('type');
        if (! in_array($type, ['heartbeat', 'site_open'], true)) {
            return new WP_Error('invalid_payload', 'type inválido.', ['status' => 422]);
        }

        $duration = (int) $req->get_param('duration_seconds');
        if ($duration < 0 || $duration > 3600) {
            return new WP_Error('invalid_payload', 'duration_seconds fora do range.', ['status' => 422]);
        }

        $domain = null;
        if ($type === 'site_open') {
            $raw = (string) $req->get_param('domain');
            if ($raw === '') {
                return new WP_Error('invalid_payload', 'domain obrigatório.', ['status' => 422]);
            }
            $domain = strtolower($raw);
        }

        $id = $this->events->insert([
            'child_id'         => $childId,
            'type'             => $type,
            'domain'           => $domain,
            'duration_seconds' => $duration,
        ]);
        if ($id === 0) {
            return new WP_Error('db_error', 'Não foi possível salvar.', ['status' => 500]);
        }

        return new WP_REST_Response([
            'id'        => $id,
            'createdAt' => current_time('mysql', true),
        ], 201);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function createArgs(): array
    {
        return [
            'kind' => [
                'type'              => 'string',
                'required'          => true,
                'enum'              => ['extra_time', 'unblock_site', 'other'],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'description' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'highlight'   => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'reason'      => ['type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function createEventsArgs(): array
    {
        return [
            'type' => [
                'type'              => 'string',
                'required'          => true,
                'enum'              => ['heartbeat', 'site_open'],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'domain' => [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'duration_seconds' => [
                'type'    => 'integer',
                'minimum' => 0,
                'maximum' => 3600,
                'default' => 0,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function childToJson(array $row): array
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
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function requestToJson(array $row): array
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
            'createdAt'   => $row['created_at'] ?? null,
        ];
    }
}
