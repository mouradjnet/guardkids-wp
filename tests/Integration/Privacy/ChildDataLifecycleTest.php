<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration\Privacy;

use GuardKids\Auth\ChildAuth;
use GuardKids\Auth\ChildPin;
use GuardKids\Database\Schema;
use GuardKids\Privacy\ChildDataEraser;
use GuardKids\Privacy\PrivacyEraser;
use GuardKids\Tests\Integration\IntegrationTestCase;

/**
 * Exclusão de dados e bloqueio do PIN contra MySQL real: as listas do Schema
 * batem com as tabelas que existem e o UPDATE do contador grava de verdade.
 */
final class ChildDataLifecycleTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // child_place não está no TRUNCATE da base.
        $this->db->query("DELETE FROM {$this->db->prefix}guardkids_child_place");
    }

    public function test_every_schema_table_exists_after_migrations(): void
    {
        foreach (Schema::TABLES as $suffix) {
            $table = $this->db->prefix . 'guardkids_' . $suffix;
            $this->assertNotFalse(
                $this->db->query("SELECT 1 FROM `{$table}` LIMIT 0"),
                "tabela {$table} não existe",
            );
        }
    }

    public function test_every_child_table_accepts_delete_by_child_id(): void
    {
        foreach (Schema::CHILD_TABLES as $suffix) {
            $table = $this->db->prefix . 'guardkids_' . $suffix;
            $this->assertNotFalse(
                $this->db->query("DELETE FROM `{$table}` WHERE child_id = 0"),
                "{$table} sem coluna child_id",
            );
        }
    }

    public function test_erase_removes_only_that_childs_rows_and_tokens(): void
    {
        $p   = $this->db->prefix . 'guardkids_';
        $now = '2026-11-10 12:00:00';
        foreach ([7, 8] as $child) {
            $this->db->query("INSERT INTO {$p}locations (child_id, latitude, longitude, recorded_at, created_at) VALUES ({$child}, -23.5, -46.6, '{$now}', '{$now}')");
            $this->db->query("INSERT INTO {$p}usage_events (child_id, type, created_at) VALUES ({$child}, 'heartbeat', '{$now}')");
            $this->db->query("INSERT INTO {$p}child_place (child_id, updated_at) VALUES ({$child}, '{$now}')");
        }
        $auth  = new ChildAuth();
        $mine  = $auth->issueToken(7)['token'];
        $other = $auth->issueToken(8)['token'];

        $summary = (new ChildDataEraser())->erase(7);

        foreach (['locations', 'usage_events', 'child_place'] as $suffix) {
            $this->assertSame(1, $summary[$suffix], $suffix);
            $this->assertSame('0', (string) $this->db->get_var("SELECT COUNT(*) FROM {$p}{$suffix} WHERE child_id = 7"));
            $this->assertSame('1', (string) $this->db->get_var("SELECT COUNT(*) FROM {$p}{$suffix} WHERE child_id = 8"));
        }
        $this->assertSame(1, $summary['device_tokens']);
        $this->assertSame([8], $auth->pairedChildIds());
        $this->assertNotSame('', $mine);
        $this->assertNotSame('', $other);
    }

    public function test_privacy_eraser_runs_on_every_erasable_table(): void
    {
        $summary = (new PrivacyEraser())->wipeAll();

        $this->assertSame(Schema::erasableTables(), array_keys($summary));
    }

    public function test_pin_locks_after_failures_with_real_settings_updates(): void
    {
        $pin = new ChildPin();
        $pin->set('1234');

        for ($i = 0; $i < ChildPin::MAX_FAILURES; $i++) {
            $this->assertFalse($pin->verify('0000'));
        }

        $this->assertGreaterThan(0, $pin->lockedSeconds());
        $this->assertFalse($pin->verify('1234'));
    }
}
