<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Auth\ChildAuth;
use GuardKids\Database\ChildRepository;
use GuardKids\Database\LocationRepository;
use GuardKids\Database\RequestRepository;
use GuardKids\Database\SettingsRepository;
use GuardKids\Database\UsageEventRepository;
use GuardKids\Schedule\ScheduleEvaluator;
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
    private readonly LocationRepository $locations;
    private readonly SettingsRepository $settings;
    private readonly ScheduleEvaluator $evaluator;

    public function __construct()
    {
        $this->auth      = new ChildAuth();
        $this->children  = new ChildRepository();
        $this->requests  = new RequestRepository();
        $this->events    = new UsageEventRepository();
        $this->locations = new LocationRepository();
        $this->settings  = new SettingsRepository();
        $this->evaluator = new ScheduleEvaluator();
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

        $tz       = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $now      = new \DateTimeImmutable('now', $tz);
        $schedule = $this->evaluator->evaluate($row, $now);

        return rest_ensure_response(
            $this->childToJson($row) + ['schedule' => $schedule]
        );
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
        if (! in_array($type, ['heartbeat', 'site_open', 'schedule_block'], true)) {
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

        $detail = null;
        if ($type === 'schedule_block') {
            $rawDetail = (string) $req->get_param('detail');
            if (! in_array($rawDetail, ['bedtime', 'weekday', 'limit'], true)) {
                return new WP_Error('invalid_payload', 'detail inválido.', ['status' => 422]);
            }
            $detail = $rawDetail;
        }

        $id = $this->events->insert([
            'child_id'         => $childId,
            'type'             => $type,
            'domain'           => $domain,
            'detail'           => $detail,
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

    public function reportLocation(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        if (! $this->settings->isLocationEnabled()) {
            return new WP_Error(
                'location_disabled',
                'Localização desativada pelos pais.',
                ['status' => 403]
            );
        }

        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }

        $now = current_time('mysql', true);
        $accuracy = $req->get_param('accuracy');
        $battery  = $req->get_param('battery');

        $id = $this->locations->insert([
            'child_id'    => $childId,
            'latitude'    => (float) $req->get_param('latitude'),
            'longitude'   => (float) $req->get_param('longitude'),
            'accuracy'    => is_numeric($accuracy) ? (int) $accuracy : null,
            'battery'     => is_numeric($battery)  ? (int) $battery  : null,
            'recorded_at' => $now,
        ]);

        if ($id === 0) {
            return new WP_Error('db_error', 'Não foi possível salvar.', ['status' => 500]);
        }

        return new WP_REST_Response([
            'id'         => $id,
            'recordedAt' => gmdate('Y-m-d\TH:i:s\Z', strtotime($now)),
        ], 201);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function createLocationArgs(): array
    {
        return [
            'latitude'  => [
                'type'     => 'number',
                'required' => true,
                'minimum'  => -90,
                'maximum'  => 90,
            ],
            'longitude' => [
                'type'     => 'number',
                'required' => true,
                'minimum'  => -180,
                'maximum'  => 180,
            ],
            'accuracy'  => [
                'type'    => 'integer',
                'minimum' => 0,
                'maximum' => 65535,
            ],
            'battery'   => [
                'type'    => 'integer',
                'minimum' => 0,
                'maximum' => 100,
            ],
        ];
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
                'enum'              => ['heartbeat', 'site_open', 'schedule_block'],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'domain' => [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'detail' => [
                'type'              => 'string',
                'enum'              => ['bedtime', 'weekday', 'limit'],
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
            'id'              => (int) ($row['id'] ?? 0),
            'slug'            => (string) ($row['slug'] ?? ''),
            'name'            => (string) ($row['name'] ?? ''),
            'age'             => isset($row['age']) ? (int) $row['age'] : null,
            'avatarUrl'       => $row['avatar_url'] ?? null,
            'device'          => $row['device'] ?? null,
            'status'          => (string) ($row['status'] ?? 'offline'),
            'usedMinutes'     => (int) ($row['used_minutes'] ?? 0),
            'limitMinutes'    => (int) ($row['limit_minutes'] ?? 60),
            'bedtimeEnabled'  => (int) ($row['bedtime_enabled'] ?? 0) === 1,
            'bedtimeStart'    => isset($row['bedtime_start']) && is_string($row['bedtime_start'])
                                 ? substr($row['bedtime_start'], 0, 5) : null,
            'bedtimeEnd'      => isset($row['bedtime_end']) && is_string($row['bedtime_end'])
                                 ? substr($row['bedtime_end'], 0, 5) : null,
            'allowedWeekdays' => (string) ($row['allowed_weekdays'] ?? 'YYYYYYY'),
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
