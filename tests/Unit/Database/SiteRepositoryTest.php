<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\SiteRepository;
use PHPUnit\Framework\TestCase;

final class SiteRepositoryTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var array<int, string> */
            public array $sqls = [];

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
                $this->sqls[] = (string) $sql;
                return [];
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testFindByListFiltersByListTypeOrderedByDomainAsc(): void
    {
        $repo = new SiteRepository();
        $repo->findByList('whitelist');

        self::assertStringContainsString('wp_guardkids_sites', $this->wpdb->sqls[0]);
        self::assertStringContainsString("list_type = 'whitelist'", $this->wpdb->sqls[0]);
        self::assertStringContainsString('ORDER BY domain ASC', $this->wpdb->sqls[0]);
    }

    public function testFindByListAcceptsBlacklist(): void
    {
        $repo = new SiteRepository();
        $repo->findByList('blacklist');

        self::assertStringContainsString("list_type = 'blacklist'", $this->wpdb->sqls[0]);
    }
}
