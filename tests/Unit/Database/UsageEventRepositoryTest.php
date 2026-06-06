<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\UsageEventRepository;
use PHPUnit\Framework\TestCase;

final class UsageEventRepositoryTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];
            /** @var array<int, array{method:string, sql:string|null, data:array|null}> */
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

            public function insert($table, $data, $format = null)
            {
                $this->log[] = ['method' => 'insert', 'sql' => null, 'data' => $data];
                $this->insert_id = count($this->rows) + 1;
                $this->rows[$this->insert_id] = array_merge(['id' => $this->insert_id], $data);
                return 1;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $this->log[] = ['method' => 'get_results', 'sql' => (string) $sql, 'data' => null];
                return [];
            }

            public function get_var($sql, $x = 0, $y = 0)
            {
                $this->log[] = ['method' => 'get_var', 'sql' => (string) $sql, 'data' => null];
                return null;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testInsertPersistsRowWithoutUpdatedAt(): void
    {
        $repo = new UsageEventRepository();
        $id = $repo->insert([
            'child_id'         => 1,
            'type'             => 'heartbeat',
            'domain'           => null,
            'duration_seconds' => 60,
        ]);

        self::assertSame(1, $id);
        $data = $this->wpdb->log[0]['data'];
        self::assertSame(1, $data['child_id']);
        self::assertSame('heartbeat', $data['type']);
        self::assertNull($data['domain']);
        self::assertSame(60, $data['duration_seconds']);
        self::assertNotEmpty($data['created_at']);
        self::assertArrayNotHasKey('updated_at', $data);
    }

    public function testAggregateDailyMinutesGroupsByDayFiltersRange(): void
    {
        $repo = new UsageEventRepository();
        $repo->aggregateDailyMinutes(1, '2026-06-01 00:00:00', '2026-06-08 00:00:00');

        $sql = (string) $this->wpdb->log[0]['sql'];
        self::assertStringContainsString('wp_guardkids_usage_events', $sql);
        self::assertStringContainsString('child_id = 1', $sql);
        self::assertStringContainsString("'2026-06-01 00:00:00'", $sql);
        self::assertStringContainsString("'2026-06-08 00:00:00'", $sql);
        self::assertStringContainsString('GROUP BY', $sql);
        self::assertStringContainsString('DATE(created_at)', $sql);
        self::assertStringContainsString('SUM(duration_seconds)', $sql);
    }

    public function testAggregateDailyMinutesWithChildIdZeroAggregatesAll(): void
    {
        $repo = new UsageEventRepository();
        $repo->aggregateDailyMinutes(0, '2026-06-01 00:00:00', '2026-06-08 00:00:00');

        $sql = (string) $this->wpdb->log[0]['sql'];
        self::assertStringNotContainsString('child_id = ', $sql);
        self::assertStringContainsString('GROUP BY', $sql);
    }
}
