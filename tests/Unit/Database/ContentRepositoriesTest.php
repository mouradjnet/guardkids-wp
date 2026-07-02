<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\ContentCategoryRepository;
use GuardKids\Database\ContentRepository;
use GuardKids\Database\FavoriteRepository;
use GuardKids\Database\HistoryRepository;
use GuardKids\Database\RecommendationRepository;
use PHPUnit\Framework\TestCase;

final class ContentRepositoriesTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<string, array<int, array<string, mixed>>> por tabela */
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

            private function tableOf(string $sql): string
            {
                preg_match('/guardkids_(content_[a-z_]+)/', $sql, $m);
                return $m[1] ?? '';
            }

            public function insert($table, $data, $format = null)
            {
                $name = $this->tableOf((string) $table);
                $this->t[$name] ??= [];
                $id = count($this->t[$name]) + 1;
                $this->insert_id = $id;
                $this->t[$name][$id] = array_merge(['id' => $id], $data);
                return 1;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $name = $this->tableOf((string) $sql);
                $rows = array_values($this->t[$name] ?? []);
                if (preg_match('/category_id = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['category_id'] ?? 0) === (int) $m[1]));
                }
                if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['child_id'] ?? 0) === (int) $m[1]));
                }
                return $rows;
            }

            public function get_var($sql, $x = 0, $y = 0)
            {
                if (str_contains((string) $sql, 'COUNT(*)')) {
                    return (string) count($this->t[$this->tableOf((string) $sql)] ?? []);
                }
                return null;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testCategoryCreateAllCount(): void
    {
        $repo = new ContentCategoryRepository();
        $repo->create(['slug' => 'games', 'name' => 'Jogos', 'icon' => 'x', 'sort_order' => 1]);
        self::assertCount(1, $repo->all());
        self::assertSame(1, $repo->count());
    }

    public function testContentCreateFindByCategory(): void
    {
        $repo = new ContentRepository();
        $repo->create(['category_id' => 5, 'title' => 'A']);
        $repo->create(['category_id' => 9, 'title' => 'B']);
        self::assertSame(2, $repo->count());
        self::assertCount(1, $repo->findByCategory(5));
    }

    public function testFavoriteAddFindByChildCount(): void
    {
        $repo = new FavoriteRepository();
        $repo->add(1, 10);
        $repo->add(2, 10);
        self::assertSame(2, $repo->count());
        self::assertCount(1, $repo->findByChild(1));
    }

    public function testRecommendationAddCount(): void
    {
        $repo = new RecommendationRepository();
        $repo->add(1, 10, 7, 'olha isso');
        self::assertSame(1, $repo->count());
        self::assertCount(1, $repo->all());
    }

    public function testHistoryAddCount(): void
    {
        $repo = new HistoryRepository();
        $repo->add(1, 10, 'open');
        self::assertSame(1, $repo->count());
    }
}
