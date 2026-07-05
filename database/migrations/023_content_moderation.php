<?php

declare(strict_types=1);

/**
 * Migration 023 — Moderação de conteúdo. Adiciona status/approved_by/approved_at
 * em content_items e faz grandfather do conteúdo existente (status='approved'),
 * pra que nada que já está no ar suma da vista das crianças. ADD COLUMN não é
 * idempotente → guard addColumnIfMissing.
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $p = $wpdb->prefix . 'guardkids_';

    $addColumnIfMissing = static function (string $table, string $col, string $def) use ($wpdb): void {
        $found = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $col));
        if ($found === null) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
        }
    };

    $addColumnIfMissing($p . 'content_items', 'status', "VARCHAR(20) NOT NULL DEFAULT 'pending'");
    $addColumnIfMissing($p . 'content_items', 'approved_by', 'BIGINT UNSIGNED NULL');
    $addColumnIfMissing($p . 'content_items', 'approved_at', 'DATETIME NULL');

    // Grandfather: todo conteúdo já existente vira approved (senão sumiria da
    // biblioteca das crianças). Roda uma vez só no salto 22->23; conteúdo novo
    // criado 'pending' pela app é DEPOIS e nunca é pego aqui.
    $now = current_time('mysql', true);
    $wpdb->query($wpdb->prepare(
        "UPDATE {$p}content_items SET status = 'approved', approved_at = %s WHERE status = 'pending'",
        $now,
    ));
};
