<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\ChildSelfController;
use GuardKids\Auth\ChildAuth;
use GuardKids\Auth\ChildPin;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /child/security/pin/verify — auth via token + gate (toggle + PIN definido).
 */
final class ChildSelfPinTest extends TestCase
{
    private \wpdb $wpdb;
    private string $validToken = '';

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

            public function get_results($sql, $output = OBJECT)
            {
                return [];
            }

            public function insert($table, $data, $format = null)
            {
                $this->settings[$data['setting_key']] = (string) $data['value'];
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                return 1;
            }

            public function delete($table, $where, $where_format = null)
            {
                unset($this->settings[$where['setting_key']]);
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->validToken = (new ChildAuth())->issueToken(7, 'tablet')['token'];
    }

    private function setPin(string $pin): void
    {
        (new ChildPin())->set($pin);
    }

    private function verifyRequest(string $pin, string $token = ''): WP_REST_Request
    {
        $req = new WP_REST_Request('POST', '/child/security/pin/verify');
        $req->set_header('X-GuardKids-Token', $token === '' ? $this->validToken : $token);
        $req->set_param('pin', $pin);
        return $req;
    }

    public function testVerifyReturnsOkTrueForCorrectPin(): void
    {
        $this->setPin('1234');
        $res = (new ChildSelfController())->verifyPin($this->verifyRequest('1234'));

        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertTrue($res->get_data()['ok']);
    }

    public function testVerifyReturnsOkFalseForWrongPin(): void
    {
        $this->setPin('1234');
        $res = (new ChildSelfController())->verifyPin($this->verifyRequest('9999'));

        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertFalse($res->get_data()['ok']);
    }

    public function testVerifyReturns403WhenNoPinSet(): void
    {
        $res = (new ChildSelfController())->verifyPin($this->verifyRequest('1234'));

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(403, $res->get_error_data()['status']);
        self::assertSame('pin_disabled', $res->get_error_code());
    }

    public function testVerifyReturns403WhenToggleExplicitlyOff(): void
    {
        $this->setPin('1234');
        $this->wpdb->settings['security.pin_child'] = json_encode(false);

        $res = (new ChildSelfController())->verifyPin($this->verifyRequest('1234'));

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(403, $res->get_error_data()['status']);
    }

    public function testVerifyReturns401WithoutToken(): void
    {
        $this->setPin('1234');
        $req = new WP_REST_Request('POST', '/child/security/pin/verify');
        $req->set_param('pin', '1234');

        $res = (new ChildSelfController())->verifyPin($req);

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(401, $res->get_error_data()['status']);
    }
}
