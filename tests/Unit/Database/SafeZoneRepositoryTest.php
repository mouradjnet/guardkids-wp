<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\SafeZoneRepository;
use PHPUnit\Framework\TestCase;

final class SafeZoneRepositoryTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<int, array{method:string, sql:string|null, data:array|null}> */
            public array $log = [];
            /** @var array<string, mixed> */
            public array $stubResponses = [];

            public function __construct()
            {
            }

            public function prepare($query, ...$args)
            {
                $flat = $args[0] ?? null;
                if (is_array($flat)) {
                    $args = $flat;
                }
                $this->log[] = ['method' => 'prepare', 'sql' => (string) $query, 'data' => $args];
                return vsprintf(str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%F'], (string) $query), $args);
            }

            public function get_row($sql, $output = OBJECT, $y = 0)
            {
                $this->log[] = ['method' => 'get_row', 'sql' => (string) $sql, 'data' => null];
                return $this->stubResponses['get_row'] ?? null;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $this->log[] = ['method' => 'get_results', 'sql' => (string) $sql, 'data' => null];
                return $this->stubResponses['get_results'] ?? [];
            }

            public function insert($table, $data, $format = null)
            {
                $this->log[] = ['method' => 'insert', 'sql' => $table, 'data' => $data];
                $this->insert_id = 3;
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                $this->log[] = ['method' => 'update', 'sql' => $table, 'data' => $data];
                return 1;
            }

            public function delete($table, $where, $where_format = null)
            {
                $this->log[] = ['method' => 'delete', 'sql' => $table, 'data' => $where];
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testInsertReturnsIdAndAddsTimestamps(): void
    {
        $repo = new SafeZoneRepository();
        $id = $repo->insert([
            'name'          => 'Casa',
            'address'       => 'Rua X, 123',
            'latitude'      => -8.0476,
            'longitude'     => -34.8770,
            'radius_meters' => 100,
        ]);

        self::assertSame(3, $id);
        $data = $this->wpdb->log[0]['data'];
        self::assertSame('Casa', $data['name']);
        self::assertNotEmpty($data['created_at']);
        self::assertNotEmpty($data['updated_at']);
        self::assertSame($data['created_at'], $data['updated_at']);
    }

    public function testFindAllOrdersByNameAsc(): void
    {
        $repo = new SafeZoneRepository();
        $repo->findAll('name', 'ASC');

        $sql = (string) $this->wpdb->log[0]['sql'];
        self::assertStringContainsString('wp_guardkids_safe_zones', $sql);
        self::assertStringContainsString('ORDER BY name ASC', $sql);
    }

    public function testUpdateAndDelete(): void
    {
        $repo = new SafeZoneRepository();

        self::assertTrue($repo->update(3, ['name' => 'Casa nova']));
        self::assertTrue($repo->delete(3));

        self::assertSame('update', $this->wpdb->log[0]['method']);
        self::assertSame('delete', $this->wpdb->log[1]['method']);
    }
}
