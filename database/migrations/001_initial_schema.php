<?php

declare(strict_types=1);

/**
 * Migration 001 — schema inicial GuardKids.
 *
 * Cria 5 tabelas: children, requests, sites, categories, settings.
 * Executada por GuardKids\Database\MigrationRunner.
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $prefix = $wpdb->prefix . 'guardkids_';

    $children = "CREATE TABLE {$prefix}children (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug          VARCHAR(64)     NOT NULL,
        name          VARCHAR(120)    NOT NULL,
        age           TINYINT UNSIGNED NULL,
        avatar_url    TEXT            NULL,
        device        VARCHAR(120)    NULL,
        status        VARCHAR(16)     NOT NULL DEFAULT 'offline',
        used_minutes  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        limit_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
        created_at    DATETIME        NOT NULL,
        updated_at    DATETIME        NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY slug (slug),
        KEY status (status)
    ) {$charsetCollate};";

    $requests = "CREATE TABLE {$prefix}requests (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        child_id      BIGINT UNSIGNED NOT NULL,
        kind          VARCHAR(32)     NOT NULL,
        description   VARCHAR(255)    NULL,
        highlight     VARCHAR(255)    NULL,
        reason        TEXT            NULL,
        status        VARCHAR(16)     NOT NULL DEFAULT 'pending',
        decided_at    DATETIME        NULL,
        decided_by    BIGINT UNSIGNED NULL,
        created_at    DATETIME        NOT NULL,
        updated_at    DATETIME        NOT NULL,
        PRIMARY KEY  (id),
        KEY child_id (child_id),
        KEY status (status),
        KEY created_at (created_at)
    ) {$charsetCollate};";

    $sites = "CREATE TABLE {$prefix}sites (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        domain      VARCHAR(255)    NOT NULL,
        category    VARCHAR(64)     NULL,
        list_type   VARCHAR(16)     NOT NULL DEFAULT 'whitelist',
        applies_to  TEXT            NULL,
        created_at  DATETIME        NOT NULL,
        updated_at  DATETIME        NOT NULL,
        PRIMARY KEY  (id),
        KEY domain_list (domain(191), list_type),
        KEY list_type (list_type)
    ) {$charsetCollate};";

    $categories = "CREATE TABLE {$prefix}categories (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug        VARCHAR(64)     NOT NULL,
        name        VARCHAR(120)    NOT NULL,
        description TEXT            NULL,
        icon        VARCHAR(64)     NULL,
        blocked     TINYINT(1)      NOT NULL DEFAULT 0,
        created_at  DATETIME        NOT NULL,
        updated_at  DATETIME        NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY slug (slug)
    ) {$charsetCollate};";

    $settings = "CREATE TABLE {$prefix}settings (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        setting_key VARCHAR(120)    NOT NULL,
        value       LONGTEXT        NULL,
        updated_at  DATETIME        NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY setting_key (setting_key)
    ) {$charsetCollate};";

    dbDelta($children);
    dbDelta($requests);
    dbDelta($sites);
    dbDelta($categories);
    dbDelta($settings);
};
