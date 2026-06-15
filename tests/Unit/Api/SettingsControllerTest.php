<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\SettingsController;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class SettingsControllerTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
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
                $out = [];
                foreach ($this->store as $key => $value) {
                    $out[] = ['setting_key' => $key, 'value' => $value];
                }
                return $out;
            }

            public function insert($table, $data, $format = null)
            {
                $this->store[$data['setting_key']] = (string) $data['value'];
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                $keys = array_keys($this->store);
                if ($keys !== []) {
                    $this->store[$keys[0]] = (string) $data['value'];
                }
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testIndexReturnsAllSettingsDecoded(): void
    {
        $this->wpdb->store = [
            'notifications.push'  => json_encode(true),
            'security.two_fa'     => json_encode(false),
        ];

        $res = (new SettingsController())->index();
        self::assertInstanceOf(WP_REST_Response::class, $res);
        $data = $res->get_data();
        self::assertTrue($data['notifications.push']);
        self::assertFalse($data['security.two_fa']);
    }

    public function testUpdateMergesPatchAndReturnsFullBag(): void
    {
        $req = new WP_REST_Request('PATCH', '/settings');
        $req->set_json_params(['notifications.email' => true, 'security.pin_child' => true]);

        $res = (new SettingsController())->update($req);
        self::assertInstanceOf(WP_REST_Response::class, $res);
        $data = $res->get_data();
        self::assertTrue($data['notifications.email']);
        self::assertTrue($data['security.pin_child']);
    }

    public function testUpdateReturns422OnEmptyPayload(): void
    {
        $req = new WP_REST_Request('PATCH', '/settings');
        $req->set_json_params([]);

        $res = (new SettingsController())->update($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(422, $res->get_error_data()['status']);
    }

    public function testUpdateIgnoresInvalidKeys(): void
    {
        $req = new WP_REST_Request('PATCH', '/settings');
        $req->set_json_params(['valid' => true, '' => 'skipped']);

        $res = (new SettingsController())->update($req);
        self::assertInstanceOf(WP_REST_Response::class, $res);
        $data = $res->get_data();
        self::assertArrayHasKey('valid', $data);
        self::assertArrayNotHasKey('', $data);
    }

    /**
     * Sem esta proteção, admin (ou XSS com nonce comprometido) consegue
     * gravar `child_token:<sha256>` em wp_guardkids_settings e forjar
     * autenticação de criança em /child/me, /child/location, etc.
     */
    public function testUpdateRejectsReservedTokenKeys(): void
    {
        $req = new WP_REST_Request('PATCH', '/settings');
        $req->set_json_params([
            'child_token:abc'           => ['childId' => 999],
            'companion_token:def'       => ['childId' => 999],
            'valid_setting'             => true,
        ]);

        $res = (new SettingsController())->update($req);
        self::assertInstanceOf(WP_REST_Response::class, $res);
        $data = $res->get_data();

        self::assertArrayNotHasKey('child_token:abc', $data);
        self::assertArrayNotHasKey('companion_token:def', $data);
        self::assertArrayHasKey('valid_setting', $data);
        self::assertArrayNotHasKey('child_token:abc', $this->wpdb->store);
        self::assertArrayNotHasKey('companion_token:def', $this->wpdb->store);
    }

    public function testIndexOmitsReservedTokenKeys(): void
    {
        $this->wpdb->store = [
            'child_token:hash1'     => json_encode(['childId' => 5]),
            'companion_token:hash2' => json_encode(['childId' => 7]),
            'location_enabled'      => json_encode(true),
        ];

        $res = (new SettingsController())->index();
        self::assertInstanceOf(WP_REST_Response::class, $res);
        $data = $res->get_data();

        self::assertArrayNotHasKey('child_token:hash1', $data);
        self::assertArrayNotHasKey('companion_token:hash2', $data);
        self::assertArrayHasKey('location_enabled', $data);
    }
}
