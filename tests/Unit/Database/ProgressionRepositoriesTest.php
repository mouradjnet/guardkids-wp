<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\AwardRepository;
use GuardKids\Database\ProgressionRepository;
use PHPUnit\Framework\TestCase;

final class ProgressionRepositoriesTest extends TestCase
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
                if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['child_id'] ?? 0) === (int) $m[1]));
                }
                if (preg_match('/content_id = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['content_id'] ?? 0) === (int) $m[1]));
                }
                if (preg_match("/award_date = '([^']+)'/", (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (string) ($r['award_date'] ?? '') === $m[1]));
                }
                return $rows;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testEnsureCreatesZeroedThenApplyAdds(): void
    {
        $repo = new ProgressionRepository();
        $row = $repo->ensure(1);
        self::assertSame(0, (int) $row['xp']);

        $repo->apply(1, 10, 15, 3, '2026-07-02');
        $after = $repo->findByChild(1);
        self::assertSame(10, (int) $after['xp']);
        self::assertSame(15, (int) $after['coins']);
        self::assertSame(3, (int) $after['streak_days']);
        self::assertSame('2026-07-02', $after['last_activity_date']);
    }

    public function testAwardExistsForAndRecord(): void
    {
        $repo = new AwardRepository();
        self::assertFalse($repo->existsFor(1, 10, '2026-07-02'));
        $repo->record(1, 10, '2026-07-02', 10, 5);
        self::assertTrue($repo->existsFor(1, 10, '2026-07-02'));
        self::assertFalse($repo->existsFor(1, 10, '2026-07-03'));
        self::assertFalse($repo->existsFor(1, 11, '2026-07-02'));
    }
}
