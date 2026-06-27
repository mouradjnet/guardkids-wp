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
            public int $insert_id = 0;
            /** @var array<int, array<string, mixed>> requests */
            public array $rows = [];
            /** @var array<int, array<string, mixed>> sites (whitelist/blacklist) */
            public array $sites = [];

            public function __construct()
            {
            }

            public function insert($table, $data, $format = null)
            {
                if (str_contains((string) $table, 'guardkids_sites')) {
                    $this->insert_id = count($this->sites) + 1;
                    $this->sites[$this->insert_id] = $data;
                }
                return 1;
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
                if (str_contains((string) $sql, 'guardkids_sites')) {
                    $domain = null;
                    $list   = null;
                    if (preg_match("/domain = '([^']+)'/", (string) $sql, $m) === 1) {
                        $domain = $m[1];
                    }
                    if (preg_match("/list_type = '([^']+)'/", (string) $sql, $m) === 1) {
                        $list = $m[1];
                    }
                    return array_values(array_filter(
                        $this->sites,
                        static fn ($s) => ($domain === null || ($s['domain'] ?? '') === $domain)
                            && ($list === null || ($s['list_type'] ?? '') === $list),
                    ));
                }
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

    public function testApproveUnblockSiteAddsDomainToWhitelist(): void
    {
        $this->wpdb->rows[10] = [
            'id' => 10, 'child_id' => 1, 'kind' => 'unblock_site',
            'highlight' => 'canva.com', 'status' => 'pending',
        ];

        $req = new WP_REST_Request('POST', '/requests/10/approve');
        $req['id'] = 10;
        (new RequestController())->approve($req);

        $domains = array_map(static fn ($s) => $s['domain'], $this->wpdb->sites);
        self::assertContains('canva.com', $domains);
        $last = $this->wpdb->sites[array_key_last($this->wpdb->sites)];
        self::assertSame('whitelist', $last['list_type']);
    }

    public function testApproveUnblockSiteDoesNotDuplicate(): void
    {
        $this->wpdb->sites = [1 => ['domain' => 'canva.com', 'list_type' => 'whitelist']];
        $this->wpdb->rows[11] = [
            'id' => 11, 'child_id' => 1, 'kind' => 'unblock_site',
            'highlight' => 'canva.com', 'status' => 'pending',
        ];

        $req = new WP_REST_Request('POST', '/requests/11/approve');
        $req['id'] = 11;
        (new RequestController())->approve($req);

        self::assertCount(1, $this->wpdb->sites);
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
