<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\CompanionController;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * CompanionController — modelo de dois tokens (pairing efêmero vs sessão).
 *
 * Foco: garantir que sync/heartbeat autenticam pelo token de SESSÃO (sem
 * expiry), enquanto /enroll troca o pairing token (10min, uso único) por uma
 * sessão. Antes, o pairing token de 10min era reusado como sessão e o device
 * parava de sincronizar 10min após parear.
 */
final class CompanionControllerTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<string, string> setting_key => json value */
            public array $settings = [];
            /** @var array<int, array<string, mixed>> id => device row */
            public array $devices = [];
            /** @var array<int, array<string, mixed>> id => child row */
            public array $children = [];
            /** @var list<string> chaves de settings deletadas */
            public array $deletedKeys = [];

            public function __construct()
            {
            }

            public function prepare($query, ...$args)
            {
                $flat = $args[0] ?? null;
                if (is_array($flat)) {
                    $args = $flat;
                }
                return vsprintf(str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%F'], (string) $query), $args);
            }

            public function get_var($sql, $x = 0, $y = 0)
            {
                $sql = (string) $sql;
                if (preg_match("/setting_key = '([^']+)'/", $sql, $m) === 1) {
                    // SELECT id (do set()) sempre força o caminho de insert — ok
                    // pros testes, que nunca re-escrevem a mesma chave.
                    if (str_contains($sql, 'SELECT id')) {
                        return null;
                    }
                    return $this->settings[$m[1]] ?? null;
                }
                return null;
            }

            public function get_row($sql, $output = ARRAY_A, $y = 0)
            {
                $sql = (string) $sql;
                if (str_contains($sql, 'companion_devices')) {
                    if (preg_match("/session_token_hash = '([^']+)'/", $sql, $m) === 1) {
                        foreach ($this->devices as $row) {
                            if (($row['session_token_hash'] ?? null) === $m[1]) {
                                return $row;
                            }
                        }
                        return null;
                    }
                    if (preg_match("/device_uuid = '([^']+)'/", $sql, $m) === 1) {
                        foreach ($this->devices as $row) {
                            if (($row['device_uuid'] ?? null) === $m[1]) {
                                return $row;
                            }
                        }
                        return null;
                    }
                    if (preg_match('/child_id = (\d+)/', $sql, $m) === 1) {
                        $match = null;
                        foreach ($this->devices as $row) {
                            if ((int) ($row['child_id'] ?? 0) === (int) $m[1]) {
                                $match = $row; // último vence ~ ORDER BY id DESC
                            }
                        }
                        return $match;
                    }
                    return null;
                }
                if (str_contains($sql, 'guardkids_children') && preg_match('/id = (\d+)/', $sql, $m) === 1) {
                    return $this->children[(int) $m[1]] ?? null;
                }
                return null;
            }

            public function insert($table, $data, $format = null)
            {
                if (str_contains((string) $table, 'companion_devices')) {
                    $this->insert_id = count($this->devices) + 1;
                    $this->devices[$this->insert_id] = array_merge(['id' => $this->insert_id], $data);
                    return 1;
                }
                if (str_contains((string) $table, 'settings')) {
                    $this->settings[(string) $data['setting_key']] = (string) $data['value'];
                    return 1;
                }
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                if (str_contains((string) $table, 'companion_devices')) {
                    $id = (int) ($where['id'] ?? 0);
                    if (isset($this->devices[$id])) {
                        $this->devices[$id] = array_merge($this->devices[$id], $data);
                    }
                }
                return 1;
            }

            public function delete($table, $where, $where_format = null)
            {
                if (str_contains((string) $table, 'settings') && isset($where['setting_key'])) {
                    $this->deletedKeys[] = (string) $where['setting_key'];
                    unset($this->settings[(string) $where['setting_key']]);
                }
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function seedDevice(array $overrides = []): array
    {
        $id = count($this->wpdb->devices) + 1;
        $row = array_merge([
            'id'                 => $id,
            'child_id'           => 5,
            'device_uuid'        => 'uuid-' . $id,
            'session_token_hash' => null,
            'status'             => 'pending',
        ], $overrides);
        $this->wpdb->devices[$id] = $row;
        return $row;
    }

    private function request(string $route, string $token = ''): WP_REST_Request
    {
        $req = new WP_REST_Request('POST', $route);
        if ($token !== '') {
            $req->set_header('X-GuardKids-Companion-Token', $token);
        }
        return $req;
    }

    // -------------------- sync / heartbeat: token de sessão --------------------

    public function testSyncAcceptsValidSessionTokenWithoutExpiry(): void
    {
        $token = str_repeat('a', 64);
        $this->seedDevice([
            'device_uuid'        => 'uuid-sess',
            'session_token_hash' => hash('sha256', $token),
            'status'             => 'active',
        ]);

        $res = (new CompanionController())->sync($this->request('/companion/sync', $token));

        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertTrue($res->get_data()['paired']);
    }

    public function testSyncRejectsExpiredSession(): void
    {
        $token = str_repeat('e', 64);
        $this->seedDevice([
            'device_uuid'        => 'uuid-exp',
            'session_token_hash' => hash('sha256', $token),
            'session_expires_at' => gmdate('Y-m-d H:i:s', time() - 60), // expirado
            'status'             => 'active',
        ]);

        $res = (new CompanionController())->sync($this->request('/companion/sync', $token));

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('companion_auth_required', $res->get_error_code());
        self::assertSame(401, $res->get_error_data()['status']);
    }

    public function testSyncRenewsExpiryWindow(): void
    {
        $token = str_repeat('f', 64);
        $device = $this->seedDevice([
            'device_uuid'        => 'uuid-renew',
            'session_token_hash' => hash('sha256', $token),
            'session_expires_at' => gmdate('Y-m-d H:i:s', time() + 86400), // 1 dia
            'status'             => 'active',
        ]);

        (new CompanionController())->sync($this->request('/companion/sync', $token));

        $renewed = $this->wpdb->devices[$device['id']]['session_expires_at'];
        self::assertGreaterThan(time() + 20 * 86400, strtotime($renewed . ' UTC')); // ~30d à frente
    }

    public function testRevokeClearsSessionAndStatus(): void
    {
        $token = str_repeat('1', 64);
        $device = $this->seedDevice([
            'child_id'           => 7,
            'device_uuid'        => 'uuid-rev',
            'session_token_hash' => hash('sha256', $token),
            'session_expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
            'status'             => 'active',
        ]);

        $req = new WP_REST_Request('POST', '/companion/revoke');
        $req->set_param('child_id', 7);
        $res = (new CompanionController())->revoke($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertTrue($res->get_data()['revoked']);
        self::assertNull($this->wpdb->devices[$device['id']]['session_token_hash']);
        self::assertSame('revoked', $this->wpdb->devices[$device['id']]['status']);

        // o token revogado para de autenticar
        $after = (new CompanionController())->sync($this->request('/companion/sync', $token));
        self::assertInstanceOf(WP_Error::class, $after);
    }

    public function testRevokeWithoutPairedDeviceReturns404(): void
    {
        $req = new WP_REST_Request('POST', '/companion/revoke');
        $req->set_param('child_id', 999);
        $res = (new CompanionController())->revoke($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(404, $res->get_error_data()['status']);
    }

    public function testHeartbeatAcceptsValidSessionToken(): void
    {
        $token = str_repeat('b', 64);
        $this->seedDevice([
            'device_uuid'        => 'uuid-hb',
            'session_token_hash' => hash('sha256', $token),
            'status'             => 'active',
        ]);

        $res = (new CompanionController())->heartbeat($this->request('/companion/heartbeat', $token));

        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertTrue($res->get_data()['ok']);
    }

    public function testSyncRejectsUnknownSessionToken(): void
    {
        $res = (new CompanionController())->sync($this->request('/companion/sync', str_repeat('c', 64)));

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('companion_auth_required', $res->get_error_code());
        self::assertSame(401, $res->get_error_data()['status']);
    }

    public function testSyncRejectsMalformedToken(): void
    {
        $res = (new CompanionController())->sync($this->request('/companion/sync', 'short'));

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(401, $res->get_error_data()['status']);
    }

    /**
     * Separação dos papéis: um pairing token válido (só em settings) NÃO
     * autentica sync — só serve pra /enroll.
     */
    public function testSyncRejectsPairingTokenUsedAsSession(): void
    {
        $token = str_repeat('d', 64);
        $this->wpdb->settings['companion_token:' . hash('sha256', $token)] = json_encode([
            'childId'    => 5,
            'deviceUuid' => 'uuid-pair',
            'expiresAt'  => gmdate('c', time() + 600),
        ]);
        $this->seedDevice(['device_uuid' => 'uuid-pair']); // session_token_hash null

        $res = (new CompanionController())->sync($this->request('/companion/sync', $token));

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(401, $res->get_error_data()['status']);
    }

    // -------------------- enroll: troca pairing -> sessão --------------------

    public function testEnrollExchangesPairingTokenForSession(): void
    {
        $pairing = str_repeat('e', 64);
        $pairingKey = 'companion_token:' . hash('sha256', $pairing);
        $this->wpdb->settings[$pairingKey] = json_encode([
            'childId'    => 5,
            'deviceUuid' => 'uuid-enroll',
            'expiresAt'  => gmdate('c', time() + 600),
        ]);
        $this->seedDevice(['device_uuid' => 'uuid-enroll']);

        $res = (new CompanionController())->enroll($this->request('/companion/enroll', $pairing));

        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertSame(201, $res->get_status());

        $session = $res->get_data()['sessionToken'];
        self::assertIsString($session);
        self::assertSame(64, strlen($session));
        self::assertNotSame($pairing, $session, 'sessão deve ser um token novo, não o pairing');

        // Device ficou ativo com o hash da sessão.
        self::assertSame(hash('sha256', $session), $this->wpdb->devices[1]['session_token_hash']);
        self::assertSame('active', $this->wpdb->devices[1]['status']);

        // Pairing token é de uso único — consumido.
        self::assertContains($pairingKey, $this->wpdb->deletedKeys);

        // E a sessão emitida autentica sync (prova ponta-a-ponta do fluxo novo).
        $sync = (new CompanionController())->sync($this->request('/companion/sync', $session));
        self::assertInstanceOf(WP_REST_Response::class, $sync);
    }

    public function testEnrollRejectsExpiredPairingToken(): void
    {
        $pairing = str_repeat('f', 64);
        $this->wpdb->settings['companion_token:' . hash('sha256', $pairing)] = json_encode([
            'childId'    => 5,
            'deviceUuid' => 'uuid-x',
            'expiresAt'  => gmdate('c', time() - 1),
        ]);
        $this->seedDevice(['device_uuid' => 'uuid-x']);

        $res = (new CompanionController())->enroll($this->request('/companion/enroll', $pairing));

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('companion_auth_required', $res->get_error_code());
        self::assertSame(401, $res->get_error_data()['status']);
    }

    public function testEnrollRejectsUnknownPairingToken(): void
    {
        $res = (new CompanionController())->enroll($this->request('/companion/enroll', str_repeat('0', 64)));

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(401, $res->get_error_data()['status']);
    }

    // -------------------- pair: re-parear revoga a sessão --------------------

    public function testRePairClearsExistingSessionToken(): void
    {
        $this->wpdb->children[5] = ['id' => 5, 'name' => 'Lucas'];
        $this->seedDevice([
            'child_id'           => 5,
            'session_token_hash' => hash('sha256', 'sessao-antiga'),
            'status'             => 'active',
        ]);

        $req = new WP_REST_Request('POST', '/companion/pair');
        $req->set_param('child_id', 5);

        $res = (new CompanionController())->pair($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertNull($this->wpdb->devices[1]['session_token_hash'], 'sessão antiga deve ser revogada');
        self::assertSame('pending', $this->wpdb->devices[1]['status']);
    }
}
