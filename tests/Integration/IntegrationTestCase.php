<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Base para integration tests.
 *
 * Antes de cada teste, faz TRUNCATE em todas as tabelas guardkids — schema fica,
 * dados zeram. O bootstrap já garantiu que migrations rodaram uma vez.
 */
abstract class IntegrationTestCase extends TestCase
{
    /** @var list<string> */
    protected const TABLES = [
        'children',
        'requests',
        'sites',
        'categories',
        'settings',
        'usage_events',
        'locations',
        'safe_zones',
    ];

    protected \wpdb $db;

    protected function setUp(): void
    {
        global $wpdb;
        $this->db = $wpdb;

        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::TABLES as $table) {
            $this->db->query("TRUNCATE TABLE `{$this->db->prefix}guardkids_{$table}`");
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
