<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\CompanionController;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * CompanionController — endpoints autenticados via X-GuardKids-Companion-Token.
 *
 * Foco do teste: garantir que `authenticate()` valida `expiresAt`. Sem essa
 * checagem, o token de pareamento de 10min vira eterno e o pareamento vazado
 * (QR fotografado, log) permite ataque indefinido.
 */
final class CompanionControllerTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var array<string, string> */
            public array $settings = [];

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
                if (preg_match("/setting_key = '([^']+)'/", (string) $sql, $m) === 1) {
                    if (str_contains((string) $sql, 'SELECT id')) {
                        return isset($this->settings[$m[1]]) ? '1' : null;
                    }
                    return $this->settings[$m[1]] ?? null;
                }
                return null;
            }

            public function get_row($sql, $output = OBJECT, $y = 0)
            {
                return null;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testSyncRejectsExpiredToken(): void
    {
        $token = str_repeat('a', 64);
        $hash  = hash('sha256', $token);
        $this->wpdb->settings['companion_token:' . $hash] = json_encode([
            'childId'    => 5,
            'deviceUuid' => 'uuid-123',
            'createdAt'  => gmdate('c', time() - 1200),
            'expiresAt'  => gmdate('c', time() - 600), // expirou 10min atrás
        ]);

        $req = new WP_REST_Request('POST', '/companion/sync');
        $req->set_header('X-GuardKids-Companion-Token', $token);

        $res = (new CompanionController())->sync($req);

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('companion_auth_required', $res->get_error_code());
        self::assertSame(401, $res->get_error_data()['status']);
    }

    public function testSyncRejectsTokenWithoutExpiresAt(): void
    {
        $token = str_repeat('b', 64);
        $hash  = hash('sha256', $token);
        $this->wpdb->settings['companion_token:' . $hash] = json_encode([
            'childId'    => 5,
            'deviceUuid' => 'uuid-123',
            // expiresAt ausente — registro corrompido ou forjado.
        ]);

        $req = new WP_REST_Request('POST', '/companion/sync');
        $req->set_header('X-GuardKids-Companion-Token', $token);

        $res = (new CompanionController())->sync($req);

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('companion_auth_required', $res->get_error_code());
        self::assertSame(401, $res->get_error_data()['status']);
    }

    public function testHeartbeatRejectsExpiredToken(): void
    {
        $token = str_repeat('c', 64);
        $hash  = hash('sha256', $token);
        $this->wpdb->settings['companion_token:' . $hash] = json_encode([
            'childId'    => 5,
            'deviceUuid' => 'uuid-456',
            'expiresAt'  => gmdate('c', time() - 1),
        ]);

        $req = new WP_REST_Request('POST', '/companion/heartbeat');
        $req->set_header('X-GuardKids-Companion-Token', $token);

        $res = (new CompanionController())->heartbeat($req);

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('companion_auth_required', $res->get_error_code());
        self::assertSame(401, $res->get_error_data()['status']);
    }
}
