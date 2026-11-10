<?php

declare(strict_types=1);

namespace GuardKids\Privacy;

use GuardKids\Database\Schema;

/**
 * Apaga os dados da família (ação "Excluir conta"). Preserva o que está em
 * {@see Schema::KEPT_ON_ERASE} (guardiões e catálogo de conteúdo), usuários WP
 * e a licença (`wp_options.guardkids_license`).
 * O plugin continua ativo e pronto pra recomeçar do zero.
 */
final class PrivacyEraser
{
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
        foreach (Schema::erasableTables() as $suffix) {
            $table  = $this->db->prefix . 'guardkids_' . $suffix;
            $result = $this->db->query('DELETE FROM ' . $table);
            $summary[$suffix] = is_numeric($result) ? (int) $result : 0;
        }
        return $summary;
    }
}
