<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\CategoryRepository;
use PHPUnit\Framework\TestCase;

/**
 * CategoryRepository — testa o comportamento idempotente do seed().
 */
final class CategoryRepositoryTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $existingCount = 0;
            public int $insertCalls = 0;

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
                if (str_contains((string) $sql, 'COUNT(*)')) {
                    return (string) $this->existingCount;
                }
                return null;
            }

            public function insert($table, $data, $format = null)
            {
                $this->insertCalls++;
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testSeedInsertsAllDefaultsWhenTableEmpty(): void
    {
        $repo = new CategoryRepository();
        $repo->seed([
            ['slug' => 'a', 'name' => 'A', 'blocked' => 1],
            ['slug' => 'b', 'name' => 'B', 'blocked' => 0],
            ['slug' => 'c', 'name' => 'C', 'blocked' => 1],
        ]);

        self::assertSame(3, $this->wpdb->insertCalls);
    }

    public function testSeedSkipsWhenTableHasRows(): void
    {
        $this->wpdb->existingCount = 6;
        $repo = new CategoryRepository();
        $repo->seed([
            ['slug' => 'a', 'name' => 'A', 'blocked' => 1],
        ]);

        self::assertSame(0, $this->wpdb->insertCalls);
    }

    public function testSeedWithEmptyDefaultsDoesNothing(): void
    {
        $repo = new CategoryRepository();
        $repo->seed([]);

        self::assertSame(0, $this->wpdb->insertCalls);
    }
}
