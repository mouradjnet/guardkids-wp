<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\SafeZoneController;
use GuardKids\Tests\Support\AlwaysAllowGate;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * /safe-zones CRUD — parent nonce auth.
 */
final class SafeZoneControllerTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];

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

            public function get_results($sql, $output = OBJECT)
            {
                return array_values($this->rows);
            }

            public function get_row($sql, $output = OBJECT, $y = 0)
            {
                if (preg_match('/WHERE id = (\d+)/', (string) $sql, $m) === 1) {
                    return $this->rows[(int) $m[1]] ?? null;
                }
                return null;
            }

            public function insert($table, $data, $format = null)
            {
                $this->insert_id = count($this->rows) + 1;
                $this->rows[$this->insert_id] = array_merge(['id' => $this->insert_id], $data);
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                $id = (int) $where['id'];
                if (isset($this->rows[$id])) {
                    $this->rows[$id] = array_merge($this->rows[$id], $data);
                }
                return 1;
            }

            public function delete($table, $where, $where_format = null)
            {
                unset($this->rows[(int) $where['id']]);
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    private function zoneRequest(string $method, string $name, float $lat, float $lng, int $radius): WP_REST_Request
    {
        $req = new WP_REST_Request($method, '/safe-zones');
        $req->set_param('name', $name);
        $req->set_param('latitude', $lat);
        $req->set_param('longitude', $lng);
        $req->set_param('radius_meters', $radius);
        return $req;
    }

    public function testCreateInsertsAndReturns201(): void
    {
        $req = $this->zoneRequest('POST', 'Casa', -8.0476, -34.8770, 100);
        $req->set_param('address', 'Rua X, 123');

        $res = (new SafeZoneController(new AlwaysAllowGate()))->create($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertSame(201, $res->get_status());
        $data = $res->get_data();
        self::assertSame('Casa', $data['name']);
        self::assertSame('Rua X, 123', $data['address']);
        self::assertSame(100, $data['radiusMeters']);
    }

    public function testCreateAcceptsNullAddress(): void
    {
        $req = $this->zoneRequest('POST', 'Escola', -8.05, -34.88, 200);
        // sem address
        $res = (new SafeZoneController(new AlwaysAllowGate()))->create($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertNull($res->get_data()['address']);
    }

    public function testIndexReturnsListWithCamelCase(): void
    {
        $this->wpdb->rows = [
            1 => [
                'id' => 1, 'name' => 'Casa', 'address' => 'Rua X',
                'latitude' => '-8.05', 'longitude' => '-34.88', 'radius_meters' => '100',
                'created_at' => '2026-06-07 15:00:00', 'updated_at' => '2026-06-07 15:00:00',
            ],
        ];

        $res = (new SafeZoneController(new AlwaysAllowGate()))->index();
        $data = $res->get_data();

        self::assertCount(1, $data);
        self::assertSame('Casa', $data[0]['name']);
        self::assertSame(100, $data[0]['radiusMeters']);
    }

    public function testUpdateChangesRowAndReturnsUpdated(): void
    {
        $this->wpdb->rows = [
            5 => [
                'id' => 5, 'name' => 'Casa', 'address' => null,
                'latitude' => '-8.05', 'longitude' => '-34.88', 'radius_meters' => '100',
                'created_at' => '2026-06-07', 'updated_at' => '2026-06-07',
            ],
        ];

        $req = $this->zoneRequest('PUT', 'Casa nova', -8.06, -34.89, 150);
        $req->set_param('id', 5);
        $req['id'] = 5;

        $res = (new SafeZoneController(new AlwaysAllowGate()))->update($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertSame('Casa nova', $res->get_data()['name']);
        self::assertSame(150, $res->get_data()['radiusMeters']);
    }

    public function testUpdateReturns404WhenZoneMissing(): void
    {
        $req = $this->zoneRequest('PUT', 'X', 0, 0, 100);
        $req['id'] = 999;

        $res = (new SafeZoneController(new AlwaysAllowGate()))->update($req);

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(404, $res->get_error_data()['status']);
    }

    public function testDestroyRemovesAndReturns204(): void
    {
        $this->wpdb->rows = [
            5 => ['id' => 5, 'name' => 'Casa', 'latitude' => 0, 'longitude' => 0, 'radius_meters' => 100],
        ];

        $req = new WP_REST_Request('DELETE', '/safe-zones/5');
        $req['id'] = 5;

        $res = (new SafeZoneController(new AlwaysAllowGate()))->destroy($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertSame(204, $res->get_status());
        self::assertSame([], $this->wpdb->rows);
    }

    public function testDestroyReturns404WhenZoneMissing(): void
    {
        $req = new WP_REST_Request('DELETE', '/safe-zones/99');
        $req['id'] = 99;

        $res = (new SafeZoneController(new AlwaysAllowGate()))->destroy($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(404, $res->get_error_data()['status']);
    }

    public function testCreateArgsValidatesRadiusBounds(): void
    {
        $args = (new SafeZoneController(new AlwaysAllowGate()))->createArgs();
        self::assertSame(10, $args['radius_meters']['minimum']);
        self::assertSame(5000, $args['radius_meters']['maximum']);
    }
}
