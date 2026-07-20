<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\ChildSelfController;
use GuardKids\Auth\ChildAuth;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Fail-open do geofencing em POST /child/location: se o PlaceTracker explodir
 * (ex.: query de safe_zones falha), o fix JÁ salvo não pode ser derrubado — o
 * 201 tem que sair normalmente. O try/catch em reportLocation garante isso.
 */
final class ChildSelfLocationGeofenceTest extends TestCase
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
                // O PlaceTracker lê os Locais via SELECT * FROM ...safe_zones...
                // Simula uma falha de DB no geofencing pra provar o fail-open.
                if (str_contains((string) $sql, 'safe_zones')) {
                    throw new \RuntimeException('boom: safe_zones indisponível');
                }
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

    private function enableLocation(): void
    {
        $this->wpdb->settings['location_enabled'] = json_encode(true);
    }

    private function locationRequest(): WP_REST_Request
    {
        $req = new WP_REST_Request('POST', '/child/location');
        $req->set_header('X-GuardKids-Token', $this->validToken);
        $req->set_param('latitude', -8.0476);
        $req->set_param('longitude', -34.8770);
        $req->set_param('accuracy', 12);
        return $req;
    }

    public function testGeofenceErrorDoesNotBreakFixInsert(): void
    {
        $this->enableLocation();

        $response = (new ChildSelfController())->reportLocation($this->locationRequest());

        // O geofencing explodiu (safe_zones lança), mas o fix salvo manda: 201.
        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame(201, $response->get_status());
        self::assertNotEmpty($response->get_data()['id']);

        // E o fix foi realmente persistido, com o childId vindo do token.
        $stored = $this->wpdb->locations[1];
        self::assertSame(7, $stored['child_id']);
        self::assertEqualsWithDelta(-8.0476, $stored['latitude'], 0.0001);
        self::assertEqualsWithDelta(-34.8770, $stored['longitude'], 0.0001);
    }
}
