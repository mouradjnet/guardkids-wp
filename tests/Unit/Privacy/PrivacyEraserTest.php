<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Privacy;

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
            self::assertStringNotContainsString('guardkids_guardians', $sql);
            self::assertStringNotContainsString('guardkids_guardian_invites', $sql);
        }
    }

    public function testWipeAllIssuesDeleteForEachTable(): void
    {
        (new PrivacyEraser($this->wpdb))->wipeAll();

        self::assertCount(9, $this->wpdb->queries);
        foreach ($this->wpdb->queries as $sql) {
            self::assertStringStartsWith('DELETE FROM', $sql);
        }
    }
}
