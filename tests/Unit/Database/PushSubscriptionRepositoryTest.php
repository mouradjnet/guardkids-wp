<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\PushSubscriptionRepository;
use PHPUnit\Framework\TestCase;

final class PushSubscriptionRepositoryTest extends TestCase
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

            public function get_results($sql, $output = OBJECT)
            {
                if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    return array_values(array_filter(
                        $this->rows,
                        static fn (array $r): bool => (int) $r['child_id'] === (int) $m[1],
                    ));
                }
                if (preg_match("/endpoint = '([^']*)'/", (string) $sql, $m) === 1) {
                    return array_values(array_filter(
                        $this->rows,
                        static fn (array $r): bool => (string) $r['endpoint'] === $m[1],
                    ));
                }
                return [];
            }

            public function insert($table, $data, $format = null)
            {
                $this->insert_id = count($this->rows) + 1;
                $this->rows[$this->insert_id] = array_merge(['id' => $this->insert_id], $data);
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                foreach ($this->rows as &$r) {
                    if ((int) $r['id'] === (int) ($where['id'] ?? 0)) {
                        $r = array_merge($r, $data);
                    }
                }
                return 1;
            }

            public function query($sql)
            {
                if (preg_match("/DELETE.*endpoint = '([^']*)'/s", (string) $sql, $m) === 1) {
                    $n = 0;
                    foreach ($this->rows as $id => $r) {
                        if ((string) $r['endpoint'] === $m[1]) {
                            unset($this->rows[$id]);
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

    public function testUpsertInsertsThenUpdatesSameEndpoint(): void
    {
        $repo = new PushSubscriptionRepository();
        $repo->upsertByEndpoint(1, 'https://push/abc', 'p1', 'a1');
        $repo->upsertByEndpoint(2, 'https://push/abc', 'p2', 'a2');
        self::assertCount(1, $this->wpdb->rows);
        $row = $this->wpdb->rows[1];
        self::assertSame(2, (int) $row['child_id']);
        self::assertSame('p2', $row['p256dh']);
    }

    public function testFindByChild(): void
    {
        $repo = new PushSubscriptionRepository();
        $repo->upsertByEndpoint(1, 'https://push/a', 'p', 'a');
        $repo->upsertByEndpoint(1, 'https://push/b', 'p', 'a');
        $repo->upsertByEndpoint(2, 'https://push/c', 'p', 'a');
        self::assertCount(2, $repo->findByChild(1));
    }

    public function testDeleteByEndpoint(): void
    {
        $repo = new PushSubscriptionRepository();
        $repo->upsertByEndpoint(1, 'https://push/a', 'p', 'a');
        $repo->deleteByEndpoint('https://push/a');
        self::assertCount(0, $this->wpdb->rows);
    }
}
