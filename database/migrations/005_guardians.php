<?php

declare(strict_types=1);

/**
 * Migration 005 — tabela guardians (família que administra a conta).
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $table = $wpdb->prefix . 'guardkids_guardians';

    $sql = "CREATE TABLE {$table} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        wp_user_id  BIGINT UNSIGNED NULL,
        name        VARCHAR(120)    NOT NULL,
        email       VARCHAR(190)    NOT NULL,
        role        VARCHAR(20)     NOT NULL DEFAULT 'collaborator',
        status      VARCHAR(16)     NOT NULL DEFAULT 'pending',
        created_at  DATETIME        NOT NULL,
        updated_at  DATETIME        NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY email (email),
        KEY wp_user_id (wp_user_id),
        KEY status (status)
    ) {$charsetCollate};";

    dbDelta($sql);
};
