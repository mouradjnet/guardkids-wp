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
            ['id' => 1, 'role' => 'admin', 'status' => 'active'],
            ['id' => 2, 'role' => 'admin', 'status' => 'active'],
        ];
        self::assertSame(2, (new GuardianRepository())->countAdmins());

        $sql = (string) $this->wpdb->log[0]['sql'];
        self::assertStringContainsString("role = 'admin'", $sql);
        self::assertStringContainsString("status = 'active'", $sql);
    }

    /**
     * Sem o filtro status='active', um admin com convite pendente seria
     * contado e o guarda de last-admin ficaria furável: dava pra deletar
     * o único admin que pode efetivamente administrar.
     */
    public function testCountAdminsExcludesPendingAdmins(): void
    {
        // Stub focal: filtra por status no SQL pra simular MySQL real.
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var array<int, array<string, mixed>> */
            public array $store = [];

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
                $needsActive = str_contains((string) $sql, "status = 'active'");
                return array_values(array_filter($this->store, static function (array $row) use ($needsActive): bool {
                    if (($row['role'] ?? '') !== 'admin') {
                        return false;
                    }
                    return ! $needsActive || ($row['status'] ?? '') === 'active';
                }));
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->wpdb->store = [
            ['id' => 1, 'role' => 'admin', 'status' => 'active'],
            ['id' => 2, 'role' => 'admin', 'status' => 'pending'],
            ['id' => 3, 'role' => 'collaborator', 'status' => 'active'],
        ];

        self::assertSame(1, (new GuardianRepository())->countAdmins());
    }

    public function testFindByInviteTokenHashFiltersByInviteTokenColumn(): void
    {
        $this->wpdb->rows = [['id' => 9, 'invite_token' => 'abc']];
        $row = (new GuardianRepository())->findByInviteTokenHash('abc');

        self::assertIsArray($row);
        $sql = (string) $this->wpdb->log[0]['sql'];
        self::assertStringContainsString("invite_token = 'abc'", $sql);
    }
}
