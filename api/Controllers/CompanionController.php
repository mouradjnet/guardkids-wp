<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Database\ChildRepository;
use GuardKids\Database\CompanionDeviceRepository;
use GuardKids\Database\SettingsRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Companion REST endpoints (v1.5.0).
 *
 * Reservado para o GuardKids Companion Android (ainda não implementado).
 * Esta versão prepara o backend — schema, auth via token e fluxo de pairing
 * — sem implementar o app Android. O wizard de pareamento no painel
 * gera um token temporário (hash em settings) e o Companion futuro vai
 * apresentá-lo no header X-GuardKids-Companion-Token nos endpoints
 * heartbeat/sync.
 *
 * Endpoints:
 *   - GET  /companion/status?child_id=N    (admin)
 *   - POST /companion/pair                 (admin)
 *   - POST /companion/sync                 (companion token)
 *   - POST /companion/heartbeat            (companion token)
 *
 * Modo de proteção (family|maximum):
 *   - GET  /protection-mode                (admin)
 *   - POST /protection-mode                (admin)
 */
final class CompanionController
{
    private const HEADER_TOKEN  = 'X-GuardKids-Companion-Token';
    private const TOKEN_PREFIX  = 'companion_token:';
    private const TOKEN_BYTES   = 32;
    private const TOKEN_LENGTH  = 64; // hex
    private const SETTINGS_KEY  = 'protection_mode';

    private const VALID_MODES = ['family', 'maximum'];

    private readonly CompanionDeviceRepository $devices;
    private readonly ChildRepository $children;
    private readonly SettingsRepository $settings;

    public function __construct()
    {
        $this->devices  = new CompanionDeviceRepository();
        $this->children = new ChildRepository();
        $this->settings = new SettingsRepository();
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
            $this->devices->update((int) $existing['id'], ['status' => 'pending']);
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

    // -------------------- companion/sync --------------------

    public function sync(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $device = $this->authenticate($req);
        if ($device instanceof WP_Error) {
            return $device;
        }

        $patch = $this->extractDevicePatch($req);
        $patch['status'] = 'active';
        $this->devices->touchSync((int) $device['id'], $patch);

        $fresh = $this->devices->findByUuid((string) $device['device_uuid']);
        return rest_ensure_response($this->deviceToJson($fresh));
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
        $device = $this->authenticate($req);
        if ($device instanceof WP_Error) {
            return $device;
        }
        $this->devices->touchSync((int) $device['id'], ['status' => 'active']);
        return rest_ensure_response(['ok' => true, 'lastSync' => current_time('mysql', true)]);
    }

    // -------------------- helpers --------------------

    /**
     * @return array<string, mixed>|WP_Error
     */
    private function authenticate(WP_REST_Request $req): array|WP_Error
    {
        $raw = (string) $req->get_header(self::HEADER_TOKEN);
        if ($raw === '' || strlen($raw) !== self::TOKEN_LENGTH || preg_match('/^[a-f0-9]+$/i', $raw) !== 1) {
            return new WP_Error('companion_auth_required', 'Token do Companion inválido ou ausente.', ['status' => 401]);
        }

        $hash = hash('sha256', strtolower($raw));
        $data = $this->settings->get(self::TOKEN_PREFIX . $hash);
        if (! is_array($data) || ! isset($data['deviceUuid'])) {
            return new WP_Error('companion_auth_required', 'Token desconhecido.', ['status' => 401]);
        }

        $device = $this->devices->findByUuid((string) $data['deviceUuid']);
        if ($device === null) {
            return new WP_Error('companion_auth_required', 'Dispositivo não encontrado.', ['status' => 401]);
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
