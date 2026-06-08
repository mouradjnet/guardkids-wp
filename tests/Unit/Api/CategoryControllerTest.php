<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\CategoryController;
use GuardKids\Tests\Support\AlwaysAllowGate;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class CategoryControllerTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
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

            public function get_row($sql, $output = OBJECT, $y = 0)
            {
                if (preg_match('/WHERE id = (\d+)/', (string) $sql, $m) === 1) {
                    return $this->rows[(int) $m[1]] ?? null;
                }
                return null;
            }

            public function get_results($sql, $output = OBJECT)
            {
                return array_values($this->rows);
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                $id = (int) ($where['id'] ?? 0);
                if (isset($this->rows[$id])) {
                    $this->rows[$id] = array_merge($this->rows[$id], $data);
                }
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testIndexReturnsAllCategoriesWithBlockedBool(): void
    {
        $this->wpdb->rows = [
            1 => ['id' => 1, 'slug' => 'gambling', 'name' => 'Gambling', 'blocked' => 1],
            2 => ['id' => 2, 'slug' => 'videos', 'name' => 'Videos', 'blocked' => 0],
        ];

        $res = (new CategoryController(new AlwaysAllowGate()))->index();
        self::assertInstanceOf(WP_REST_Response::class, $res);
        $data = $res->get_data();
        self::assertCount(2, $data);
        self::assertTrue($data[0]['blocked']);
        self::assertFalse($data[1]['blocked']);
    }

    public function testUpdateTogglesBlockedFlag(): void
    {
        $this->wpdb->rows[1] = ['id' => 1, 'slug' => 'videos', 'name' => 'Videos', 'blocked' => 0];

        $req = new WP_REST_Request('PATCH', '/categories/1');
        $req['id'] = 1;
        $req->set_param('blocked', true);

        $res = (new CategoryController(new AlwaysAllowGate()))->update($req);
        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertTrue($res->get_data()['blocked']);
    }

    public function testUpdateReturns404WhenMissing(): void
    {
        $req = new WP_REST_Request('PATCH', '/categories/999');
        $req['id'] = 999;
        $req->set_param('blocked', false);

        $res = (new CategoryController(new AlwaysAllowGate()))->update($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(404, $res->get_error_data()['status']);
    }

    public function testUpdateReturns422WhenBlockedMissing(): void
    {
        $this->wpdb->rows[1] = ['id' => 1, 'slug' => 'videos'];

        $req = new WP_REST_Request('PATCH', '/categories/1');
        $req['id'] = 1;

        $res = (new CategoryController(new AlwaysAllowGate()))->update($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(422, $res->get_error_data()['status']);
    }
}
