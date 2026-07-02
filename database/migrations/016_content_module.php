<?php

declare(strict_types=1);

/**
 * Migration 016 — módulo Mundo Guardião (infra). 5 tabelas content_*.
 * Nomeadas com prefixo content_ pra não colidir com guardkids_categories
 * (categorias de sites, já existente).
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $p = $wpdb->prefix . 'guardkids_';

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}content_categories (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(64) NOT NULL,
        name VARCHAR(120) NOT NULL,
        icon VARCHAR(48) NULL,
        description VARCHAR(255) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY slug_unq (slug)
    ) {$charsetCollate};");

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}content_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        category_id BIGINT UNSIGNED NULL,
        title VARCHAR(160) NOT NULL,
        description VARCHAR(255) NULL,
        url VARCHAR(512) NULL,
        type VARCHAR(32) NOT NULL DEFAULT 'link',
        thumbnail VARCHAR(512) NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY category (category_id)
    ) {$charsetCollate};");

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}content_favorites (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        child_id BIGINT UNSIGNED NOT NULL,
        content_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY child_content (child_id, content_id),
        KEY child (child_id)
    ) {$charsetCollate};");

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}content_recommendations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        child_id BIGINT UNSIGNED NOT NULL,
        content_id BIGINT UNSIGNED NOT NULL,
        guardian_id BIGINT UNSIGNED NULL,
        note VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY child (child_id)
    ) {$charsetCollate};");

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}content_history (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        child_id BIGINT UNSIGNED NOT NULL,
        content_id BIGINT UNSIGNED NOT NULL,
        action VARCHAR(32) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY child_created (child_id, created_at)
    ) {$charsetCollate};");
};
