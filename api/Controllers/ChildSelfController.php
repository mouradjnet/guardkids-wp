<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Auth\ChildAuth;
use GuardKids\Auth\ChildPin;
use GuardKids\Avatars\AvatarCatalog;
use GuardKids\Database\ChildRepository;
use GuardKids\Database\LocationRepository;
use GuardKids\Database\NotificationRepository;
use GuardKids\Database\ProgressionRepository;
use GuardKids\Database\PushSubscriptionRepository;
use GuardKids\Database\RequestRepository;
use GuardKids\Database\SettingsRepository;
use GuardKids\Database\SiteRepository;
use GuardKids\Database\UsageEventRepository;
use GuardKids\Notifications\GuardianNotifier;
use GuardKids\Notifications\Notifier;
use GuardKids\Notifications\WebPush\VapidKeys;
use GuardKids\Schedule\ScheduleEvaluator;
use GuardKids\Security\RateLimiter;
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
    private readonly SiteRepository $sites;
    private readonly NotificationRepository $notifications;
    private readonly Notifier $notifier;
    private readonly GuardianNotifier $guardianNotifier;
    private readonly PushSubscriptionRepository $pushSubs;
    private readonly ProgressionRepository $progression;
    private readonly VapidKeys $vapidKeys;
    private readonly ScheduleEvaluator $evaluator;
    private readonly RateLimiter $limiter;
    private readonly ChildPin $pin;

    public function __construct(?RateLimiter $limiter = null, ?ChildPin $pin = null)
    {
        $this->auth      = new ChildAuth();
        $this->children  = new ChildRepository();
        $this->requests  = new RequestRepository();
        $this->events    = new UsageEventRepository();
        $this->locations = new LocationRepository();
        $this->settings  = new SettingsRepository();
        $this->sites     = new SiteRepository();
        $this->notifications = new NotificationRepository();
        $this->notifier      = new Notifier();
        $this->guardianNotifier = new GuardianNotifier();
        $this->pushSubs      = new PushSubscriptionRepository();
        $this->progression   = new ProgressionRepository();
        $this->vapidKeys     = new VapidKeys();
        $this->evaluator = new ScheduleEvaluator();
        $this->limiter   = $limiter ?? new RateLimiter();
        $this->pin       = $pin ?? new ChildPin();
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

        // Janela do dia local convertida pra UTC (created_at é UTC). [00:00, 24:00).
        $utc        = new \DateTimeZone('UTC');
        $startLocal = $now->setTime(0, 0, 0);
        $fromUtc    = $startLocal->setTimezone($utc)->format('Y-m-d H:i:s');
        $toUtc      = $startLocal->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
        $usedMin    = $this->events->minutesUsedInWindow($childId, $fromUtc, $toUtc);

        $schedule = $this->evaluator->evaluate($row, $now, $usedMin);

        if ($schedule['isBlocked'] === false) {
            $this->notifier->persistWarnings($childId, $now, $row, $usedMin);
        }

        return rest_ensure_response(
            $this->childToJson($row) + [
                'schedule'            => $schedule,
                'pinUnlockEnabled'    => $this->pinUnlockEnabled(),
                'unreadNotifications' => $this->notifications->unreadCount($childId),
                'avatarEmoji'         => $this->avatarEmoji($childId),
            ]
        );
    }

    /**
     * Emoji do avatar equipado (progression.equipped_avatar → AvatarCatalog),
     * ou null se sem carteira / avatar default / chave desconhecida.
     */
    private function avatarEmoji(int $childId): ?string
    {
        $wallet = $this->progression->findByChild($childId);
        $key = $wallet !== null ? ($wallet['equipped_avatar'] ?? null) : null;
        if (! is_string($key) || $key === '') {
            return null;
        }
        foreach (AvatarCatalog::all() as $a) {
            if ($a['key'] === $key) {
                return $a['emoji'];
            }
        }
        return null;
    }

    /**
     * Confere o PIN dos pais pra liberar o ambiente seguro no aparelho.
     * Só responde se o desbloqueio estiver ativo (toggle + PIN definido).
     */
    public function verifyPin(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }

        // Checa auth ANTES da feature gate pra não vazar estado do toggle
        // pra quem manda request sem token (mesmo padrão de reportLocation).
        if (! $this->pinUnlockEnabled()) {
            return new WP_Error('pin_disabled', 'Desbloqueio por PIN não está ativo.', ['status' => 403]);
        }

        // Barra brute force do PIN curto via o cap por janela do RateLimiter.
        if (! $this->limiter->allow('pin_verify', $childId)) {
            return new WP_Error(
                'rate_limited',
                'Muitas tentativas. Tente de novo em instantes.',
                ['status' => 429, 'retryAfter' => $this->limiter->retryAfter()],
            );
        }

        return rest_ensure_response(['ok' => $this->pin->verify((string) $req->get_param('pin'))]);
    }

    /**
     * Desbloqueio por PIN disponível quando o toggle dos pais está ligado
     * (default on) E existe um PIN definido. Fail-closed: sem PIN, nunca.
     */
    private function pinUnlockEnabled(): bool
    {
        return $this->pin->isSet() && (bool) $this->settings->get('security.pin_child', true);
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

    /**
     * Sites liberados (whitelist) pro navegador seguro da criança. Mesma fonte
     * que o Companion consome; a tabela só guarda domain + category.
     */
    public function sitesIndex(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }

        return rest_ensure_response(array_map(
            static fn (array $s): array => [
                'domain'   => (string) ($s['domain'] ?? ''),
                'category' => isset($s['category']) && is_string($s['category']) ? $s['category'] : null,
            ],
            $this->sites->findByList('whitelist'),
        ));
    }

    public function notificationsIndex(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        $rows = $this->notifications->findByChild($childId);
        return rest_ensure_response(array_map([$this, 'notificationToJson'], $rows));
    }

    public function notificationsRead(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        return rest_ensure_response(['updated' => $this->notifications->markAllRead($childId)]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function notificationToJson(array $row): array
    {
        return [
            'id'        => (int) ($row['id'] ?? 0),
            'type'      => (string) ($row['type'] ?? ''),
            'title'     => (string) ($row['title'] ?? ''),
            'body'      => $row['body'] ?? null,
            'read'      => ($row['read_at'] ?? null) !== null,
            'createdAt' => $row['created_at'] ?? null,
        ];
    }

    public function pushKey(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        if ($this->auth->resolveChildId($req) === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        return rest_ensure_response(['publicKey' => $this->vapidKeys->publicKey()]);
    }

    public function pushSubscribe(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        $endpoint = (string) $req->get_param('endpoint');
        $keys     = $req->get_param('keys');
        $p256dh   = is_array($keys) ? (string) ($keys['p256dh'] ?? '') : '';
        $auth     = is_array($keys) ? (string) ($keys['auth'] ?? '') : '';
        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            return new WP_Error('invalid_payload', 'Subscription incompleta.', ['status' => 422]);
        }
        $this->pushSubs->upsertByEndpoint($childId, $endpoint, $p256dh, $auth);
        return rest_ensure_response(['ok' => true]);
    }

    public function pushUnsubscribe(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        if ($this->auth->resolveChildId($req) === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        $endpoint = (string) $req->get_param('endpoint');
        if ($endpoint !== '') {
            $this->pushSubs->deleteByEndpoint($endpoint);
        }
        return rest_ensure_response(['ok' => true]);
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

        try {
            $this->guardianNotifier->notifyRequestCreated($created ?? ['id' => $id, 'child_id' => $childId]);
        } catch (\Throwable $e) {
            // Push nunca derruba o gatilho: a criança tem que conseguir pedir
            // mesmo se o serviço de push estiver fora.
            error_log('[GuardKids] notificar guardião falhou: ' . $e->getMessage());
        }

        return new WP_REST_Response($this->requestToJson($created ?? []), 201);
    }

    public function eventsCreate(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }

        if (! $this->limiter->allow('events', $childId)) {
            return new WP_Error(
                'rate_limited',
                'Muitos eventos em pouco tempo. Tente de novo em instantes.',
                ['status' => 429, 'retryAfter' => $this->limiter->retryAfter()],
            );
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

        if ($type === 'schedule_block' && $detail !== null) {
            $this->notifier->notifyBlocked($childId, $detail);

            try {
                if ($detail === 'limit') {
                    $this->guardianNotifier->notifyLimitReached($childId);
                } else {
                    $this->guardianNotifier->notifyBlockedAttempt($childId, $detail);
                }
            } catch (\Throwable $e) {
                error_log('[GuardKids] notificar guardião falhou: ' . $e->getMessage());
            }
        }

        return new WP_REST_Response([
            'id'        => $id,
            'createdAt' => current_time('mysql', true),
        ], 201);
    }

    public function reportLocation(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }

        // Checa auth ANTES da feature gate pra não vazar estado do toggle
        // pra quem manda request sem token (B2 da auditoria).
        if (! $this->settings->isLocationEnabled()) {
            return new WP_Error(
                'location_disabled',
                'Localização desativada pelos pais.',
                ['status' => 403]
            );
        }

        if (! $this->limiter->allow('location', $childId)) {
            return new WP_Error(
                'rate_limited',
                'Muitos reports em pouco tempo. Tente de novo em instantes.',
                ['status' => 429, 'retryAfter' => $this->limiter->retryAfter()],
            );
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
    public function verifyPinArgs(): array
    {
        return [
            'pin' => [
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
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
