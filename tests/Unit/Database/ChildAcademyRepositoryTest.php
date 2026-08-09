<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\ChildAcademyRepository;
use PHPUnit\Framework\TestCase;

final class ChildAcademyRepositoryTest extends TestCase
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

            public function insert($table, $data, $format = null)
            {
                $this->t['academy_child_lessons'] ??= [];
                $id = count($this->t['academy_child_lessons']) + 1;
                $this->insert_id = $id;
                $this->t['academy_child_lessons'][$id] = array_merge(['id' => $id], $data);
                return 1;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $rows = array_values($this->t['academy_child_lessons'] ?? []);
                if (preg_match('/child_id = (\d+)/', (string) $sql, $mm) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['child_id'] ?? 0) === (int) $mm[1]));
                }
                if (preg_match("/lesson_key = '([^']+)'/", (string) $sql, $mm) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (string) ($r['lesson_key'] ?? '') === $mm[1]));
                }
                return $rows;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testRecordThenExistsFor(): void
    {
        $repo = new ChildAcademyRepository();
        self::assertFalse($repo->existsFor(1, 'seguro-o-que-e'));
        $repo->record(1, 'seguro-o-que-e', 25, 15);
        self::assertTrue($repo->existsFor(1, 'seguro-o-que-e'));
        // outra criança não herda a conclusão
        self::assertFalse($repo->existsFor(2, 'seguro-o-que-e'));
        // outra aula da mesma criança ainda não concluída
        self::assertFalse($repo->existsFor(1, 'seguro-senha'));
    }

    public function testListCompletedIsPerChild(): void
    {
        $repo = new ChildAcademyRepository();
        $repo->record(1, 'seguro-o-que-e', 25, 15);
        $repo->record(1, 'seguro-senha', 25, 15);
        $repo->record(2, 'seguro-o-que-e', 25, 15);

        self::assertSame(['seguro-o-que-e', 'seguro-senha'], $repo->listCompleted(1));
        self::assertSame(['seguro-o-que-e'], $repo->listCompleted(2));
        self::assertSame([], $repo->listCompleted(3));
    }
}
