<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Privacy;

use GuardKids\Privacy\PrivacyExporter;
use PHPUnit\Framework\TestCase;

final class PrivacyExporterTest extends TestCase
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

            public function get_results($query = null, $output = ARRAY_A)
            {
                $this->queries[] = (string) $query;
                if (str_contains((string) $query, 'guardkids_children')) {
                    return [['id' => 1, 'name' => 'Lucas']];
                }
                if (str_contains((string) $query, 'guardkids_settings')) {
                    return [
                        ['setting_key' => 'location_enabled', 'value' => 'true'],
                        ['setting_key' => 'child_token:abc', 'value' => '"hash"'],
                    ];
                }
                return [];
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testCollectIncludesAllTablesWithMeta(): void
    {
        $out = (new PrivacyExporter($this->wpdb))->collect();

        self::assertArrayHasKey('exported_at', $out);
        self::assertArrayHasKey('tables', $out);
        self::assertSame([['id' => 1, 'name' => 'Lucas']], $out['tables']['children']);
        self::assertArrayHasKey('guardians', $out['tables']);
        self::assertArrayHasKey('companion_devices', $out['tables']);
    }

    public function testCollectOmitsTokenKeysFromSettings(): void
    {
        $out = (new PrivacyExporter($this->wpdb))->collect();
        $keys = array_column($out['tables']['settings'], 'setting_key');

        self::assertContains('location_enabled', $keys);
        self::assertNotContains('child_token:abc', $keys);
    }
}
