<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Database\ChildRepository;
use GuardKids\Database\CompanionDeviceRepository;
use GuardKids\Database\SettingsRepository;
use GuardKids\Database\UsageEventRepository;
use GuardKids\Schedule\ScheduleEvaluator;
use GuardKids\Security\RateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Companion REST endpoints (v1.5.0).
 *
 * Reservado para o GuardKids Companion Android (ainda não implementado).
 * Esta versão prepara o backend — schema, auth via token e fluxo de pairing
 * — sem implementar o app Android.
 *
 * Modelo de tokens (dois papéis distintos, ambos no header
 * X-GuardKids-Companion-Token):
 *   - Pairing token: efêmero (10min), uso único, vai no QR gerado por /pair.
 *     Só serve pra trocar por um token de sessão via /enroll.
 *   - Session token: persistente (sem expiry), emitido por /enroll, usado em
 *     todo sync/heartbeat. Hash mora na linha do device; re-parear o limpa,
 *     revogando o device perdido/roubado.
 *
 * Endpoints:
 *   - GET  /companion/status?child_id=N    (admin)
 *   - POST /companion/pair                 (admin)
 *   - POST /companion/enroll               (pairing token → session token)
 *   - POST /companion/sync                 (session token)
 *   - POST /companion/heartbeat            (session token)
 *
 * Modo de proteção (family|maximum):
 *   - GET  /protection-mode                (admin)
 *   - POST /protection-mode                (admin)
 */
final class CompanionController
{
    private const HEADER_TOKEN  = 'X-GuardKids-Companion-Token';
    private const TOKEN_PREFIX  = 'companion_token:'; // pairing token (efêmero, 10min)
    private const TOKEN_BYTES   = 32;
    private const TOKEN_LENGTH  = 64; // hex
    private const SETTINGS_KEY  = 'protection_mode';

    private const VALID_MODES = ['family', 'maximum'];

    private readonly CompanionDeviceRepository $devices;
    private readonly ChildRepository $children;
    private readonly SettingsRepository $settings;
    private readonly UsageEventRepository $events;
    private readonly ScheduleEvaluator $evaluator;

    public function __construct()
    {
        $this->devices   = new CompanionDeviceRepository();
        $this->children  = new ChildRepository();
        $this->settings  = new SettingsRepository();
        $this->events    = new UsageEventRepository();
        $this->evaluator = new ScheduleEvaluator();
    }

    // -------------------- protection-mode --------------------

    public function getMode(): WP_REST_Response
    {
        $mode = $this->settings->get(self::SETTINGS_KEY, 'family');
        if (! in_array($mode, self::VALID_MODES, true)) {
            $mode = 'family';
        }
        return rest_ensure_response(['mode' => $mode]);
    }

    public function setMode(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $mode = (string) $req->get_param('mode');
        if (! in_array($mode, self::VALID_MODES, true)) {
            return new WP_Error('invalid_payload', 'mode inválido (family|maximum).', ['status' => 422]);
        }
        $this->settings->set(self::SETTINGS_KEY, $mode);
        return rest_ensure_response(['mode' => $mode]);
    }

