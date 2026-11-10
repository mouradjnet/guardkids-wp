<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Privacy;

use GuardKids\Database\Schema;
use GuardKids\Privacy\ChildDataEraser;
use PHPUnit\Framework\TestCase;

final class ChildDataEraserTest extends TestCase
{
    public function testEraseDeletesChildRowsInEveryChildTableAndRevokesTokens(): void
    {
        $wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var array<int, string> */
            public array $queries = [];
            /** @var array<int, string> */
            public array $deletedKeys = [];

            public function __construct()
            {
            }

            public function prepare($query, ...$args)
            {
                return vsprintf(str_replace('%s', "'%s'", (string) $query), $args);
            }

            public function esc_like($text)
            {
                return $text;
            }

            public function query($sql)
            {
                $this->queries[] = (string) $sql;
                return 2;
            }

            public function get_results($sql, $output = OBJECT)
            {
                // Tokens de dispositivo em settings: 1 do filho 7, 1 do irmão (8).
                return [
                    ['setting_key' => 'child_token:aaa', 'value' => json_encode(['childId' => 7])],
                    ['setting_key' => 'child_token:bbb', 'value' => json_encode(['childId' => 8])],
                ];
            }

            public function delete($table, $where, $where_format = null)
            {
                $this->deletedKeys[] = (string) $where['setting_key'];
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $wpdb;

        $summary = (new ChildDataEraser($wpdb))->erase(7);

        self::assertCount(count(Schema::CHILD_TABLES), $wpdb->queries);
        foreach (Schema::CHILD_TABLES as $i => $table) {
            self::assertSame("DELETE FROM wp_guardkids_{$table} WHERE child_id = 7", $wpdb->queries[$i]);
            self::assertSame(2, $summary[$table]);
        }
        self::assertSame(['child_token:aaa'], $wpdb->deletedKeys);
        self::assertSame(1, $summary['device_tokens']);
    }
}
