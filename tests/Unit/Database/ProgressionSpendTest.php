<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\ProgressionRepository;
use PHPUnit\Framework\TestCase;

final class ProgressionSpendTest extends TestCase
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

            // Emula o UPDATE condicional atômico: só deduz se coins >= X.
            public function query($sql)
            {
                $sql = (string) $sql;
                if (! str_contains($sql, 'UPDATE') || ! str_contains($sql, 'guardkids_progression')) {
                    return 0;
                }
                preg_match('/coins = coins - (\d+)/', $sql, $mc);
                preg_match('/child_id = (\d+)/', $sql, $mChild);
                preg_match('/coins >= (\d+)/', $sql, $mMin);
                $amount = (int) ($mc[1] ?? 0);
                $childId = (int) ($mChild[1] ?? 0);
                $min = (int) ($mMin[1] ?? 0);
                foreach ($this->rows as &$r) {
                    if ((int) $r['child_id'] === $childId && (int) $r['coins'] >= $min) {
                        $r['coins'] = (int) $r['coins'] - $amount;
                        return 1;
                    }
                }
                return 0;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    private function seed(int $childId, int $coins): void
    {
        $this->wpdb->rows[] = ['child_id' => $childId, 'coins' => $coins];
    }

    private function coinsOf(int $childId): int
    {
        foreach ($this->wpdb->rows as $r) {
            if ((int) $r['child_id'] === $childId) {
                return (int) $r['coins'];
            }
        }
        return -1;
    }

    public function testSpendDeductsWhenEnough(): void
    {
        $this->seed(1, 100);
        self::assertTrue((new ProgressionRepository())->spend(1, 30));
        self::assertSame(70, $this->coinsOf(1));
    }

    public function testSpendExactBalanceReachesZero(): void
    {
        $this->seed(1, 50);
        self::assertTrue((new ProgressionRepository())->spend(1, 50));
        self::assertSame(0, $this->coinsOf(1));
    }

    public function testSpendFailsWhenInsufficient(): void
    {
        $this->seed(1, 20);
        self::assertFalse((new ProgressionRepository())->spend(1, 30));
        self::assertSame(20, $this->coinsOf(1)); // inalterado
    }

    public function testSpendFailsWithoutWallet(): void
    {
        self::assertFalse((new ProgressionRepository())->spend(99, 10));
    }
}
