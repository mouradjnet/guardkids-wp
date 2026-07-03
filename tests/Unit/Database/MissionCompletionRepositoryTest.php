<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\MissionCompletionRepository;
use PHPUnit\Framework\TestCase;

final class MissionCompletionRepositoryTest extends TestCase
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
                // pega a última tabela guardkids_ citada (cobre JOINs)
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
                $rows = array_values($this->t['mission_completions'] ?? []);
                foreach (['child_id'] as $col) {
                    if (preg_match("/{$col} = (\d+)/", (string) $sql, $mm) === 1) {
                        $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r[$col] ?? 0) === (int) $mm[1]));
                    }
                }
                if (preg_match("/mission_key = '([^']+)'/", (string) $sql, $mm) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (string) ($r['mission_key'] ?? '') === $mm[1]));
                }
                if (preg_match("/completion_date = '([^']+)'/", (string) $sql, $mm) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (string) ($r['completion_date'] ?? '') === $mm[1]));
                }
                return $rows;
            }

            public function get_var($sql, $x = 0, $y = 0)
            {
                $sql = (string) $sql;
                // COUNT(*) de mission_completions por filho
                if (str_contains($sql, 'COUNT(*)') && str_contains($sql, 'mission_completions')) {
                    preg_match('/child_id = (\d+)/', $sql, $m);
                    $cid = (int) ($m[1] ?? 0);
                    return (string) count(array_filter(
                        $this->t['mission_completions'] ?? [],
                        static fn ($r) => (int) ($r['child_id'] ?? 0) === $cid,
                    ));
                }
                // COUNT(*) de conteúdos abertos hoje
                if (str_contains($sql, 'COUNT(*)') && str_contains($sql, 'progression_awards')) {
                    preg_match('/child_id = (\d+)/', $sql, $mc);
                    preg_match("/award_date = '([^']+)'/", $sql, $md);
                    $cid = (int) ($mc[1] ?? 0);
                    $date = $md[1] ?? '';
                    return (string) count(array_filter(
                        $this->t['progression_awards'] ?? [],
                        static fn ($r) => (int) ($r['child_id'] ?? 0) === $cid && (string) ($r['award_date'] ?? '') === $date,
                    ));
                }
                // COUNT(DISTINCT category) via JOIN awards x content_items
                if (str_contains($sql, 'COUNT(DISTINCT')) {
                    preg_match('/a.child_id = (\d+)/', $sql, $mc);
                    preg_match("/a.award_date = '([^']+)'/", $sql, $md);
                    $cid = (int) ($mc[1] ?? 0);
                    $date = $md[1] ?? '';
                    $contentIds = array_map(
                        static fn ($r) => (int) $r['content_id'],
                        array_filter(
                            $this->t['progression_awards'] ?? [],
                            static fn ($r) => (int) ($r['child_id'] ?? 0) === $cid && (string) ($r['award_date'] ?? '') === $date,
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
                // last_activity_date da carteira
                if (str_contains($sql, 'last_activity_date')) {
                    preg_match('/child_id = (\d+)/', $sql, $m);
                    $cid = (int) ($m[1] ?? 0);
                    foreach ($this->t['progression'] ?? [] as $r) {
                        if ((int) ($r['child_id'] ?? 0) === $cid) {
                            return $r['last_activity_date'] ?? null;
                        }
                    }
                    return null;
                }
                return null;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testRecordThenExistsFor(): void
    {
        $repo = new MissionCompletionRepository();
        self::assertFalse($repo->existsFor(1, 'explore_3', '2026-07-03'));
        $repo->record(1, 'explore_3', '2026-07-03', 15, 10);
        self::assertTrue($repo->existsFor(1, 'explore_3', '2026-07-03'));
        self::assertFalse($repo->existsFor(1, 'explore_3', '2026-07-04'));
    }

    public function testCountCompleted(): void
    {
        $repo = new MissionCompletionRepository();
        $repo->record(1, 'explore_3', '2026-07-03', 15, 10);
        $repo->record(1, 'streak_today', '2026-07-03', 10, 5);
        $repo->record(2, 'explore_3', '2026-07-03', 15, 10);
        self::assertSame(2, $repo->countCompleted(1));
        self::assertSame(1, $repo->countCompleted(2));
    }

    public function testSignalsForComputesFromExistingData(): void
    {
        $this->wpdb->t['progression_awards'] = [
            1 => ['id' => 1, 'child_id' => 1, 'content_id' => 10, 'award_date' => '2026-07-03'],
            2 => ['id' => 2, 'child_id' => 1, 'content_id' => 11, 'award_date' => '2026-07-03'],
        ];
        $this->wpdb->t['content_items'] = [
            1 => ['id' => 10, 'category_id' => 5],
            2 => ['id' => 11, 'category_id' => 7],
        ];
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 1, 'last_activity_date' => '2026-07-03'],
        ];

        $signals = (new MissionCompletionRepository())->signalsFor(1, '2026-07-03');
        self::assertSame(2, $signals['contentOpenedToday']);
        self::assertSame(2, $signals['categoriesToday']);
        self::assertTrue($signals['streakActiveToday']);
    }

    public function testStreakInactiveWhenLastDateIsNotToday(): void
    {
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 1, 'last_activity_date' => '2026-07-02'],
        ];
        $signals = (new MissionCompletionRepository())->signalsFor(1, '2026-07-03');
        self::assertFalse($signals['streakActiveToday']);
        self::assertSame(0, $signals['contentOpenedToday']);
    }
}
