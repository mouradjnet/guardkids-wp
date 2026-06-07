<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\SettingsRepository;
use PHPUnit\Framework\TestCase;

/**
 * SettingsRepository — key-value JSON store. Cobre encode/decode + insert vs update.
 */
final class SettingsRepositoryTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var array<string, string> chave -> JSON */
            public array $rows = [];
            /** @var array<int, array{method:string, args:array}> */
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

            public function get_var($sql, $x = 0, $y = 0)
            {
                $this->log[] = ['method' => 'get_var', 'args' => [$sql]];
                if (preg_match("/SELECT value FROM .* WHERE setting_key = '([^']+)'/", (string) $sql, $m) === 1) {
                    return $this->rows[$m[1]] ?? null;
                }
                if (preg_match("/SELECT id FROM .* WHERE setting_key = '([^']+)'/", (string) $sql, $m) === 1) {
                    return isset($this->rows[$m[1]]) ? '1' : null;
                }
                return null;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $this->log[] = ['method' => 'get_results', 'args' => [$sql]];
                $out = [];
                foreach ($this->rows as $key => $value) {
                    $out[] = ['setting_key' => $key, 'value' => $value];
                }
                return $out;
            }

            public function insert($table, $data, $format = null)
            {
                $this->log[] = ['method' => 'insert', 'args' => [$table, $data]];
                $this->rows[$data['setting_key']] = (string) $data['value'];
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                $this->log[] = ['method' => 'update', 'args' => [$table, $data, $where]];
                // não rastreia por id; assume primeira chave do array
                $keys = array_keys($this->rows);
                if ($keys !== []) {
                    $this->rows[$keys[0]] = (string) $data['value'];
                }
                return 1;
            }
        };

        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testSetThenGetRoundtripBool(): void
    {
        $repo = new SettingsRepository();
        $repo->set('notifications.push', true);

        self::assertTrue($repo->get('notifications.push'));
    }

    public function testSetThenGetRoundtripArray(): void
    {
        $repo = new SettingsRepository();
        $repo->set('foo', ['a' => 1, 'b' => 'two']);

        self::assertSame(['a' => 1, 'b' => 'two'], $repo->get('foo'));
    }

    public function testGetReturnsDefaultWhenMissing(): void
    {
        $repo = new SettingsRepository();
        self::assertSame('fallback', $repo->get('inexistente', 'fallback'));
    }

    public function testGetReturnsDefaultWhenStoredValueIsInvalidJson(): void
    {
        $this->wpdb->rows['broken'] = 'not-json-at-all-{';
        $repo = new SettingsRepository();

        self::assertSame('fallback', $repo->get('broken', 'fallback'));
    }

    public function testSetUsesInsertOnFirstWrite(): void
    {
        $repo = new SettingsRepository();
        $repo->set('first', 42);

        $inserts = array_filter($this->wpdb->log, fn ($e) => $e['method'] === 'insert');
        $updates = array_filter($this->wpdb->log, fn ($e) => $e['method'] === 'update');
        self::assertCount(1, $inserts);
        self::assertCount(0, $updates);
    }

    public function testSetUsesUpdateOnSecondWrite(): void
    {
        $repo = new SettingsRepository();
        $repo->set('foo', 1);
        $repo->set('foo', 2);

        $inserts = array_filter($this->wpdb->log, fn ($e) => $e['method'] === 'insert');
        $updates = array_filter($this->wpdb->log, fn ($e) => $e['method'] === 'update');
        self::assertCount(1, $inserts);
        self::assertCount(1, $updates);
    }

    public function testIsLocationEnabledReturnsFalseByDefault(): void
    {
        $repo = new SettingsRepository();
        self::assertFalse($repo->isLocationEnabled());
    }

    public function testIsLocationEnabledReturnsTrueWhenSet(): void
    {
        $repo = new SettingsRepository();
        $repo->set('location_enabled', true);
        self::assertTrue($repo->isLocationEnabled());
    }

    public function testIsLocationEnabledReturnsFalseWhenSetToFalse(): void
    {
        $repo = new SettingsRepository();
        $repo->set('location_enabled', false);
        self::assertFalse($repo->isLocationEnabled());
    }

    public function testAllReturnsAllKeysDecoded(): void
    {
        $repo = new SettingsRepository();
        $repo->set('a', true);
        $repo->set('b', ['x']);
        $repo->set('c', 'string');

        $all = $repo->all();
        self::assertSame(true, $all['a']);
        self::assertSame(['x'], $all['b']);
        self::assertSame('string', $all['c']);
    }
}
