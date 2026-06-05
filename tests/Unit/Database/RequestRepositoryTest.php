<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\RequestRepository;
use PHPUnit\Framework\TestCase;

/**
 * RequestRepository — findByStatus/Child + decide().
 */
final class RequestRepositoryTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var array<int, array{method:string, sql:string|null, data:array|null}> */
            public array $log = [];

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
                $this->log[] = ['method' => 'get_results', 'sql' => (string) $sql, 'data' => null];
                return [];
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                $this->log[] = ['method' => 'update', 'sql' => null, 'data' => ['set' => $data, 'where' => $where]];
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testFindByStatusFiltersByStatusOrderedByCreatedAtDesc(): void
    {
        $repo = new RequestRepository();
        $repo->findByStatus('pending');

        $sql = (string) $this->wpdb->log[0]['sql'];
        self::assertStringContainsString('wp_guardkids_requests', $sql);
        self::assertStringContainsString("status = 'pending'", $sql);
        self::assertStringContainsString('ORDER BY created_at DESC', $sql);
    }

    public function testFindByChildFiltersByChildIdOrderedDesc(): void
    {
        $repo = new RequestRepository();
        $repo->findByChild(7);

        $sql = (string) $this->wpdb->log[0]['sql'];
        self::assertStringContainsString('child_id = 7', $sql);
        self::assertStringContainsString('ORDER BY created_at DESC', $sql);
    }

    public function testDecideSetsStatusDecidedAtDecidedByUpdatedAt(): void
    {
        $repo = new RequestRepository();
        $ok = $repo->decide(99, 'approved', 5);

        self::assertTrue($ok);
        $entry = $this->wpdb->log[0];
        self::assertSame('update', $entry['method']);
        $set = $entry['data']['set'];
        self::assertSame('approved', $set['status']);
        self::assertSame(5, $set['decided_by']);
        self::assertNotEmpty($set['decided_at']);
        self::assertNotEmpty($set['updated_at']);
        self::assertSame(['id' => 99], $entry['data']['where']);
    }
}
