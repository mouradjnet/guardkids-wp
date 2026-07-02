<?php

declare(strict_types=1);

/**
 * Migration 018 — economia/progressão (gamificação 3a).
 *
 * `progression` = carteira/nível por filho; `progression_awards` = ledger
 * anti-farm (um ganho por conteúdo/dia via UNIQUE).
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $progression = $wpdb->prefix . 'guardkids_progression';
    $awards      = $wpdb->prefix . 'guardkids_progression_awards';

    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS {$progression} (
            id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id           BIGINT UNSIGNED NOT NULL,
            xp                 INT  NOT NULL DEFAULT 0,
            coins              INT  NOT NULL DEFAULT 0,
            streak_days        INT  NOT NULL DEFAULT 0,
            last_activity_date DATE NULL,
            created_at         DATETIME NOT NULL,
            updated_at         DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY child_unq (child_id)
        ) {$charsetCollate};"
    );

    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS {$awards} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id   BIGINT UNSIGNED NOT NULL,
            content_id BIGINT UNSIGNED NOT NULL,
            award_date DATE NOT NULL,
            xp         INT  NOT NULL DEFAULT 0,
            coins      INT  NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY once_per_day (child_id, content_id, award_date),
            KEY child (child_id)
        ) {$charsetCollate};"
    );
};
