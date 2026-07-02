<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\NotificationRepository;
use PHPUnit\Framework\TestCase;

final class NotificationRepositoryTest extends TestCase
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
                $this->insert_id = count($this->rows) + 1;
                $this->rows[$this->insert_id] = array_merge(['id' => $this->insert_id], $data);
                return 1;
            }

            public function get_results($sql, $output = OBJECT)
            {
                if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    $out = array_values(array_filter(
                        $this->rows,
                        static fn (array $r): bool => (int) $r['child_id'] === (int) $m[1],
                    ));
                    if (preg_match("/dedup_key = '([^']*)'/", (string) $sql, $d) === 1) {
                        $out = array_values(array_filter(
                            $out,
                            static fn (array $r): bool => (string) ($r['dedup_key'] ?? '') === $d[1],
                        ));
                    }
                    return $out;
                }
                return [];
            }

            public function get_var($sql, $x = 0, $y = 0)
            {
                if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    return (string) count(array_filter(
                        $this->rows,
                        static fn (array $r): bool => (int) $r['child_id'] === (int) $m[1]
                            && ($r['read_at'] ?? null) === null,
                    ));
                }
                return null;
            }

            public function query($sql)
            {
                if (preg_match('/UPDATE.*child_id = (\d+)/s', (string) $sql, $m) === 1) {
                    $n = 0;
                    foreach ($this->rows as &$r) {
                        if ((int) $r['child_id'] === (int) $m[1] && ($r['read_at'] ?? null) === null) {
                            $r['read_at'] = '2026-07-02 00:00:00';
                            $n++;
                        }
                    }
                    return $n;
                }
                return 0;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testCreateInsertsRowAndReturnsId(): void
    {
        $id = (new NotificationRepository())->create([
            'child_id' => 1,
            'type'     => 'request_approved',
            'title'    => 'Aprovado',
            'body'     => 'canva.com',
        ]);
        self::assertSame(1, $id);
        self::assertSame('request_approved', $this->wpdb->rows[1]['type']);
        self::assertNull($this->wpdb->rows[1]['read_at']);
    }

    public function testCreateIfAbsentSkipsDuplicateDedupKey(): void
    {
        $repo = new NotificationRepository();
        self::assertTrue($repo->createIfAbsent(1, 'req:9', ['type' => 'x', 'title' => 't']));
        self::assertFalse($repo->createIfAbsent(1, 'req:9', ['type' => 'x', 'title' => 't']));
        self::assertCount(1, $this->wpdb->rows);
    }

    public function testUnreadCountAndMarkAllRead(): void
    {
        $repo = new NotificationRepository();
        $repo->create(['child_id' => 1, 'type' => 'x', 'title' => 'a']);
        $repo->create(['child_id' => 1, 'type' => 'x', 'title' => 'b']);
        $repo->create(['child_id' => 2, 'type' => 'x', 'title' => 'c']);
        self::assertSame(2, $repo->unreadCount(1));
        self::assertSame(2, $repo->markAllRead(1));
        self::assertSame(0, $repo->unreadCount(1));
    }

    public function testFindByChildFiltersById(): void
    {
        $repo = new NotificationRepository();
        $repo->create(['child_id' => 1, 'type' => 'x', 'title' => 'a']);
        $repo->create(['child_id' => 2, 'type' => 'x', 'title' => 'b']);
        self::assertCount(1, $repo->findByChild(1));
    }
}
