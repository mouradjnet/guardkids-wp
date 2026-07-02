<?php

declare(strict_types=1);

/**
 * Migration 015 — subscriptions de Web Push do app-filho.
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $table = $wpdb->prefix . 'guardkids_push_subscriptions';

    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS {$table} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id   BIGINT UNSIGNED NOT NULL,
            endpoint   VARCHAR(512) NOT NULL,
            p256dh     VARCHAR(255) NOT NULL,
            auth       VARCHAR(255) NOT NULL,
            created_at DATETIME     NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY endpoint_unq (endpoint(191)),
            KEY child (child_id)
        ) {$charsetCollate};"
    );
};
