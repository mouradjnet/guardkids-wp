<?php

declare(strict_types=1);

namespace GuardKids\Database;

/**
 * Key-value store das settings do plugin (JSON em `value`).
 */
final class SettingsRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'settings';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $sql = $this->db->prepare(
            'SELECT value FROM ' . $this->table() . ' WHERE setting_key = %s LIMIT 1',
            $key,
        );
        $raw = $this->db->get_var($sql);
        if ($raw === null) {
            return $default;
        }
        $decoded = json_decode((string) $raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $payload = wp_json_encode($value);
        if ($payload === false) {
            return;
        }
        $now = current_time('mysql', true);

        $existing = $this->db->get_var(
            $this->db->prepare(
                'SELECT id FROM ' . $this->table() . ' WHERE setting_key = %s LIMIT 1',
                $key,
            ),
        );

        if ($existing === null) {
            $this->db->insert($this->table(), [
                'setting_key' => $key,
                'value'       => $payload,
                'updated_at'  => $now,
            ]);
            return;
        }
        $this->db->update(
            $this->table(),
            ['value' => $payload, 'updated_at' => $now],
            ['id' => (int) $existing],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $rows = $this->db->get_results('SELECT setting_key, value FROM ' . $this->table(), ARRAY_A);
        if (! is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) ($row['value'] ?? ''), true);
            $out[$row['setting_key']] = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }
        return $out;
    }
}
