<?php

declare(strict_types=1);

namespace GuardKids\Privacy;

use GuardKids\Auth\ChildAuth;
use GuardKids\Database\Schema;

/**
 * Apaga os dados de UMA criança ao excluir o perfil dela: linhas em todas as
 * tabelas com `child_id` ({@see Schema::CHILD_TABLES}) e os tokens de
 * dispositivo. Sem FKs no schema, ninguém mais limpa esses órfãos.
 */
final class ChildDataEraser
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
     * @return array<string, int> linhas removidas por tabela (+ `device_tokens`).
     */
    public function erase(int $childId): array
    {
        $summary = [];
        foreach (Schema::CHILD_TABLES as $suffix) {
            $table  = $this->db->prefix . 'guardkids_' . $suffix;
            $result = $this->db->query(
                $this->db->prepare('DELETE FROM ' . $table . ' WHERE child_id = %d', $childId),
            );
            $summary[$suffix] = is_numeric($result) ? (int) $result : 0;
        }
        $summary['device_tokens'] = (new ChildAuth())->revokeForChild($childId);
        return $summary;
    }
}
