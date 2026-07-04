<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\ContentRepository;
use PHPUnit\Framework\TestCase;

final class ContentModerationRepoTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new class () extends \wpdb {
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
                return vsprintf(str_replace(['%d', '%s'], ['%d', "'%s'"], (string) $query), $args);
            }
            public function get_var($sql, $x = 0, $y = 0)
            {
                if (str_contains((string) $sql, 'COUNT(*)') && preg_match("/status = '([a-z]+)'/", (string) $sql, $m) === 1) {
                    return (string) count(array_filter($this->rows, static fn ($r) => ($r['status'] ?? null) === $m[1]));
                }
                return null;
            }
            public function get_results($sql, $output = OBJECT)
            {
                $rows = array_values($this->rows);
                if (str_contains((string) $sql, "status = 'approved'")) {
                    $rows = array_values(array_filter($rows, static fn ($r) => ($r['status'] ?? null) === 'approved'));
                }
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
        };
    }

    public function testCreateDefaultsToPending(): void
    {
        $repo = new ContentRepository();
        $id = $repo->create(['title' => 'A']);
        self::assertSame('pending', $repo->findById($id)['status']);
    }

    public function testSearchApprovedOnlyExcludesPending(): void
    {
        $repo = new ContentRepository();
        $repo->create(['title' => 'A']);
        $approvedId = $repo->create(['title' => 'B']);
        $repo->approve($approvedId, 7);

        $all = $repo->search(null, null, null, false);
        $approved = $repo->search(null, null, null, true);
        self::assertCount(2, $all);
        self::assertCount(1, $approved);
        self::assertSame('B', $approved[0]['title']);
    }

    public function testFindApprovedByIdReturnsNullForPending(): void
    {
        $repo = new ContentRepository();
        $id = $repo->create(['title' => 'A']);
        self::assertNull($repo->findApprovedById($id));
        $repo->approve($id, 7);
        self::assertNotNull($repo->findApprovedById($id));
    }

    public function testApproveSetsApproverAndRevokeClearsIt(): void
    {
        $repo = new ContentRepository();
        $id = $repo->create(['title' => 'A']);
        $repo->approve($id, 42);
        $row = $repo->findById($id);
        self::assertSame('approved', $row['status']);
        self::assertSame(42, (int) $row['approved_by']);
        self::assertNotNull($row['approved_at']);

        $repo->revoke($id);
        $row = $repo->findById($id);
        self::assertSame('pending', $row['status']);
        self::assertNull($row['approved_by']);
        self::assertNull($row['approved_at']);
    }

    public function testCountByStatus(): void
    {
        $repo = new ContentRepository();
        $repo->create(['title' => 'A']);
        $repo->create(['title' => 'B']);
        $c = $repo->create(['title' => 'C']);
        $repo->approve($c, 7);
        self::assertSame(2, $repo->countByStatus('pending'));
        self::assertSame(1, $repo->countByStatus('approved'));
    }
}
