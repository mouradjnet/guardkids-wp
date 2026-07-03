<?php

declare(strict_types=1);

/**
 * Migration 021 — recompensas (gamificação 3d).
 *
 * `rewards` = catálogo global editável pelos pais. `reward_redemptions` =
 * pedidos de resgate (espelha `requests`), com snapshot do custo.
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $rewards     = $wpdb->prefix . 'guardkids_rewards';
    $redemptions = $wpdb->prefix . 'guardkids_reward_redemptions';

    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS {$rewards} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title      VARCHAR(120) NOT NULL,
            cost_coins INT UNSIGNED NOT NULL,
            icon       VARCHAR(40) NULL,
            active     TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY active (active)
        ) {$charsetCollate};"
    );

    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS {$redemptions} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id   BIGINT UNSIGNED NOT NULL,
            reward_id  BIGINT UNSIGNED NOT NULL,
            cost_coins INT UNSIGNED NOT NULL,
            status     VARCHAR(16) NOT NULL DEFAULT 'pending',
            decided_at DATETIME NULL,
            decided_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY child_id (child_id),
            KEY status (status)
        ) {$charsetCollate};"
    );
};
