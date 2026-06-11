<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration\Repository;

use GuardKids\Database\SettingsRepository;
use GuardKids\Tests\Integration\IntegrationTestCase;

/**
 * Valida SettingsRepository (key-value JSON store) contra MySQL real.
 *
 * Foco: upsert manual via SELECT id + insert/update, JSON encode/decode,
 * fail-closed do isLocationEnabled, all() retornando assoc.
 */
final class SettingsRepositoryTest extends IntegrationTestCase
{
    public function test_get_returns_default_when_key_missing(): void
    {
        $repo = new SettingsRepository();
        $this->assertSame('fallback', $repo->get('missing', 'fallback'));
        $this->assertNull($repo->get('missing'));
    }

    public function test_set_then_get_round_trips_scalar(): void
    {
        $repo = new SettingsRepository();
        $repo->set('upgrade_url', 'https://example.com/upgrade');
        $this->assertSame('https://example.com/upgrade', $repo->get('upgrade_url'));
    }

    public function test_set_then_get_round_trips_array(): void
    {
        $repo = new SettingsRepository();
        $repo->set('feature_flags', ['premium' => true, 'beta' => false]);

        $this->assertSame(['premium' => true, 'beta' => false], $repo->get('feature_flags'));
    }

    public function test_set_upserts_existing_key(): void
    {
        $repo = new SettingsRepository();
        $repo->set('counter', 1);
        $repo->set('counter', 42);
        $repo->set('counter', 100);

        $this->assertSame(100, $repo->get('counter'));

        // Confirma que só tem uma linha (upsert, não duplica).
        $count = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM `{$this->db->prefix}guardkids_settings` WHERE setting_key = 'counter'",
        );
        $this->assertSame(1, $count);
    }

    public function test_isLocationEnabled_fails_closed_when_unset(): void
    {
        $repo = new SettingsRepository();
        $this->assertFalse($repo->isLocationEnabled());
    }

    public function test_isLocationEnabled_returns_true_when_explicitly_enabled(): void
    {
        $repo = new SettingsRepository();
        $repo->set('location_enabled', true);
        $this->assertTrue($repo->isLocationEnabled());
    }

    public function test_isLocationEnabled_returns_false_when_explicitly_disabled(): void
    {
        $repo = new SettingsRepository();
        $repo->set('location_enabled', false);
        $this->assertFalse($repo->isLocationEnabled());
    }

    public function test_all_returns_assoc_of_decoded_values(): void
    {
        $repo = new SettingsRepository();
        $repo->set('a', 'string-val');
        $repo->set('b', 42);
        $repo->set('c', ['nested' => true]);

        $all = $repo->all();
        $this->assertSame('string-val', $all['a']);
        $this->assertSame(42, $all['b']);
        $this->assertSame(['nested' => true], $all['c']);
    }

    public function test_unique_constraint_on_setting_key_enforced(): void
    {
        $repo = new SettingsRepository();
        $repo->set('uniq', 'first');
        $repo->set('uniq', 'second'); // upsert, não duplica

        $count = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM `{$this->db->prefix}guardkids_settings`",
        );
        $this->assertSame(1, $count);
        $this->assertSame('second', $repo->get('uniq'));
    }
}
