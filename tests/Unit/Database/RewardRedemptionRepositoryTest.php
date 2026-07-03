<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\RewardRedemptionRepository;
use PHPUnit\Framework\TestCase;

final class RewardRedemptionRepositoryTest extends TestCase
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

            public function get_row($sql, $output = OBJECT, $y = 0)
            {
                if (preg_match('/id = (\d+)/', (string) $sql, $m) === 1) {
                    return $this->rows[(int) $m[1]] ?? null;
                }
                return null;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $rows = array_values($this->rows);
                foreach (['child_id', 'reward_id'] as $col) {
                    if (preg_match("/{$col} = (\d+)/", (string) $sql, $m) === 1) {
                        $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r[$col] ?? 0) === (int) $m[1]));
                    }
                }
                if (preg_match("/status = '([^']+)'/", (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (string) ($r['status'] ?? '') === $m[1]));
                }
                return $rows;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testCreateAndFindByChild(): void
    {
        $repo = new RewardRedemptionRepository();
        $repo->create(1, 7, 100);
        $rows = $repo->findByChild(1);
        self::assertCount(1, $rows);
        self::assertSame(100, (int) $rows[0]['cost_coins']);
        self::assertSame('pending', $rows[0]['status']);
    }

    public function testHasPendingFor(): void
    {
        $repo = new RewardRedemptionRepository();
        self::assertFalse($repo->hasPendingFor(1, 7));
        $repo->create(1, 7, 100);
        self::assertTrue($repo->hasPendingFor(1, 7));
        self::assertFalse($repo->hasPendingFor(1, 8));
    }

    public function testDecideSetsStatus(): void
    {
        $repo = new RewardRedemptionRepository();
        $id = $repo->create(1, 7, 100);
        self::assertTrue($repo->decide($id, 'approved', 42));
        $row = $repo->findById($id);
        self::assertSame('approved', $row['status']);
        self::assertSame(42, (int) $row['decided_by']);
    }

    public function testFindByStatus(): void
    {
        $repo = new RewardRedemptionRepository();
        $repo->create(1, 7, 100);
        $id2 = $repo->create(2, 8, 50);
        $repo->decide($id2, 'denied', 42);
        self::assertCount(1, $repo->findByStatus('pending'));
        self::assertCount(1, $repo->findByStatus('denied'));
    }
}
