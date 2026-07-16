<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Maintenance;

use GuardKids\Maintenance\Purger;
use PHPUnit\Framework\TestCase;

/**
 * Purger — descarta usage_events > 90d e locations > 30d.
 */
final class PurgerTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var array<int, string> */
            public array $queries = [];
            public int $rowsDeleted = 7;

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

            public function query($sql)
            {
                $this->queries[] = (string) $sql;
                return $this->rowsDeleted;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testPurgeOldUsageEventsTargetsCorrectTable(): void
    {
        $deleted = (new Purger($this->wpdb))->purgeOldUsageEvents(90);

        self::assertSame(7, $deleted);
        self::assertCount(1, $this->wpdb->queries);
        self::assertStringContainsString('wp_guardkids_usage_events', $this->wpdb->queries[0]);
        self::assertStringContainsString('created_at <', $this->wpdb->queries[0]);
    }

    public function testPurgeOldLocationsTargetsCorrectTable(): void
    {
        $deleted = (new Purger($this->wpdb))->purgeOldLocations(30);

        self::assertSame(7, $deleted);
        self::assertCount(1, $this->wpdb->queries);
        self::assertStringContainsString('wp_guardkids_locations', $this->wpdb->queries[0]);
        self::assertStringContainsString('recorded_at <', $this->wpdb->queries[0]);
    }

    public function testRunInvokesAllPurges(): void
    {
        (new Purger($this->wpdb))->run();

        // 3 desde v1.36.0: o dedupe do push do guardião entrou no cron diário.
        // (purgeExpiredPairingTokens usa get_results+delete, não query.)
        self::assertCount(3, $this->wpdb->queries);
        self::assertStringContainsString('usage_events', $this->wpdb->queries[0]);
        self::assertStringContainsString('locations', $this->wpdb->queries[1]);
        self::assertStringContainsString('guardian_push_dedup', $this->wpdb->queries[2]);
    }

    public function testPurgeOldGuardianDedupTargetsCorrectTable(): void
    {
        $deleted = (new Purger($this->wpdb))->purgeOldGuardianDedup(30);

        self::assertSame(7, $deleted);
        self::assertCount(1, $this->wpdb->queries);
        self::assertStringContainsString('wp_guardkids_guardian_push_dedup', $this->wpdb->queries[0]);
        self::assertStringContainsString('created_at <', $this->wpdb->queries[0]);
    }

    public function testCutoffUsesUtcTimestamp(): void
    {
        (new Purger($this->wpdb))->purgeOldUsageEvents(90);

        // Cutoff ~= now - 90d. Aceita janela de 1 dia pra evitar flakiness
        // por timing entre o cálculo no Purger e este assert.
        $sql = $this->wpdb->queries[0];
        $expectedMin = gmdate('Y-m-d', time() - 91 * 86400);
        $expectedMax = gmdate('Y-m-d', time() - 89 * 86400);
        self::assertMatchesRegularExpression(
            sprintf("/'(%s|%s|%s)/", $expectedMin, gmdate('Y-m-d', time() - 90 * 86400), $expectedMax),
            $sql,
        );
    }

    public function testReturnsZeroWhenWpdbFails(): void
    {
        $this->wpdb->rowsDeleted = 0;
        $purger = new Purger($this->wpdb);

        self::assertSame(0, $purger->purgeOldUsageEvents(90));
    }

    public function testPurgeOldDecidedRequestsTargetsRequestsAndPreservesPending(): void
    {
        $deleted = (new Purger($this->wpdb))->purgeOldDecidedRequests(90);

        self::assertSame(7, $deleted);
        self::assertCount(1, $this->wpdb->queries);
        self::assertStringContainsString('wp_guardkids_requests', $this->wpdb->queries[0]);
        self::assertStringContainsString('decided_at IS NOT NULL', $this->wpdb->queries[0]);
        self::assertStringContainsString('decided_at <', $this->wpdb->queries[0]);
    }

    public function testRunDoesNotTouchRequests(): void
    {
        (new Purger($this->wpdb))->run();

        foreach ($this->wpdb->queries as $sql) {
            self::assertStringNotContainsString('guardkids_requests', $sql);
        }
    }

    public function testPurgeExpiredPairingTokens(): void
    {
        $wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var list<array<string, string>> */
            public array $rows = [];
            /** @var list<string> */
            public array $deleted = [];
            public function __construct()
            {
            }
            public function get_results($sql, $output = ARRAY_A)
            {
                return $this->rows;
            }
            public function delete($table, $where, $where_format = null)
            {
                $this->deleted[] = (string) $where['setting_key'];
                return 1;
            }
        };
        $wpdb->rows = [
            ['setting_key' => 'companion_token:aaa', 'value' => (string) json_encode(['expiresAt' => gmdate('c', time() - 60)])],
            ['setting_key' => 'companion_token:bbb', 'value' => (string) json_encode(['expiresAt' => gmdate('c', time() + 600)])],
        ];

        $removed = (new Purger($wpdb))->purgeExpiredPairingTokens();

        self::assertSame(1, $removed);
        self::assertSame(['companion_token:aaa'], $wpdb->deleted);
    }
}
