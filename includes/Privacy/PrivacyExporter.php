<?php

declare(strict_types=1);

namespace GuardKids\Privacy;

/**
 * Agrega todas as tabelas do plugin num array exportável (download JSON).
 * Omite as keys reservadas de token de `settings` (`child_token:*`,
 * `companion_token:*`) — mesmo critério do SettingsController.
 */
final class PrivacyExporter
{
    private const TABLES = [
        'children', 'categories', 'requests', 'sites', 'settings',
        'usage_events', 'locations', 'safe_zones', 'guardians',
        'guardian_invites', 'companion_devices',
    ];

    private readonly \wpdb $db;

    public function __construct(?\wpdb $db = null)
    {
        if ($db === null) {
            global $wpdb;
            $db = $wpdb;
        }
        $this->db = $db;
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $tables = [];
        foreach (self::TABLES as $suffix) {
            $rows = $this->db->get_results(
                'SELECT * FROM ' . $this->db->prefix . 'guardkids_' . $suffix,
                ARRAY_A,
            );
            $rows = is_array($rows) ? $rows : [];
            if ($suffix === 'settings') {
                $rows = array_values(array_filter(
                    $rows,
                    static fn (array $r): bool => ! str_contains((string) ($r['setting_key'] ?? ''), ':'),
                ));
            }
            $tables[$suffix] = $rows;
        }

        return [
            'exported_at' => gmdate('c'),
            'site_url'    => \home_url(),
            'version'     => defined('GUARDKIDS_VERSION') ? GUARDKIDS_VERSION : 'unknown',
            'tables'      => $tables,
        ];
    }
}
