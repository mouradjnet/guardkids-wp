<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\ContentRepository;
use PHPUnit\Framework\TestCase;

final class ContentRepositorySearchTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];

            public function __construct()
            {
            }

            public function esc_like($text)
            {
                return addcslashes((string) $text, '_%\\');
            }

            public function prepare($query, ...$args)
            {
                $flat = $args[0] ?? null;
                if (is_array($flat)) {
                    $args = $flat;
                }
                return vsprintf(str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%F'], (string) $query), $args);
            }

            public function get_results($sql, $output = OBJECT)
            {
                $rows = array_values($this->rows);
                if (preg_match('/category_id = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['category_id'] ?? 0) === (int) $m[1]));
                }
                if (preg_match("/title LIKE '%([^%']+)%'/", (string) $sql, $m) === 1) {
                    $term = strtolower($m[1]);
                    $rows = array_values(array_filter($rows, static fn ($r) =>
                        str_contains(strtolower((string) ($r['title'] ?? '')), $term)
                        || str_contains(strtolower((string) ($r['tags'] ?? '')), $term)));
                }
                if (preg_match('/age_min <= (\d+) AND age_max >= (\d+)/', (string) $sql, $m) === 1) {
                    $age = (int) $m[1];
                    $rows = array_values(array_filter($rows, static fn ($r) =>
                        (int) ($r['age_min'] ?? 0) <= $age && (int) ($r['age_max'] ?? 99) >= $age));
                }
                return $rows;
            }

            public function insert($table, $data, $format = null)
            {
                $id = count($this->rows) + 1;
                $this->insert_id = $id;
                $this->rows[$id] = array_merge(['id' => $id], $data);
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                $id = (int) ($where['id'] ?? 0);
                if (isset($this->rows[$id])) {
                    $this->rows[$id] = array_merge($this->rows[$id], $data);
                    return 1;
                }
                return 0;
            }

            public function delete($table, $where, $where_format = null)
            {
                $id = (int) ($where['id'] ?? 0);
                if (isset($this->rows[$id])) {
                    unset($this->rows[$id]);
                    return 1;
                }
                return 0;
            }

            public function get_row($sql, $output = OBJECT, $y = 0)
            {
                if (preg_match('/WHERE id = (\d+)/', (string) $sql, $m) === 1) {
                    return $this->rows[(int) $m[1]] ?? null;
                }
                return null;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testSearchByCategory(): void
    {
        $repo = new ContentRepository();
        $repo->create(['category_id' => 1, 'title' => 'A', 'age_min' => 0, 'age_max' => 99]);
        $repo->create(['category_id' => 2, 'title' => 'B', 'age_min' => 0, 'age_max' => 99]);
        self::assertCount(1, $repo->search(1, null, null));
    }

    public function testSearchByTermMatchesTitleOrTags(): void
    {
        $repo = new ContentRepository();
        $repo->create(['category_id' => 1, 'title' => 'Roblox', 'tags' => 'jogo', 'age_min' => 0, 'age_max' => 99]);
        $repo->create(['category_id' => 1, 'title' => 'Khan', 'tags' => 'matematica', 'age_min' => 0, 'age_max' => 99]);
        self::assertCount(1, $repo->search(null, 'roblox', null));
        self::assertCount(1, $repo->search(null, 'matemat', null));
    }

    public function testSearchByAgeFilter(): void
    {
        $repo = new ContentRepository();
        $repo->create(['category_id' => 1, 'title' => 'A', 'age_min' => 4, 'age_max' => 6]);
        $repo->create(['category_id' => 1, 'title' => 'B', 'age_min' => 10, 'age_max' => 13]);
        self::assertCount(1, $repo->search(null, null, 5));
    }

    public function testUpdateAndDelete(): void
    {
        $repo = new ContentRepository();
        $repo->create(['category_id' => 1, 'title' => 'A']);
        self::assertTrue($repo->update(1, ['title' => 'B']));
        self::assertSame('B', $repo->findById(1)['title']);
        self::assertTrue($repo->delete(1));
        self::assertNull($repo->findById(1));
    }
}
