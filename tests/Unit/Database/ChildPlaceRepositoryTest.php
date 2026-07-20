<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\ChildPlaceRepository;
use PHPUnit\Framework\TestCase;

final class ChildPlaceRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];
            /** @var array<int, string> */
            public array $queries = [];

            public function __construct()
            {
            }

            public function prepare($query, ...$args)
            {
                $flat = $args[0] ?? null;
                if (is_array($flat)) {
                    $args = $flat;
                }
                return vsprintf(str_replace(['%d', '%s'], ['%d', "'%s'"], (string) $query), $args);
            }

            public function get_row($query = null, $output = ARRAY_A, $y = 0)
            {
                if (preg_match('/child_id = (\d+)/', (string) $query, $m) === 1) {
                    return $this->rows[(int) $m[1]] ?? null;
                }
                return null;
            }

            public function query($query)
            {
                $this->queries[] = (string) $query;
                if (preg_match('/child_place.*VALUES \((\d+),\s*(\d+|NULL)/s', (string) $query, $m) === 1) {
                    $this->rows[(int) $m[1]] = [
                        'child_id'        => (int) $m[1],
                        'current_zone_id' => $m[2] === 'NULL' ? null : (int) $m[2],
                    ];
                }
                return 1;
            }

            public function delete($table, $where, $where_format = null)
            {
                unset($this->rows[(int) $where['child_id']]);
                return 1;
            }
        };
    }

    public function testGetReturnsNullWhenAbsent(): void
    {
        self::assertNull((new ChildPlaceRepository())->get(7));
    }

    public function testUpsertThenGet(): void
    {
        $repo = new ChildPlaceRepository();
        $repo->upsert(7, [
            'current_zone_id' => 3,
            'current_since'   => '2026-07-20 14:00:00',
            'pending_zone_id' => null,
            'pending_count'   => 0,
            'pending_since'   => null,
        ]);
        $row = $repo->get(7);
        self::assertNotNull($row);
        self::assertSame(3, (int) $row['current_zone_id']);
    }

    public function testDeleteByChild(): void
    {
        $repo = new ChildPlaceRepository();
        $repo->upsert(7, ['current_zone_id' => 3, 'current_since' => '2026-07-20 14:00:00', 'pending_zone_id' => null, 'pending_count' => 0, 'pending_since' => null]);
        $repo->deleteByChild(7);
        self::assertNull($repo->get(7));
    }
}
