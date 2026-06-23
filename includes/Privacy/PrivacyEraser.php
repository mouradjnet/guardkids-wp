<?php

declare(strict_types=1);

namespace GuardKids\Privacy;

/**
 * Apaga os dados da família (ação "Excluir conta"). Preserva `guardians`,
 * `guardian_invites`, usuários WP e a licença (`wp_options.guardkids_license`).
 * O plugin continua ativo e pronto pra recomeçar do zero.
 */
final class PrivacyEraser
{
    /** Tabelas zeradas. `guardians`/`guardian_invites` ficam de fora de propósito. */
    private const TABLES = [
        'children', 'categories', 'requests', 'sites',
        'usage_events', 'locations', 'safe_zones', 'companion_devices',
        'settings',
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
     * @return array<string, int> linhas removidas por tabela.
     */
    public function wipeAll(): array
    {
        $summary = [];
        foreach (self::TABLES as $suffix) {
            $table  = $this->db->prefix . 'guardkids_' . $suffix;
            $result = $this->db->query('DELETE FROM ' . $table);
            $summary[$suffix] = is_numeric($result) ? (int) $result : 0;
        }
        return $summary;
    }
}
