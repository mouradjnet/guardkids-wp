<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Notifications;

use GuardKids\Notifications\DigestData;
use PHPUnit\Framework\TestCase;

final class DigestDataTest extends TestCase
{
    private function wpdbReturning(callable $results, callable $vars): \wpdb
    {
        return new class ($results, $vars) extends \wpdb {
            public string $prefix = 'wp_';

            /** @param callable $results @param callable $vars */
            public function __construct(private $results, private $vars)
            {
            }

            public function prepare($query, ...$args)
            {
                return (string) $query;
            }

            public function get_results($query = null, $output = ARRAY_A)
            {
                return ($this->results)((string) $query);
            }

            public function get_var($query = null, $x = 0, $y = 0)
            {
                return ($this->vars)((string) $query);
            }
        };
    }

    public function testBuildDailyShapesChildrenPendingAndBlocks(): void
    {
        $wpdb = $this->wpdbReturning(
            static fn (string $q) => str_contains($q, 'guardkids_children')
                ? [['name' => 'Lucas', 'used_minutes' => 30, 'limit_minutes' => 60]]
                : [],
            static fn (string $q) => str_contains($q, 'schedule_block') ? 2 : 5,
        );
        $GLOBALS['wpdb'] = $wpdb;

        $out = (new DigestData($wpdb))->buildDaily();

        self::assertSame('Lucas', $out['children'][0]['name']);
        self::assertSame(30, $out['children'][0]['usedMinutes']);
        self::assertSame(60, $out['children'][0]['limitMinutes']);
        self::assertSame(5, $out['pendingRequests']);
        self::assertSame(2, $out['blocksToday']);
    }

    public function testBuildWeeklyShapesMinutesBlocksAndDecisions(): void
    {
        $wpdb = $this->wpdbReturning(
            static fn (string $q) => [['name' => 'Lucas', 'secs' => 7200]],
            static function (string $q): int {
                if (str_contains($q, 'schedule_block')) {
                    return 4;
                }
                if (str_contains($q, "status = 'approved'")) {
                    return 3;
                }
                if (str_contains($q, "status = 'denied'")) {
                    return 1;
                }
                return 0;
            },
        );
        $GLOBALS['wpdb'] = $wpdb;

        $out = (new DigestData($wpdb))->buildWeekly();

        self::assertSame('Lucas', $out['children'][0]['name']);
        self::assertSame(120, $out['children'][0]['weekMinutes']);
        self::assertSame(4, $out['blocksWeek']);
        self::assertSame(3, $out['requestsApproved']);
        self::assertSame(1, $out['requestsDenied']);
    }
}
