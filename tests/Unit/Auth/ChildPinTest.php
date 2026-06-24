<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Auth;

use GuardKids\Auth\ChildPin;
use PHPUnit\Framework\TestCase;

/**
 * Testes do ChildPin — usa SettingsRepository in-memory (wpdb fake) pra
 * evitar DB, espelhando o harness do ChildAuthTest.
 */
final class ChildPinTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var array<string, string> key → JSON */
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

            public function get_var($sql, $x = 0, $y = 0)
            {
                if (preg_match("/setting_key = '([^']+)'/", (string) $sql, $m) === 1) {
                    return $this->store[$m[1]] ?? null;
                }
                return null;
            }

            public function get_results($sql, $output = OBJECT)
            {
                return [];
            }

            public function insert($table, $data, $format = null)
            {
                $this->store[$data['setting_key']] = (string) $data['value'];
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                return 1;
            }

            public function delete($table, $where, $where_format = null)
            {
                unset($this->store[$where['setting_key']]);
                return 1;
            }
        };
    }

    public function testSetThenVerifyAcceptsCorrectPin(): void
    {
        $pin = new ChildPin();
        self::assertTrue($pin->set('1234'));
        self::assertTrue($pin->isSet());
        self::assertTrue($pin->verify('1234'));
    }

    public function testVerifyRejectsWrongPin(): void
    {
        $pin = new ChildPin();
        $pin->set('4321');
        self::assertFalse($pin->verify('1234'));
    }

    public function testPinIsNeverStoredInPlainText(): void
    {
        $pin = new ChildPin();
        $pin->set('246810');
        $stored = json_decode($GLOBALS['wpdb']->store['child_pin:secret'] ?? '', true);
        self::assertIsString($stored);
        self::assertStringNotContainsString('246810', $stored);
        self::assertTrue(password_verify('246810', $stored));
    }

    public function testSetRejectsInvalidFormats(): void
    {
        $pin = new ChildPin();
        self::assertFalse($pin->set('123'));      // curto demais
        self::assertFalse($pin->set('1234567'));  // longo demais
        self::assertFalse($pin->set('12a4'));     // não numérico
        self::assertFalse($pin->set(''));
        self::assertFalse($pin->isSet());
    }

    public function testVerifyFailsClosedWhenNoPinSet(): void
    {
        $pin = new ChildPin();
        self::assertFalse($pin->isSet());
        self::assertFalse($pin->verify('1234'));
    }

    public function testClearRemovesPin(): void
    {
        $pin = new ChildPin();
        $pin->set('1234');
        self::assertTrue($pin->isSet());
        $pin->clear();
        self::assertFalse($pin->isSet());
        self::assertFalse($pin->verify('1234'));
    }
}
