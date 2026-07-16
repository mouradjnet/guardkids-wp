<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\GuardianPushSubscriptionRepository;
use PHPUnit\Framework\TestCase;

final class GuardianPushSubscriptionRepositoryTest extends TestCase
{
    private function bootWpdb(): \wpdb
    {
        $wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];
            /** @var array<int, array<string, mixed>> */
            public array $updates = [];

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

            public function get_results($query = null, $output = ARRAY_A)
            {
                if (preg_match("/endpoint = '([^']*)'/", (string) $query, $m) === 1) {
                    return array_values(array_filter(
                        $this->rows,
                        static fn (array $r): bool => (string) $r['endpoint'] === $m[1],
                    ));
                }
                return array_values($this->rows);
            }

            public function insert($table, $data, $format = null)
            {
                $this->insert_id = count($this->rows) + 1;
                $this->rows[$this->insert_id] = array_merge(['id' => $this->insert_id], $data);
                return 1;
            }

            public function update($table, $data, $where, $format = null, $whereFormat = null)
            {
                $this->updates[] = ['data' => $data, 'where' => $where];
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $wpdb;
        return $wpdb;
    }

    public function testUpsertInsertsWhenEndpointIsNew(): void
    {
        $wpdb = $this->bootWpdb();

        (new GuardianPushSubscriptionRepository())
            ->upsertByEndpoint(7, 'https://fcm.example/abc', 'P256', 'AUTH');

        self::assertCount(1, $wpdb->rows);
        $row = $wpdb->rows[1];
        self::assertSame(7, $row['wp_user_id']);
        self::assertSame('https://fcm.example/abc', $row['endpoint']);
        self::assertArrayHasKey('created_at', $row);
        self::assertArrayNotHasKey('updated_at', $row, 'a tabela nao tem updated_at');
    }

    public function testUpsertUpdatesWhenEndpointExists(): void
    {
        $wpdb = $this->bootWpdb();
        $wpdb->rows[1] = [
            'id'     => 1,
            'wp_user_id' => 7,
            'endpoint' => 'https://fcm.example/abc',
            'p256dh' => 'OLD',
            'auth'   => 'OLD',
            'created_at' => '2026-01-01 00:00:00',
        ];

        (new GuardianPushSubscriptionRepository())
            ->upsertByEndpoint(9, 'https://fcm.example/abc', 'NEW', 'NEWAUTH');

        self::assertCount(1, $wpdb->rows, 'nao pode inserir duplicado');
        self::assertCount(1, $wpdb->updates);
        self::assertSame(9, $wpdb->updates[0]['data']['wp_user_id']);
        self::assertSame('NEW', $wpdb->updates[0]['data']['p256dh']);
        self::assertSame(['id' => 1], $wpdb->updates[0]['where']);
        self::assertArrayNotHasKey('updated_at', $wpdb->updates[0]['data']);
    }

    public function testFindByUserFiltersByWpUserId(): void
    {
        $wpdb = $this->bootWpdb();
        $wpdb->rows[1] = ['id' => 1, 'wp_user_id' => 7, 'endpoint' => 'https://a'];

        $rows = (new GuardianPushSubscriptionRepository())->findByUser(7);

        self::assertCount(1, $rows);
    }

    public function testDeleteByEndpointIssuesDelete(): void
    {
        $wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
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

            public function query($query)
            {
                $this->queries[] = (string) $query;
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $wpdb;

        (new GuardianPushSubscriptionRepository())->deleteByEndpoint('https://fcm.example/abc');

        self::assertCount(1, $wpdb->queries);
        self::assertStringContainsString('DELETE FROM', $wpdb->queries[0]);
        self::assertStringContainsString('guardian_push_subscriptions', $wpdb->queries[0]);
        self::assertStringContainsString('https://fcm.example/abc', $wpdb->queries[0]);
    }
}
