<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\SecurityController;
use GuardKids\Auth\ChildPin;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class SecurityControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var array<string, string> */
            public array $store = [];

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
                        return isset($this->store[$m[1]]) ? '1' : null;
                    }
                    return $this->store[$m[1]] ?? null;
                }
                return null;
            }

            public function get_results($sql, $output = OBJECT)
            {
                return [];
            }

            public function insert($table, $data, $format = null)
            {
                $this->store[$data['setting_key']] = (string) $data['value'];
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                return 1;
            }

            public function delete($table, $where, $where_format = null)
            {
                unset($this->store[$where['setting_key']]);
                return 1;
            }
        };
    }

    private function setPinReq(string $pin): WP_REST_Request
    {
        $req = new WP_REST_Request('POST', '/security/pin');
        $req->set_param('pin', $pin);
        return $req;
    }

    public function testStatusReflectsWhetherPinIsSet(): void
    {
        $ctrl = new SecurityController();
        self::assertFalse($ctrl->status()->get_data()['pinSet']);

        $ctrl->setPin($this->setPinReq('1234'));
        self::assertTrue($ctrl->status()->get_data()['pinSet']);
    }

    public function testSetPinPersistsAndReturnsPinSet(): void
    {
        $res = (new SecurityController())->setPin($this->setPinReq('123456'));
        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertTrue($res->get_data()['pinSet']);
        // O PIN guardado é hash, nunca o texto.
        self::assertArrayHasKey('child_pin:secret', $GLOBALS['wpdb']->store);
        self::assertStringNotContainsString('123456', $GLOBALS['wpdb']->store['child_pin:secret']);
    }

    public function testSetPinRejectsInvalidFormatWith422(): void
    {
        $res = (new SecurityController())->setPin($this->setPinReq('12'));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(422, $res->get_error_data()['status']);
        self::assertSame('invalid_pin', $res->get_error_code());
    }

    public function testClearPinRemovesIt(): void
    {
        $ctrl = new SecurityController();
        $ctrl->setPin($this->setPinReq('1234'));
        self::assertTrue($ctrl->status()->get_data()['pinSet']);

        $res = $ctrl->clearPin();
        self::assertFalse($res->get_data()['pinSet']);
        self::assertFalse($ctrl->status()->get_data()['pinSet']);
    }

    public function testStatusNeverLeaksPin(): void
    {
        $ctrl = new SecurityController();
        $ctrl->setPin($this->setPinReq('4321'));
        self::assertSame(['pinSet' => true], $ctrl->status()->get_data());
    }
}
