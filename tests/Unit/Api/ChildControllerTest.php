<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\ChildController;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * ChildController — fluxo CRUD admin + emissão de token.
 * Reusa o fake $wpdb pra capturar inserts/updates/deletes.
 */
final class ChildControllerTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];
            /** @var array<int, array{method:string, args:array}> */
            public array $log = [];

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

            public function get_row($sql, $output = OBJECT, $y = 0)
            {
                $this->log[] = ['method' => 'get_row', 'args' => [$sql]];
                if (preg_match('/WHERE id = (\d+)/', (string) $sql, $m) === 1) {
                    return $this->rows[(int) $m[1]] ?? null;
                }
                return null;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $this->log[] = ['method' => 'get_results', 'args' => [$sql]];
                return array_values($this->rows);
            }

            public function insert($table, $data, $format = null)
            {
                $this->log[] = ['method' => 'insert', 'args' => [$table, $data]];
                $this->insert_id = count($this->rows) + 1;
                $this->rows[$this->insert_id] = array_merge(['id' => $this->insert_id], $data);
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                $this->log[] = ['method' => 'update', 'args' => [$table, $data, $where]];
                $id = (int) ($where['id'] ?? 0);
                if (isset($this->rows[$id])) {
                    $this->rows[$id] = array_merge($this->rows[$id], $data);
                }
                return 1;
            }

            public function delete($table, $where, $where_format = null)
            {
                $this->log[] = ['method' => 'delete', 'args' => [$table, $where]];
                $id = (int) ($where['id'] ?? 0);
                if (isset($this->rows[$id])) {
                    unset($this->rows[$id]);
                    return 1;
                }
                return 0;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testIndexReturnsAllChildrenAsJson(): void
    {
        $this->wpdb->rows = [
            1 => ['id' => 1, 'slug' => 'lucas', 'name' => 'Lucas', 'status' => 'online', 'used_minutes' => 30, 'limit_minutes' => 60],
            2 => ['id' => 2, 'slug' => 'paloma', 'name' => 'Paloma', 'status' => 'offline', 'used_minutes' => 0, 'limit_minutes' => 60],
        ];
        $res = (new ChildController())->index();

        self::assertInstanceOf(WP_REST_Response::class, $res);
        $data = $res->get_data();
        self::assertCount(2, $data);
        self::assertSame('Lucas', $data[0]['name']);
        self::assertSame('Paloma', $data[1]['name']);
    }

    public function testShowReturnsChildJsonWhenFound(): void
    {
        $this->wpdb->rows[1] = ['id' => 1, 'slug' => 'lucas', 'name' => 'Lucas', 'status' => 'online', 'used_minutes' => 30, 'limit_minutes' => 60];

        $req = new WP_REST_Request('GET', '/children/1');
        $req['id'] = 1;

        $res = (new ChildController())->show($req);
        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertSame('Lucas', $res->get_data()['name']);
    }

    public function testShowReturns404WhenNotFound(): void
    {
        $req = new WP_REST_Request('GET', '/children/999');
        $req['id'] = 999;

        $res = (new ChildController())->show($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(404, $res->get_error_data()['status']);
        self::assertSame('not_found', $res->get_error_code());
    }

    public function testCreateReturns201WithSlugFromName(): void
    {
        $req = new WP_REST_Request('POST', '/children');
        $req->set_param('name', 'Rafael');

        $res = (new ChildController())->create($req);
        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertSame(201, $res->get_status());
        self::assertSame('rafael', $res->get_data()['slug']);
        self::assertSame('Rafael', $res->get_data()['name']);
    }

    public function testCreatePersistsDailyLimitEnabledFlag(): void
    {
        $req = new WP_REST_Request('POST', '/children');
        $req->set_param('name', 'Rafael');
        $req->set_param('daily_limit_enabled', true);

        $res = (new ChildController())->create($req);
        self::assertInstanceOf(WP_REST_Response::class, $res);

        $insert = array_values(array_filter($this->wpdb->log, fn ($e) => $e['method'] === 'insert'));
        self::assertNotEmpty($insert);
        self::assertSame(1, $insert[0]['args'][1]['daily_limit_enabled']);
    }

    public function testCreateRespectsExplicitSlug(): void
    {
        $req = new WP_REST_Request('POST', '/children');
        $req->set_param('name', 'Rafael');
        $req->set_param('slug', 'rafa');

        $res = (new ChildController())->create($req);
        self::assertSame('rafa', $res->get_data()['slug']);
    }

    public function testCreateReturns422WhenNameEmpty(): void
    {
        $req = new WP_REST_Request('POST', '/children');
        $req->set_param('name', '');

        $res = (new ChildController())->create($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(422, $res->get_error_data()['status']);
        self::assertSame('invalid_payload', $res->get_error_code());
    }

    public function testUpdateMergesProvidedFieldsAndReturnsUpdatedJson(): void
    {
        $this->wpdb->rows[5] = ['id' => 5, 'slug' => 'lucas', 'name' => 'Lucas', 'status' => 'online', 'used_minutes' => 30, 'limit_minutes' => 60];

        $req = new WP_REST_Request('PATCH', '/children/5');
        $req['id'] = 5;
        $req->set_param('limit_minutes', 90);

        $res = (new ChildController())->update($req);
        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertSame(90, $res->get_data()['limitMinutes']);
    }

    public function testUpdateReturns404WhenNotFound(): void
    {
        $req = new WP_REST_Request('PATCH', '/children/777');
        $req['id'] = 777;

        $res = (new ChildController())->update($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(404, $res->get_error_data()['status']);
    }

    public function testDestroyReturnsDeletedTrueJson(): void
    {
        $this->wpdb->rows[5] = ['id' => 5, 'name' => 'Lucas'];

        $req = new WP_REST_Request('DELETE', '/children/5');
        $req['id'] = 5;

        $res = (new ChildController())->destroy($req);
        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertTrue($res->get_data()['deleted']);
        self::assertSame(5, $res->get_data()['id']);
    }

    public function testIssueDeviceTokenReturnsTokenAndCreatedJson(): void
    {
        $this->wpdb->rows[3] = ['id' => 3, 'name' => 'Rafael'];

        $req = new WP_REST_Request('POST', '/children/3/pair');
        $req['id'] = 3;
        $req->set_param('label', 'Tablet do Rafael');

        $res = (new ChildController())->issueDeviceToken($req);
        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertSame(201, $res->get_status());
        $data = $res->get_data();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $data['token']);
        self::assertSame(3, $data['childId']);
        self::assertSame('Tablet do Rafael', $data['label']);
    }

    public function testIssueDeviceTokenReturns404WhenChildMissing(): void
    {
        $req = new WP_REST_Request('POST', '/children/999/pair');
        $req['id'] = 999;

        $res = (new ChildController())->issueDeviceToken($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(404, $res->get_error_data()['status']);
    }
}
