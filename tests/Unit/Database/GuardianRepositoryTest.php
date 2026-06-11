<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\GuardianRepository;
use PHPUnit\Framework\TestCase;

/**
 * GuardianRepository — findByEmail / findByWpUserId / countAdmins.
 */
final class GuardianRepositoryTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var array<int, array{method:string, sql:string|null}> */
            public array $log = [];
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

            public function get_results($sql, $output = OBJECT)
            {
                $this->log[] = ['method' => 'get_results', 'sql' => (string) $sql];
                return $this->rows;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testFindByEmailFiltersByEmailColumn(): void
    {
        $this->wpdb->rows = [['id' => 1, 'email' => 'a@b.c']];
        $row = (new GuardianRepository())->findByEmail('a@b.c');

        self::assertIsArray($row);
        $sql = (string) $this->wpdb->log[0]['sql'];
        self::assertStringContainsString('wp_guardkids_guardians', $sql);
        self::assertStringContainsString("email = 'a@b.c'", $sql);
    }

    public function testFindByEmailReturnsNullWhenNoRows(): void
    {
        $this->wpdb->rows = [];
        self::assertNull((new GuardianRepository())->findByEmail('missing@x.com'));
    }

    public function testFindByWpUserIdFiltersByWpUserIdColumn(): void
    {
        $this->wpdb->rows = [['id' => 1, 'wp_user_id' => 42]];
        $row = (new GuardianRepository())->findByWpUserId(42);

        self::assertIsArray($row);
        $sql = (string) $this->wpdb->log[0]['sql'];
        self::assertStringContainsString('wp_user_id = 42', $sql);
    }

    public function testCountAdminsReturnsRowCount(): void
    {
        $this->wpdb->rows = [
            ['id' => 1, 'role' => 'admin'],
            ['id' => 2, 'role' => 'admin'],
        ];
        self::assertSame(2, (new GuardianRepository())->countAdmins());

        $sql = (string) $this->wpdb->log[0]['sql'];
        self::assertStringContainsString("role = 'admin'", $sql);
    }
}
