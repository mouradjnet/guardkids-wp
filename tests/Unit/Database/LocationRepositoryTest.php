<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\LocationRepository;
use PHPUnit\Framework\TestCase;

final class LocationRepositoryTest extends TestCase
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
                $this->insert_id = 42;
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testInsertPersistsRowWithoutUpdatedAt(): void
    {
        $repo = new LocationRepository();
        $id = $repo->insert([
            'child_id'  => 1,
            'latitude'  => -8.0476,
            'longitude' => -34.8770,
            'accuracy'  => 12,
            'battery'   => 58,
            'recorded_at' => '2026-06-07 15:32:00',
        ]);

        self::assertSame(42, $id);
        $data = $this->wpdb->log[0]['data'];
        self::assertSame(1, $data['child_id']);
        self::assertSame('2026-06-07 15:32:00', $data['recorded_at']);
        self::assertNotEmpty($data['created_at']);
        self::assertArrayNotHasKey('updated_at', $data);
    }

    public function testInsertDefaultsRecordedAtToCreatedAtWhenMissing(): void
    {
        $repo = new LocationRepository();
        $repo->insert([
            'child_id'  => 1,
            'latitude'  => 0.0,
            'longitude' => 0.0,
        ]);

        $data = $this->wpdb->log[0]['data'];
        self::assertSame($data['created_at'], $data['recorded_at']);
    }

    public function testFindLastByChildIdReturnsRowOrderedDesc(): void
    {
        $this->wpdb->stubResponses['get_row'] = [
            'id' => 99, 'child_id' => 7, 'latitude' => '-8.04', 'longitude' => '-34.87',
        ];

        $repo = new LocationRepository();
        $row = $repo->findLastByChildId(7);

        self::assertNotNull($row);
        self::assertSame(99, (int) $row['id']);
        $sql = (string) $this->wpdb->log[0]['sql'];
        self::assertStringContainsString('wp_guardkids_locations', $sql);
        self::assertStringContainsString('child_id = %d', $sql);
        self::assertStringContainsString('ORDER BY recorded_at DESC', $sql);
        self::assertStringContainsString('LIMIT 1', $sql);
    }

    public function testFindLastByChildIdReturnsNullWhenEmpty(): void
    {
        $this->wpdb->stubResponses['get_row'] = null;
        $repo = new LocationRepository();
        self::assertNull($repo->findLastByChildId(42));
    }

    public function testFindByChildIdRespectsLimitClampedToMax(): void
    {
        $repo = new LocationRepository();
        $repo->findByChildId(1, 500);  // > MAX_LIMIT 100

        $sql = (string) $this->wpdb->log[1]['sql'];
        self::assertStringContainsString('LIMIT 100', $sql);
    }

    public function testFindByChildIdRespectsLimitClampedToOne(): void
    {
        $repo = new LocationRepository();
        $repo->findByChildId(1, 0);  // < 1

        $sql = (string) $this->wpdb->log[1]['sql'];
        self::assertStringContainsString('LIMIT 1', $sql);
    }
}
