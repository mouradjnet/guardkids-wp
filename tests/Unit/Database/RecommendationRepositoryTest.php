<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\RecommendationRepository;
use PHPUnit\Framework\TestCase;

final class RecommendationRepositoryTest extends TestCase
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

            public function prepare($query, ...$args)
            {
                $flat = $args[0] ?? null;
                if (is_array($flat)) {
                    $args = $flat;
                }
                return vsprintf(str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%F'], (string) $query), $args);
            }

            public function get_var($sql, $x = 0, $y = 0)
            {
                if (str_contains((string) $sql, 'MAX(sort_order)') && preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    $vals = array_map(static fn ($r) => (int) ($r['sort_order'] ?? 0), array_filter($this->rows, static fn ($r) => (int) $r['child_id'] === (int) $m[1]));
                    return (string) ($vals === [] ? 0 : max($vals));
                }
                return null;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $rows = array_values($this->rows);
                if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) $r['child_id'] === (int) $m[1]));
                }
                usort($rows, static fn ($a, $b) => ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0)));
                return $rows;
            }

            public function get_row($sql, $output = OBJECT, $y = 0)
            {
                if (preg_match('/WHERE id = (\d+)/', (string) $sql, $m) === 1) {
                    return $this->rows[(int) $m[1]] ?? null;
                }
                return null;
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
                unset($this->rows[$id]);
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testAddAssignsIncrementingSortOrderAndOrders(): void
    {
        $repo = new RecommendationRepository();
        $a = $repo->add(1, 10, 7, null);
        $b = $repo->add(1, 11, 7, null);
        $ordered = $repo->findByChildOrdered(1);
        self::assertSame([$a, $b], array_map(static fn ($r) => (int) $r['id'], $ordered));
    }

    public function testReorderAppliesPositions(): void
    {
        $repo = new RecommendationRepository();
        $a = $repo->add(1, 10, 7, null);
        $b = $repo->add(1, 11, 7, null);
        $repo->reorder([$b, $a]);
        $ordered = $repo->findByChildOrdered(1);
        self::assertSame([$b, $a], array_map(static fn ($r) => (int) $r['id'], $ordered));
    }

    public function testUpdateAndDelete(): void
    {
        $repo = new RecommendationRepository();
        $id = $repo->add(1, 10, 7, 'antiga');
        self::assertTrue($repo->update($id, ['note' => 'nova']));
        self::assertSame('nova', $repo->findById($id)['note']);
        self::assertTrue($repo->delete($id));
        self::assertNull($repo->findById($id));
    }
}