    public function setModeArgs(): array
    {
        return [
            'mode' => [
                'type'              => 'string',
                'required'          => true,
                'enum'              => self::VALID_MODES,
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    // -------------------- companion/status --------------------

    public function status(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = (int) $req->get_param('child_id');
        if ($childId <= 0) {
            return new WP_Error('invalid_payload', 'child_id obrigatório.', ['status' => 422]);
        }
        $row = $this->devices->findByChildId($childId);
        return rest_ensure_response($this->deviceToJson($row));
    }

    public function statusArgs(): array
    {
        return [
            'child_id' => [
                'type'     => 'integer',
                'required' => true,
                'minimum'  => 1,
            ],
        ];
    }

    // -------------------- companion/pair --------------------

    public function pair(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = (int) $req->get_param('child_id');
        if ($childId <= 0 || $this->children->findById($childId) === null) {
            return new WP_Error('not_found', 'Filho não encontrado.', ['status' => 404]);
        }

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $hash  = hash('sha256', $token);
        $deviceUuid = bin2hex(random_bytes(8));

        $existing = $this->devices->findByChildId($childId);
        if ($existing === null) {
            $this->devices->insert([
                'child_id'    => $childId,
                'device_uuid' => $deviceUuid,
                'device_name' => null,
                'status'      => 'pending',
            ]);
        } else {
            $deviceUuid = (string) $existing['device_uuid'];
            // Re-parear revoga a sessão atual: zera o hash pra que o token de
            // sessão antigo (device perdido/roubado) pare de autenticar já.
            $this->devices->update((int) $existing['id'], [
                'status'             => 'pending',
                'session_token_hash' => null,
            ]);
        }

        $this->settings->set(self::TOKEN_PREFIX . $hash, [
            'childId'     => $childId,
            'deviceUuid'  => $deviceUuid,
            'createdAt'   => gmdate('c'),
            'expiresAt'   => gmdate('c', time() + 600), // 10 minutos
        ]);

        $restRoot = function_exists('rest_url')
            ? rest_url('guardkids/v1')
            : '/wp-json/guardkids/v1';

        return new WP_REST_Response([
            'token'      => $token,
            'deviceUuid' => $deviceUuid,
            'endpoint'   => $restRoot,
            'expiresAt'  => gmdate('c', time() + 600),
            'qrPayload'  => wp_json_encode([
                'v'    => 1,
                'type' => 'gk-companion-pair',
                'child' => $childId,
                'uuid' => $deviceUuid,
                'tok'  => $token,
                'api'  => $restRoot,
            ]),
            'notice'     => 'Token expira em 10 minutos. Aponte a câmera do Companion para o QR.',
        ], 201);
    }

    public function pairArgs(): array
    {
        return [
            'child_id' => [
                'type'     => 'integer',
                'required' => true,
                'minimum'  => 1,
            ],
        ];
    }

    // -------------------- companion/enroll --------------------

    /**
     * Troca o pairing token (efêmero, do QR) por um token de sessão persistente.
     *
     * O Companion chama isto uma vez logo após escanear o QR, apresentando o
     * pairing token no header. Devolvemos um token de sessão novo (sem expiry)
     * que o app guarda e usa em todo sync/heartbeat. O pairing token é de uso
     * único: consumido (deletado) aqui mesmo.
     */
    public function enroll(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $pairing = $this->authenticatePairing($req);
        if ($pairing instanceof WP_Error) {
            return $pairing;
        }
        [$device, $pairingKey] = $pairing;

        if (($limited = $this->rateLimited('companion_enroll', (int) $device['id'])) !== null) {
            return $limited;
        }

        $sessionToken = bin2hex(random_bytes(self::TOKEN_BYTES));
        $sessionHash  = hash('sha256', $sessionToken);

        $this->devices->update((int) $device['id'], [
            'session_token_hash' => $sessionHash,
            'session_expires_at' => $this->devices->expiryFromNow(),
            'status'             => 'active',
        ]);

        // Pairing token é de uso único — não pode ser reapresentado.
        $this->settings->deleteByKey($pairingKey);

        return new WP_REST_Response([
            'sessionToken' => $sessionToken,
            'deviceUuid'   => (string) $device['device_uuid'],
        ], 201);
    }

    // -------------------- companion/sync --------------------

    public function sync(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $device = $this->authenticateSession($req);
        if ($device instanceof WP_Error) {
            return $device;
        }
        if (($limited = $this->rateLimited('companion_sync', (int) $device['id'])) !== null) {
            return $limited;
        }

        $patch = $this->extractDevicePatch($req);
        $patch['status'] = 'active';
        $this->devices->touchSync((int) $device['id'], $patch);

        $fresh = $this->devices->findByUuid((string) $device['device_uuid']);
        $payload = $this->deviceToJson($fresh);
        $payload['block'] = $this->buildBlockVerdict($device);

        return rest_ensure_response($payload);
    }

    /**
     * Veredito de bloqueio por tempo para o device. Server-side, fonte da
     * verdade. Gate de modo global + fail-open em qualquer ausência/erro.
     *
     * @param array<string, mixed> $device linha de companion_devices
     * @return array{isBlocked:bool,reason:?string,unlockAt:?string,nextChangeAt:?string,mode:string}
     */
    private function buildBlockVerdict(array $device): array
    {
        $mode = $this->settings->get(self::SETTINGS_KEY, 'family');
        if (! in_array($mode, self::VALID_MODES, true)) {
            $mode = 'family';
        }

        $unblocked = [
            'isBlocked'    => false,
            'reason'       => null,
            'unlockAt'     => null,
            'nextChangeAt' => null,
            'mode'         => $mode,
        ];

        if ($mode !== 'maximum') {
            return $unblocked; // enforcement OFF em family
        }

        $childId = (int) ($device['child_id'] ?? 0);
        if ($childId <= 0) {
            return $unblocked; // fail-open
        }
        $child = $this->children->findById($childId);
        if ($child === null) {
            return $unblocked; // fail-open
        }

        $tz  = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $tz);
        $utc = new \DateTimeZone('UTC');
        $startLocal = $now->setTime(0, 0, 0);
        $fromUtc = $startLocal->setTimezone($utc)->format('Y-m-d H:i:s');
        $toUtc   = $startLocal->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
        $usedMin = $this->events->minutesUsedInWindow($childId, $fromUtc, $toUtc);

        $verdict = $this->evaluator->evaluate($child, $now, $usedMin);

        return [
            'isBlocked'    => (bool) $verdict['isBlocked'],
            'reason'       => $verdict['reason'],
            'unlockAt'     => $verdict['unlockAt'],
            'nextChangeAt' => $this->evaluator->nextDeterministicChange($child, $now),
            'mode'         => $mode,
        ];
    }

    public function syncArgs(): array
    {
        return [
            'device_name'                => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'android_version'            => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'companion_version'          => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'device_owner_enabled'       => ['type' => 'boolean'],
            'accessibility_enabled'      => ['type' => 'boolean'],
            'device_admin_enabled'       => ['type' => 'boolean'],
            'play_store_enabled'         => ['type' => 'boolean'],
            'settings_locked'            => ['type' => 'boolean'],
            'kiosk_mode'                 => ['type' => 'boolean'],
            'device_shutdown_protection' => ['type' => 'boolean'],
        ];
    }

    // -------------------- companion/heartbeat --------------------

    public function heartbeat(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $device = $this->authenticateSession($req);
        if ($device instanceof WP_Error) {
            return $device;
        }
        if (($limited = $this->rateLimited('companion_heartbeat', (int) $device['id'])) !== null) {
            return $limited;
        }
        $this->devices->touchSync((int) $device['id'], ['status' => 'active']);
        return rest_ensure_response(['ok' => true, 'lastSync' => current_time('mysql', true)]);
    }

    // -------------------- companion/revoke (admin) --------------------

    public function revoke(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = (int) $req->get_param('child_id');
        if ($childId <= 0) {
            return new WP_Error('invalid_payload', 'child_id obrigatório.', ['status' => 422]);
        }
        $device = $this->devices->findByChildId($childId);
        if ($device === null) {
            return new WP_Error('not_found', 'Nenhum dispositivo pareado.', ['status' => 404]);
        }
        $this->devices->revokeSession((int) $device['id']);
        return rest_ensure_response(['revoked' => true]);
    }

    public function revokeArgs(): array
    {
        return [
            'child_id' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
        ];
    }

    // -------------------- helpers --------------------

    private function rateLimited(string $endpoint, int $deviceId): ?WP_Error
    {
        if (! (new RateLimiter())->allow($endpoint, $deviceId)) {
            return new WP_Error('too_many', 'Muitas requisições. Tente novamente em instantes.', ['status' => 429]);
        }
        return null;
    }

    /**
     * Lê e valida o formato do token no header (64 chars hex), devolvendo-o
     * em lowercase. null = ausente ou malformado.
     */
    private function readToken(WP_REST_Request $req): ?string
    {
        $raw = (string) $req->get_header(self::HEADER_TOKEN);
        if ($raw === '' || strlen($raw) !== self::TOKEN_LENGTH || preg_match('/^[a-f0-9]+$/i', $raw) !== 1) {
            return null;
        }
        return strtolower($raw);
    }

    /**
     * Auth do enroll: valida o pairing token (efêmero, do QR) e checa o expiry.
     * Devolve [device, settingsKey] — a key é usada pra consumir o token.
     *
     * @return array{0: array<string, mixed>, 1: string}|WP_Error
     */
    private function authenticatePairing(WP_REST_Request $req): array|WP_Error
    {
        $token = $this->readToken($req);
        if ($token === null) {
            return new WP_Error('companion_auth_required', 'Token do Companion inválido ou ausente.', ['status' => 401]);
        }

        $key  = self::TOKEN_PREFIX . hash('sha256', $token);
        $data = $this->settings->get($key);
        if (! is_array($data) || ! isset($data['deviceUuid'])) {
            return new WP_Error('companion_auth_required', 'Token desconhecido.', ['status' => 401]);
        }

        $expiresAt = isset($data['expiresAt']) && is_string($data['expiresAt'])
            ? strtotime($data['expiresAt'])
            : false;
        if ($expiresAt === false || $expiresAt < time()) {
            $this->settings->deleteByKey($key);
            return new WP_Error('companion_auth_required', 'Token de pareamento expirado.', ['status' => 401]);
        }

        $device = $this->devices->findByUuid((string) $data['deviceUuid']);
        if ($device === null) {
            return new WP_Error('companion_auth_required', 'Dispositivo não encontrado.', ['status' => 401]);
        }
        return [$device, $key];
    }

    /**
     * Auth de sync/heartbeat: valida o token de SESSÃO (persistente, sem expiry)
     * pelo hash gravado na linha do device. Re-parear limpa o hash, revogando.
     *
     * @return array<string, mixed>|WP_Error
     */
    private function authenticateSession(WP_REST_Request $req): array|WP_Error
    {
        $token = $this->readToken($req);
        if ($token === null) {
            return new WP_Error('companion_auth_required', 'Token do Companion inválido ou ausente.', ['status' => 401]);
        }

        $device = $this->devices->findBySessionTokenHash(hash('sha256', $token));
        if ($device === null) {
            return new WP_Error('companion_auth_required', 'Sessão inválida. Refaça o pareamento.', ['status' => 401]);
        }

        $expiresAt = $device['session_expires_at'] ?? null;
        if (is_string($expiresAt) && $expiresAt !== '' && strtotime($expiresAt . ' UTC') < time()) {
            return new WP_Error('companion_auth_required', 'Sessão expirada. Refaça o pareamento.', ['status' => 401]);
        }

        return $device;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractDevicePatch(WP_REST_Request $req): array
    {
        $patch = [];
        foreach (
            [
                'device_name',
                'android_version',
                'companion_version',
                'device_owner_enabled',
                'accessibility_enabled',
                'device_admin_enabled',
                'play_store_enabled',
                'settings_locked',
                'kiosk_mode',
                'device_shutdown_protection',
            ] as $field
        ) {
            $value = $req->get_param($field);
            if ($value === null) {
                continue;
            }
            if (in_array($field, [
                'device_owner_enabled',
                'accessibility_enabled',
                'device_admin_enabled',
                'play_store_enabled',
                'settings_locked',
                'kiosk_mode',
                'device_shutdown_protection',
            ], true)) {
                $patch[$field] = $value ? 1 : 0;
            } else {
                $patch[$field] = (string) $value;
            }
        }
        return $patch;
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    private function deviceToJson(?array $row): array
    {
        if ($row === null) {
            return [
                'paired'                  => false,
                'status'                  => 'unpaired',
                'deviceUuid'              => null,
                'deviceName'              => null,
                'androidVersion'          => null,
                'companionVersion'        => null,
                'deviceOwnerEnabled'      => false,
                'accessibilityEnabled'    => false,
                'deviceAdminEnabled'      => false,
                'playStoreEnabled'        => true,
                'lastSync'                => null,
            ];
        }
        return [
            'paired'                  => true,
            'status'                  => (string) ($row['status'] ?? 'pending'),
            'deviceUuid'              => (string) ($row['device_uuid'] ?? ''),
            'deviceName'              => $row['device_name'] ?? null,
            'androidVersion'          => $row['android_version'] ?? null,
            'companionVersion'        => $row['companion_version'] ?? null,
            'deviceOwnerEnabled'      => (int) ($row['device_owner_enabled'] ?? 0) === 1,
            'accessibilityEnabled'    => (int) ($row['accessibility_enabled'] ?? 0) === 1,
            'deviceAdminEnabled'      => (int) ($row['device_admin_enabled'] ?? 0) === 1,
            'playStoreEnabled'        => (int) ($row['play_store_enabled'] ?? 1) === 1,
            'lastSync'                => $row['last_sync'] ?? null,
        ];
    }
}
