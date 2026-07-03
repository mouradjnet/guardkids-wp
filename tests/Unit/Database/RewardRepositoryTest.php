<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\RewardRepository;
use PHPUnit\Framework\TestCase;

final class RewardRepositoryTest extends TestCase
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

            public function get_results($sql, $output = OBJECT)
            {
                $rows = array_values($this->rows);
                if (preg_match('/active = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['active'] ?? 0) === (int) $m[1]));
                }
                return $rows;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testFindActiveReturnsOnlyActive(): void
    {
        $repo = new RewardRepository();
        $repo->insert(['title' => 'Sorvete', 'cost_coins' => 100, 'icon' => 'icecream', 'active' => 1]);
        $repo->insert(['title' => 'Antigo', 'cost_coins' => 50, 'icon' => null, 'active' => 0]);
        $active = $repo->findActive();
        self::assertCount(1, $active);
        self::assertSame('Sorvete', $active[0]['title']);
    }
}
