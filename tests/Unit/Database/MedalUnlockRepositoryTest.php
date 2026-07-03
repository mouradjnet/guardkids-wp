<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\MedalUnlockRepository;
use PHPUnit\Framework\TestCase;

final class MedalUnlockRepositoryTest extends TestCase
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
                preg_match_all('/guardkids_([a-z_]+)/', $sql, $m);
                return end($m[1]) ?: '';
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

            public function get_results($sql, $output = OBJECT)
            {
                $rows = array_values($this->t['medal_unlocks'] ?? []);
                if (preg_match('/child_id = (\d+)/', (string) $sql, $mm) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['child_id'] ?? 0) === (int) $mm[1]));
                }
                if (preg_match("/medal_key = '([^']+)'/", (string) $sql, $mm) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (string) ($r['medal_key'] ?? '') === $mm[1]));
                }
                return $rows;
            }

            public function get_var($sql, $x = 0, $y = 0)
            {
                $sql = (string) $sql;
                if (str_contains($sql, 'COUNT(*)') && str_contains($sql, 'medal_unlocks')) {
                    preg_match('/child_id = (\d+)/', $sql, $m);
                    $cid = (int) ($m[1] ?? 0);
                    return (string) count(array_filter(
                        $this->t['medal_unlocks'] ?? [],
                        static fn ($r) => (int) ($r['child_id'] ?? 0) === $cid,
                    ));
                }
                if (str_contains($sql, 'COUNT(*)') && str_contains($sql, 'progression_awards')) {
                    preg_match('/child_id = (\d+)/', $sql, $m);
                    $cid = (int) ($m[1] ?? 0);
                    return (string) count(array_filter(
                        $this->t['progression_awards'] ?? [],
                        static fn ($r) => (int) ($r['child_id'] ?? 0) === $cid,
                    ));
                }
                if (str_contains($sql, 'COUNT(*)') && str_contains($sql, 'mission_completions')) {
                    preg_match('/child_id = (\d+)/', $sql, $m);
                    $cid = (int) ($m[1] ?? 0);
                    return (string) count(array_filter(
                        $this->t['mission_completions'] ?? [],
                        static fn ($r) => (int) ($r['child_id'] ?? 0) === $cid,
                    ));
                }
                if (str_contains($sql, 'COUNT(DISTINCT')) {
                    preg_match('/a.child_id = (\d+)/', $sql, $m);
                    $cid = (int) ($m[1] ?? 0);
                    $contentIds = array_map(
                        static fn ($r) => (int) $r['content_id'],
                        array_filter(
                            $this->t['progression_awards'] ?? [],
                            static fn ($r) => (int) ($r['child_id'] ?? 0) === $cid,
                        ),
                    );
                    $cats = [];
                    foreach ($this->t['content_items'] ?? [] as $item) {
                        if (in_array((int) $item['id'], $contentIds, true) && $item['category_id'] !== null) {
                            $cats[(int) $item['category_id']] = true;
                        }
                    }
                    return (string) count($cats);
                }
                return null;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testRecordThenExistsFor(): void
    {
        $repo = new MedalUnlockRepository();
        self::assertFalse($repo->existsFor(1, 'explorer_10'));
        $repo->record(1, 'explorer_10', '2026-07-03', 30, 20);
        self::assertTrue($repo->existsFor(1, 'explorer_10'));
        self::assertFalse($repo->existsFor(1, 'devourer_50'));
    }

    public function testCountUnlocked(): void
    {
        $repo = new MedalUnlockRepository();
        $repo->record(1, 'explorer_10', '2026-07-03', 30, 20);
        $repo->record(1, 'faithful_7', '2026-07-03', 40, 25);
        $repo->record(2, 'explorer_10', '2026-07-03', 30, 20);
        self::assertSame(2, $repo->countUnlocked(1));
        self::assertSame(1, $repo->countUnlocked(2));
    }

    public function testSignalsForComputesAllTime(): void
    {
        $this->wpdb->t['progression_awards'] = [
            1 => ['id' => 1, 'child_id' => 1, 'content_id' => 10, 'award_date' => '2026-07-01'],
            2 => ['id' => 2, 'child_id' => 1, 'content_id' => 11, 'award_date' => '2026-07-03'],
        ];
        $this->wpdb->t['content_items'] = [
            1 => ['id' => 10, 'category_id' => 5],
            2 => ['id' => 11, 'category_id' => 7],
        ];
        $this->wpdb->t['mission_completions'] = [
            1 => ['id' => 1, 'child_id' => 1, 'mission_key' => 'explore_3', 'completion_date' => '2026-07-01'],
        ];

        $signals = (new MedalUnlockRepository())->signalsFor(1);
        self::assertSame(2, $signals['totalContentOpened']);
        self::assertSame(1, $signals['totalMissionsCompleted']);
        self::assertSame(2, $signals['distinctCategoriesAllTime']);
    }
}
