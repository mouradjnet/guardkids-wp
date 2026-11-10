<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Trava a fonte única de tabelas contra as migrations: tabela nova sem entrar
 * em Schema quebra aqui (antes, uninstall/eraser/exporter divergiam em silêncio).
 */
final class SchemaTest extends TestCase
{
    /** @var array<string, string> tabela → corpo do CREATE TABLE */
    private static array $created = [];

    public static function setUpBeforeClass(): void
    {
        foreach (glob(dirname(__DIR__, 3) . '/database/migrations/*.php') ?: [] as $file) {
            $src = (string) file_get_contents($file);

            // `$var = $wpdb->prefix . 'guardkids_xxx'` → var resolve pra tabela xxx;
            // `$p = $wpdb->prefix . 'guardkids_'` → var é só o prefixo.
            preg_match_all("/\\$(\w+)\s*=\s*\\\$wpdb->prefix\s*\.\s*'guardkids_(\w*)'/", $src, $vars, PREG_SET_ORDER);
            $map = [];
            foreach ($vars as $v) {
                $map[$v[1]] = $v[2];
            }

            preg_match_all(
                '/CREATE TABLE (?:IF NOT EXISTS )?\{\$(\w+)\}(\w*)\s*\((.*?)\)\s*\{\$charsetCollate\}/s',
                $src,
                $creates,
                PREG_SET_ORDER,
            );
            foreach ($creates as $c) {
                $table = ($map[$c[1]] ?? '') . $c[2];
                self::$created[$table] = $c[3];
            }
        }
    }

    public function testParserFoundTheMigrations(): void
    {
        self::assertGreaterThanOrEqual(27, count(self::$created));
    }

    public function testTablesMatchMigrationsExactly(): void
    {
        $expected = array_keys(self::$created);
        sort($expected);
        $actual = Schema::TABLES;
        sort($actual);

        self::assertSame($expected, $actual);
    }

    public function testChildTablesAreExactlyThoseWithChildIdColumn(): void
    {
        $withChildId = [];
        foreach (self::$created as $table => $body) {
            if (preg_match('/^\s*child_id\s+BIGINT/m', $body) === 1) {
                $withChildId[] = $table;
            }
        }
        sort($withChildId);
        $actual = Schema::CHILD_TABLES;
        sort($actual);

        self::assertSame($withChildId, $actual);
    }

    public function testKeptOnEraseIsSubsetAndExcludedFromErasable(): void
    {
        foreach (Schema::KEPT_ON_ERASE as $table) {
            self::assertContains($table, Schema::TABLES);
            self::assertNotContains($table, Schema::erasableTables());
        }
        foreach (Schema::CHILD_TABLES as $table) {
            self::assertContains($table, Schema::erasableTables(), "{$table} tem dado de criança e precisa ser apagada");
        }
    }

    public function testUninstallUsesSchema(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/uninstall.php');
        self::assertStringContainsString('database/Schema.php', $src);
        self::assertStringContainsString('Schema::TABLES', $src);
    }
}
