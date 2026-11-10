<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Privacy;

use GuardKids\Database\Schema;
use GuardKids\Privacy\PrivacyEraser;
use PHPUnit\Framework\TestCase;

final class PrivacyEraserTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var array<int, string> */
            public array $queries = [];

            public function __construct()
            {
            }

            public function query($sql)
            {
                $this->queries[] = (string) $sql;
                return 3;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testWipeAllDeletesFamilyTables(): void
    {
        $summary = (new PrivacyEraser($this->wpdb))->wipeAll();

        self::assertSame(3, $summary['children']);
        self::assertSame(3, $summary['settings']);
        self::assertArrayHasKey('companion_devices', $summary);
    }

    public function testWipeAllPreservesGuardians(): void
    {
        (new PrivacyEraser($this->wpdb))->wipeAll();

        foreach ($this->wpdb->queries as $sql) {
            self::assertStringNotContainsString('guardkids_guardians ', $sql . ' ');
            self::assertStringNotContainsString('guardkids_guardian_push_subscriptions', $sql);
            self::assertStringNotContainsString('guardkids_content_items', $sql);
            self::assertStringNotContainsString('guardkids_content_categories', $sql);
        }
    }

    public function testWipeAllCoversEveryChildDataTable(): void
    {
        $summary = (new PrivacyEraser($this->wpdb))->wipeAll();

        foreach (Schema::CHILD_TABLES as $table) {
            self::assertArrayHasKey($table, $summary, "{$table} tem dado de criança e ficava para trás");
        }
    }

    public function testWipeAllIssuesDeleteForEachTable(): void
    {
        (new PrivacyEraser($this->wpdb))->wipeAll();

        self::assertCount(count(Schema::erasableTables()), $this->wpdb->queries);
        foreach ($this->wpdb->queries as $sql) {
            self::assertStringStartsWith('DELETE FROM', $sql);
        }
    }
}
