<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Progression;

use GuardKids\Progression\Progression;
use PHPUnit\Framework\TestCase;

final class ProgressionTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<string, array<int, array<string, mixed>>> */
            public array $t = [];

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

            private function nameOf(string $sql): string
            {
                preg_match('/guardkids_(progression\w*)/', $sql, $m);
                return $m[1] ?? '';
            }

            public function insert($table, $data, $format = null)
            {
                $n = $this->nameOf((string) $table);
                $this->t[$n] ??= [];
                $id = count($this->t[$n]) + 1;
                $this->insert_id = $id;
                $this->t[$n][$id] = array_merge(['id' => $id], $data);
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                $n = $this->nameOf((string) $table);
                $id = (int) ($where['id'] ?? 0);
                if (isset($this->t[$n][$id])) {
                    $this->t[$n][$id] = array_merge($this->t[$n][$id], $data);
                }
                return 1;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $rows = array_values($this->t[$this->nameOf((string) $sql)] ?? []);
                foreach (['child_id', 'content_id'] as $col) {
                    if (preg_match("/{$col} = (\d+)/", (string) $sql, $m) === 1) {
                        $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r[$col] ?? 0) === (int) $m[1]));
                    }
                }
                if (preg_match("/award_date = '([^']+)'/", (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (string) ($r['award_date'] ?? '') === $m[1]));
                }
                return $rows;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    /** @return array<string, mixed> */
    private function wallet(int $childId): array
    {
        foreach ($this->wpdb->t['progression'] ?? [] as $r) {
            if ((int) $r['child_id'] === $childId) {
                return $r;
            }
        }
        return [];
    }

    public function testFirstOpenCreditsWithDailyBonus(): void
    {
        (new Progression())->awardForOpen(1, 10, new \DateTimeImmutable('2026-07-02 10:00:00'));
        $w = $this->wallet(1);
        self::assertSame(10, (int) $w['xp']);
        self::assertSame(10, (int) $w['coins']); // 5 base + 5 bônus do dia
        self::assertSame(1, (int) $w['streak_days']);
    }

    public function testSameContentSameDayIsNoOp(): void
    {
        $p = new Progression();
        $now = new \DateTimeImmutable('2026-07-02 10:00:00');
        $p->awardForOpen(1, 10, $now);
        $p->awardForOpen(1, 10, $now);
        self::assertSame(10, (int) $this->wallet(1)['xp']);
    }

    public function testDifferentContentSameDayCreditsWithoutSecondBonus(): void
    {
        $p = new Progression();
        $now = new \DateTimeImmutable('2026-07-02 10:00:00');
        $p->awardForOpen(1, 10, $now);
        $p->awardForOpen(1, 11, $now);
        $w = $this->wallet(1);
        self::assertSame(20, (int) $w['xp']);    // 10 + 10
        self::assertSame(15, (int) $w['coins']); // (5+5) + 5, sem 2º bônus
    }

    public function testStreakIncrementsOnConsecutiveDayAndResetsOnGap(): void
    {
        $p = new Progression();
        $p->awardForOpen(1, 10, new \DateTimeImmutable('2026-07-02 10:00:00'));
        $p->awardForOpen(1, 11, new \DateTimeImmutable('2026-07-03 10:00:00'));
        self::assertSame(2, (int) $this->wallet(1)['streak_days']);
        $p->awardForOpen(1, 12, new \DateTimeImmutable('2026-07-06 10:00:00'));
        self::assertSame(1, (int) $this->wallet(1)['streak_days']);
    }
}
