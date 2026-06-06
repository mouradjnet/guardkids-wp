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
            /** @var array<int, array<int, array<string, mixed>>> */
            public array $cannedResults = [];
            /** @var array<int, mixed> */
            public array $cannedVars = [];
            public int $varCallCount = 0;

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
                if ($this->cannedResults !== []) {
                    return array_shift($this->cannedResults) ?? [];
                }
                return [];
            }

            public function get_var($sql, $x = 0, $y = 0)
            {
                $this->log[] = ['method' => 'get_var', 'sql' => (string) $sql, 'data' => null];
                if (isset($this->cannedVars[$this->varCallCount])) {
                    return $this->cannedVars[$this->varCallCount++];
                }
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

    public function testTopDomainsCountsOpensIgnoresHeartbeats(): void
    {
        $repo = new UsageEventRepository();
        $repo->topDomains(0, '2026-06-01 00:00:00', '2026-06-08 00:00:00', 10);

        $sql = (string) $this->wpdb->log[0]['sql'];
        self::assertStringContainsString('wp_guardkids_usage_events', $sql);
        self::assertStringContainsString("type = 'site_open'", $sql);
        self::assertStringContainsString('GROUP BY domain', $sql);
        self::assertStringContainsString('COUNT(*)', $sql);
        self::assertStringContainsString('ORDER BY opens DESC', $sql);
        self::assertStringContainsString('LIMIT 10', $sql);
    }

    public function testTopDomainsRespectsLimitAndChildFilter(): void
    {
        $repo = new UsageEventRepository();
        $repo->topDomains(7, '2026-06-01 00:00:00', '2026-06-08 00:00:00', 3);

        $sql = (string) $this->wpdb->log[0]['sql'];
        self::assertStringContainsString('child_id = 7', $sql);
        self::assertStringContainsString('LIMIT 3', $sql);
    }

    public function testKpisForRangeReturnsTotalAndDeltaShape(): void
    {
        $repo = new UsageEventRepository();
        $out = $repo->kpisForRange(0, '2026-06-01 00:00:00', '2026-06-08 00:00:00');

        self::assertArrayHasKey('total_minutes', $out);
        self::assertArrayHasKey('total_minutes_prev', $out);
        self::assertArrayHasKey('range_days', $out);
        self::assertSame(7, $out['range_days']);
    }

    public function testKpisForRangeComputesPreviousWindow(): void
    {
        $repo = new UsageEventRepository();
        $repo->kpisForRange(1, '2026-06-08 00:00:00', '2026-06-15 00:00:00');

        // Espera 2 queries: atual + anterior
        self::assertCount(2, $this->wpdb->log);
        $sql1 = (string) $this->wpdb->log[0]['sql'];
        $sql2 = (string) $this->wpdb->log[1]['sql'];
        // Janela anterior: 7d antes
        self::assertStringContainsString("'2026-06-01 00:00:00'", $sql2);
        self::assertStringContainsString("'2026-06-08 00:00:00'", $sql2);
        self::assertStringContainsString('child_id = 1', $sql1);
        self::assertStringContainsString('child_id = 1', $sql2);
    }

    public function testAggregateDailyMinutesMapsSecondsToMinutesAndCastsChildId(): void
    {
        $this->wpdb->cannedResults = [
            [
                ['day' => '2026-06-01', 'child_id' => '1', 'total_seconds' => '125'],
            ],
        ];

        $repo = new UsageEventRepository();
        $out  = $repo->aggregateDailyMinutes(1, '2026-06-01 00:00:00', '2026-06-08 00:00:00');

        self::assertSame(
            [
                ['day' => '2026-06-01', 'child_id' => 1, 'minutes' => 2],
            ],
            $out,
        );
    }

    public function testTopDomainsCastsOpensAndTopChildIdAndHandlesNull(): void
    {
        $this->wpdb->cannedResults = [
            [
                ['domain' => 'youtube.com', 'opens' => '14', 'top_child_id' => '1'],
                ['domain' => 'wikipedia.org', 'opens' => '3', 'top_child_id' => null],
            ],
        ];

        $repo = new UsageEventRepository();
        $out  = $repo->topDomains(0, '2026-06-01 00:00:00', '2026-06-08 00:00:00', 10);

        self::assertSame(
            [
                ['domain' => 'youtube.com', 'opens' => 14, 'top_child_id' => 1],
                ['domain' => 'wikipedia.org', 'opens' => 3, 'top_child_id' => null],
            ],
            $out,
        );
    }

    public function testKpisForRangeConvertsSecondsToMinutesAndReadsConsecutiveVars(): void
    {
        // 720 min = 43200s (current), 600 min = 36000s (previous)
        $this->wpdb->cannedVars = [43200, 36000];

        $repo = new UsageEventRepository();
        $out  = $repo->kpisForRange(0, '2026-06-01 00:00:00', '2026-06-08 00:00:00');

        self::assertSame(
            [
                'total_minutes'      => 720,
                'total_minutes_prev' => 600,
                'range_days'         => 7,
            ],
            $out,
        );
        self::assertSame(2, $this->wpdb->varCallCount);
    }
}
