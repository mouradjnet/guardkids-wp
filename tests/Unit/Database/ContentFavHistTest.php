<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\FavoriteRepository;
use GuardKids\Database\HistoryRepository;
use PHPUnit\Framework\TestCase;

final class ContentFavHistTest extends TestCase
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
                preg_match('/guardkids_(content_[a-z_]+)/', $sql, $m);
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

            public function get_results($sql, $output = OBJECT)
            {
                $rows = array_values($this->t[$this->nameOf((string) $sql)] ?? []);
                if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['child_id'] ?? 0) === (int) $m[1]));
                }
                return $rows;
            }

            public function query($sql)
            {
                if (preg_match('/DELETE FROM \S*guardkids_(content_[a-z_]+).*child_id = (\d+) AND content_id = (\d+)/s', (string) $sql, $m) === 1) {
                    foreach (($this->t[$m[1]] ?? []) as $id => $r) {
                        if ((int) $r['child_id'] === (int) $m[2] && (int) $r['content_id'] === (int) $m[3]) {
                            unset($this->t[$m[1]][$id]);
                        }
                    }
                }
                return 0;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testFavoriteRemoveAndContentIds(): void
    {
        $repo = new FavoriteRepository();
        $repo->add(1, 10);
        $repo->add(1, 11);
        self::assertSame([10, 11], $repo->contentIdsOf(1));
        $repo->remove(1, 10);
        self::assertSame([11], $repo->contentIdsOf(1));
    }

    public function testHistoryRecordWithDurationAndAll(): void
    {
        $repo = new HistoryRepository();
        $repo->record(1, 10, 'open', 120);
        self::assertCount(1, $repo->all());
        self::assertSame(120, (int) $repo->all()[0]['duration_seconds']);
    }
}
