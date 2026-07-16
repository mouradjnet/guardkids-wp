<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\GuardianPushDedupRepository;
use PHPUnit\Framework\TestCase;

final class GuardianPushDedupRepositoryTest extends TestCase
{
    private function bootWpdb(): \wpdb
    {
        $wpdb = new class () extends \wpdb {
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

            public function get_results($query = null, $output = ARRAY_A)
            {
                if (preg_match("/dedup_key = '([^']*)'/", (string) $query, $m) === 1) {
                    return array_values(array_filter(
                        $this->rows,
                        static fn (array $r): bool => (string) $r['dedup_key'] === $m[1],
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
        };
        $GLOBALS['wpdb'] = $wpdb;
        return $wpdb;
    }

    public function testFirstCallCreatesAndReturnsTrue(): void
    {
        $wpdb = $this->bootWpdb();

        $created = (new GuardianPushDedupRepository())->createIfAbsent('req:42');

        self::assertTrue($created);
        self::assertCount(1, $wpdb->rows);
        self::assertSame('req:42', $wpdb->rows[1]['dedup_key']);
        self::assertArrayNotHasKey('updated_at', $wpdb->rows[1]);
    }

    public function testSecondCallWithSameKeyReturnsFalseAndDoesNotInsert(): void
    {
        $wpdb = $this->bootWpdb();
        $repo = new GuardianPushDedupRepository();

        $repo->createIfAbsent('req:42');
        $second = $repo->createIfAbsent('req:42');

        self::assertFalse($second, 'a segunda vez nao pode reenviar');
        self::assertCount(1, $wpdb->rows);
    }

    public function testDifferentKeysBothCreate(): void
    {
        $wpdb = $this->bootWpdb();
        $repo = new GuardianPushDedupRepository();

        self::assertTrue($repo->createIfAbsent('req:1'));
        self::assertTrue($repo->createIfAbsent('req:2'));
        self::assertCount(2, $wpdb->rows);
    }
}
