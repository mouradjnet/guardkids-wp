<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\ChildSelfController;
use GuardKids\Auth\ChildAuth;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /child/location — auth via X-GuardKids-Token + fail-closed em location_enabled.
 */
final class ChildSelfLocationTest extends TestCase
{
    private \wpdb $wpdb;
    private string $validToken = '';

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<string, string> */
            public array $settings = [];
            /** @var array<int, array<string, mixed>> */
            public array $locations = [];

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

            public function get_results($sql, $output = OBJECT)
            {
                return [];
            }

            public function insert($table, $data, $format = null)
            {
                if (str_contains((string) $table, 'guardkids_settings')) {
                    $this->settings[$data['setting_key']] = (string) $data['value'];
                    return 1;
                }
                if (str_contains((string) $table, 'guardkids_locations')) {
                    $this->insert_id = count($this->locations) + 1;
                    $this->locations[$this->insert_id] = array_merge(['id' => $this->insert_id], $data);
                    return 1;
                }
                return 0;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;

        $issued = (new ChildAuth())->issueToken(7, 'tablet');
        $this->validToken = $issued['token'];
    }

    private function locationRequest(string $token = ''): WP_REST_Request
    {
        $req = new WP_REST_Request('POST', '/child/location');
        $req->set_header('X-GuardKids-Token', $token === '' ? $this->validToken : $token);
        $req->set_param('latitude', -8.0476);
        $req->set_param('longitude', -34.8770);
        return $req;
    }

    private function enableLocation(): void
    {
        $this->wpdb->settings['location_enabled'] = json_encode(true);
    }

    public function testReportLocationSucceedsWhenSettingEnabled(): void
    {
        $this->enableLocation();
        $req = $this->locationRequest();
        $req->set_param('accuracy', 12);
        $req->set_param('battery', 58);

        $res = (new ChildSelfController())->reportLocation($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertSame(201, $res->get_status());
        self::assertNotEmpty($res->get_data()['id']);
        self::assertNotEmpty($res->get_data()['recordedAt']);

        // Confirma childId veio do token (não do body)
        $stored = $this->wpdb->locations[1];
        self::assertSame(7, $stored['child_id']);
        self::assertEqualsWithDelta(-8.0476, $stored['latitude'], 0.0001);
        self::assertEqualsWithDelta(-34.8770, $stored['longitude'], 0.0001);
        self::assertSame(12, $stored['accuracy']);
        self::assertSame(58, $stored['battery']);
    }

    public function testReportLocationReturns403WhenSettingDisabled(): void
    {
        // setting omitido = fail-closed
        $req = $this->locationRequest();
        $res = (new ChildSelfController())->reportLocation($req);

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(403, $res->get_error_data()['status']);
        self::assertSame('location_disabled', $res->get_error_code());
        self::assertSame([], $this->wpdb->locations);
    }

    public function testReportLocationReturns401WithoutToken(): void
    {
        $this->enableLocation();
        $req = new WP_REST_Request('POST', '/child/location');
        $req->set_param('latitude', 0);
        $req->set_param('longitude', 0);

        $res = (new ChildSelfController())->reportLocation($req);

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(401, $res->get_error_data()['status']);
    }

    public function testReportLocationAcceptsNullableAccuracyAndBattery(): void
    {
        $this->enableLocation();
        $req = $this->locationRequest();
        // accuracy e battery omitidos

        $res = (new ChildSelfController())->reportLocation($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertSame(201, $res->get_status());
        $stored = $this->wpdb->locations[1];
        self::assertNull($stored['accuracy']);
        self::assertNull($stored['battery']);
    }

    public function testReportLocationRecordedAtIsServerSetIsoUtc(): void
    {
        $this->enableLocation();
        $req = $this->locationRequest();

        $res = (new ChildSelfController())->reportLocation($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $res->get_data()['recordedAt']
        );
    }
}
