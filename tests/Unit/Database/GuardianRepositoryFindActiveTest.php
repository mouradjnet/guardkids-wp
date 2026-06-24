<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\GuardianRepository;
use PHPUnit\Framework\TestCase;

final class GuardianRepositoryFindActiveTest extends TestCase
{
    public function testFindActiveFiltersByActiveStatus(): void
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
                $this->queries[] = (string) $query;
                return (string) $query;
            }

            public function get_results($query = null, $output = ARRAY_A)
            {
                return [['id' => 1, 'email' => 'a@b.com', 'status' => 'active']];
            }
        };
        $GLOBALS['wpdb'] = $wpdb;

        $rows = (new GuardianRepository())->findActive();

        self::assertCount(1, $rows);
        self::assertSame('a@b.com', $rows[0]['email']);
        self::assertStringContainsString('status', $wpdb->queries[0]);
    }
}
