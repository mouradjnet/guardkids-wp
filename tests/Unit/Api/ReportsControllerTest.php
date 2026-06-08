<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\ReportsController;
use GuardKids\Tests\Support\AlwaysAllowGate;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ReportsControllerTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var array<int, array<string, mixed>> */
            public array $children = [];
            /** @var array<int, array<string, mixed>> */
            public array $events = [];

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
                if (str_contains((string) $sql, 'COALESCE(SUM(duration_seconds)')) {
                    return '0';
                }
                return null;
            }

            public function get_results($sql, $output = OBJECT)
            {
                if (str_contains((string) $sql, 'guardkids_children')) {
                    return array_values($this->children);
                }
                return [];
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testIndexReturnsExpectedShapeWithWeekDefault(): void
    {
        $req = new WP_REST_Request('GET', '/reports');
        $res = (new ReportsController(new AlwaysAllowGate()))->index($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        $data = $res->get_data();
        self::assertSame('week', $data['range']);
        self::assertArrayHasKey('from', $data);
        self::assertArrayHasKey('to', $data);
        self::assertArrayHasKey('kpis', $data);
        self::assertArrayHasKey('dailyByChild', $data);
        self::assertArrayHasKey('topSites', $data);
        self::assertArrayHasKey('perChild', $data);
    }

    public function testIndexAcceptsMonthRange(): void
    {
        $req = new WP_REST_Request('GET', '/reports');
        $req->set_param('range', 'month');
        $res = (new ReportsController(new AlwaysAllowGate()))->index($req);
        self::assertSame('month', $res->get_data()['range']);
    }

    public function testIndexRejectsUnknownRange(): void
    {
        $req = new WP_REST_Request('GET', '/reports');
        $req->set_param('range', 'forever');
        $res = (new ReportsController(new AlwaysAllowGate()))->index($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(422, $res->get_error_data()['status']);
    }

    public function testIndexEmptyArraysWhenNoData(): void
    {
        $req = new WP_REST_Request('GET', '/reports');
        $res = (new ReportsController(new AlwaysAllowGate()))->index($req);
        $data = $res->get_data();
        self::assertSame([], $data['dailyByChild']);
        self::assertSame([], $data['topSites']);
        self::assertSame(0, $data['kpis']['totalMinutes']);
        self::assertNull($data['kpis']['deltaPctVsPrevious']);
    }

    public function testIndexComputesKpisFromRepository(): void
    {
        $this->wpdb->children = [
            1 => ['id' => 1, 'slug' => 'lucas', 'name' => 'Lucas', 'limit_minutes' => 60],
        ];
        $req = new WP_REST_Request('GET', '/reports');
        $req->set_param('child_id', 1);

        $res = (new ReportsController(new AlwaysAllowGate()))->index($req);
        $data = $res->get_data();
        self::assertCount(1, $data['perChild']);
        self::assertSame(1, $data['perChild'][0]['childId']);
        self::assertSame('Lucas', $data['perChild'][0]['name']);
    }
}
