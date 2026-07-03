<?php

declare(strict_types=1);

/**
 * Migration 020 — medalhas permanentes (gamificação 3c).
 *
 * `medal_unlocks` = ledger de desbloqueio. UNIQUE por (filho, medalha) SEM
 * data — medalha desbloqueia uma vez pra sempre.
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $table = $wpdb->prefix . 'guardkids_medal_unlocks';

    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS {$table} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id      BIGINT UNSIGNED NOT NULL,
            medal_key     VARCHAR(40) NOT NULL,
            unlocked_date DATE NOT NULL,
            xp            INT NOT NULL DEFAULT 0,
            coins         INT NOT NULL DEFAULT 0,
            created_at    DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY once_per_medal (child_id, medal_key),
            KEY child (child_id)
        ) {$charsetCollate};"
    );
};
