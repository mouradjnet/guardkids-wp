<?php

declare(strict_types=1);

/**
 * Migration 024 — Web Push do guardião: subscriptions + dedupe por evento.
 *
 * Tabelas paralelas às da criança (015). Não toca em push_subscriptions: o
 * caminho de push da criança já roda em produção e fica intacto.
 *
 * $wpdb->query direto, nunca dbDelta — dbDelta já causou no-op silencioso em
 * produção na migration 003.
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $subs  = $wpdb->prefix . 'guardkids_guardian_push_subscriptions';
    $dedup = $wpdb->prefix . 'guardkids_guardian_push_dedup';

    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS {$subs} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT UNSIGNED NOT NULL,
            endpoint   VARCHAR(512) NOT NULL,
            p256dh     VARCHAR(255) NOT NULL,
            auth       VARCHAR(255) NOT NULL,
            created_at DATETIME     NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY endpoint_unq (endpoint(191)),
            KEY wp_user (wp_user_id)
        ) {$charsetCollate};"
    );

    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS {$dedup} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            dedup_key  VARCHAR(191) NOT NULL,
            created_at DATETIME     NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY dedup_unq (dedup_key)
        ) {$charsetCollate};"
    );
};
