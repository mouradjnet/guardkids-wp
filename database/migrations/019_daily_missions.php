<?php

declare(strict_types=1);

/**
 * Migration 019 — missões diárias (gamificação 3b).
 *
 * `mission_completions` = ledger anti-duplo de conclusão. Uma linha por
 * (filho, missão, dia) via UNIQUE — impede creditar o bônus duas vezes.
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $table = $wpdb->prefix . 'guardkids_mission_completions';

    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS {$table} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id        BIGINT UNSIGNED NOT NULL,
            mission_key     VARCHAR(40) NOT NULL,
            completion_date DATE NOT NULL,
            xp              INT NOT NULL DEFAULT 0,
            coins           INT NOT NULL DEFAULT 0,
            created_at      DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY once_per_day (child_id, mission_key, completion_date),
            KEY child (child_id)
        ) {$charsetCollate};"
    );
};
