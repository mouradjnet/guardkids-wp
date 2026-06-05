<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\RequestController;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class RequestControllerTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $GLOBALS['gk_current_user_id'] = 7;
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

    public function testIndexFiltersByStatus(): void
    {
        $this->wpdb->rows = [
            1 => ['id' => 1, 'child_id' => 1, 'kind' => 'extra_time', 'status' => 'pending'],
        ];
        $req = new WP_REST_Request('GET', '/requests');
        $req->set_param('status', 'pending');

        $res = (new RequestController())->index($req);
        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertCount(1, $res->get_data());
        self::assertSame('pending', $res->get_data()[0]['status']);
    }

    public function testApproveSetsStatusAndDecidedBy(): void
    {
        $this->wpdb->rows[5] = ['id' => 5, 'child_id' => 1, 'kind' => 'extra_time', 'status' => 'pending'];

        $req = new WP_REST_Request('POST', '/requests/5/approve');
        $req['id'] = 5;

        $res = (new RequestController())->approve($req);
        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertSame('approved', $res->get_data()['status']);
        self::assertSame(7, $res->get_data()['decidedBy']);
    }

    public function testDenyMarksAsDenied(): void
    {
        $this->wpdb->rows[6] = ['id' => 6, 'child_id' => 1, 'kind' => 'extra_time', 'status' => 'pending'];

        $req = new WP_REST_Request('POST', '/requests/6/deny');
        $req['id'] = 6;

        $res = (new RequestController())->deny($req);
        self::assertSame('denied', $res->get_data()['status']);
    }

    public function testApproveReturns409WhenAlreadyDecided(): void
    {
        $this->wpdb->rows[5] = ['id' => 5, 'status' => 'approved'];

        $req = new WP_REST_Request('POST', '/requests/5/approve');
        $req['id'] = 5;

        $res = (new RequestController())->approve($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(409, $res->get_error_data()['status']);
        self::assertSame('already_decided', $res->get_error_code());
    }

    public function testApproveReturns404WhenRequestMissing(): void
    {
        $req = new WP_REST_Request('POST', '/requests/999/approve');
        $req['id'] = 999;

        $res = (new RequestController())->approve($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(404, $res->get_error_data()['status']);
    }
}
