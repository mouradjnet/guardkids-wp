<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\LocationController;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /locations?child_id=&limit= — leitura pelo parent.
 */
final class LocationControllerTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];
            public ?string $lastSql = null;

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
                $this->lastSql = (string) $sql;
                return $this->rows;
            }

            public function get_row($sql, $output = OBJECT, $y = 0)
            {
                return $this->rows[0] ?? null;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    private function request(int $childId, ?int $limit = null): WP_REST_Request
    {
        $req = new WP_REST_Request('GET', '/locations');
        $req->set_param('child_id', $childId);
        if ($limit !== null) {
            $req->set_param('limit', $limit);
        }
        return $req;
    }

    public function testIndexFiltersByChildIdAndReturnsCamelCase(): void
    {
        $this->wpdb->rows = [
            [
                'id' => 42, 'child_id' => 7,
                'latitude' => '-8.0476', 'longitude' => '-34.8770',
                'accuracy' => '12', 'battery' => '58',
                'recorded_at' => '2026-06-07 15:32:00',
            ],
        ];

        $res = (new LocationController())->index($this->request(7));

        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertSame(200, $res->get_status());
        $rows = $res->get_data();
        self::assertCount(1, $rows);
        self::assertSame(42, $rows[0]['id']);
        self::assertSame(7, $rows[0]['childId']);
        self::assertEqualsWithDelta(-8.0476, $rows[0]['latitude'], 0.0001);
        self::assertSame(12, $rows[0]['accuracy']);
        self::assertSame(58, $rows[0]['battery']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $rows[0]['recordedAt']
        );
        self::assertStringContainsString('child_id = 7', (string) $this->wpdb->lastSql);
    }

    public function testIndexRespectsLimitParameter(): void
    {
        (new LocationController())->index($this->request(7, 50));
        self::assertStringContainsString('LIMIT 50', (string) $this->wpdb->lastSql);
    }

    public function testIndexReturnsEmptyArrayWhenChildHasNoLocations(): void
    {
        $this->wpdb->rows = [];
        $res = (new LocationController())->index($this->request(99));

        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertSame([], $res->get_data());
    }

    public function testIndexReturnsEmptyWhenChildIdMissing(): void
    {
        $res = (new LocationController())->index($this->request(0));
        self::assertSame([], $res->get_data());
    }
}
