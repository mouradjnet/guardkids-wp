<?php

declare(strict_types=1);

/**
 * Migration 014 — tabela de notificações in-app do app-filho.
 *
 * Fundação do sistema de notificações (fase 1 de push). Append-mostly:
 * só read_at muda depois da criação. dedup_key dá idempotência por janela/evento.
 *
 * CREATE TABLE via $wpdb->query com IF NOT EXISTS (idempotente).
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $table = $wpdb->prefix . 'guardkids_notifications';

    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS {$table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id    BIGINT UNSIGNED NOT NULL,
            type        VARCHAR(32)     NOT NULL,
            title       VARCHAR(160)    NOT NULL,
            body        VARCHAR(255)    NULL,
            dedup_key   VARCHAR(191)    NULL,
            read_at     DATETIME        NULL,
            created_at  DATETIME        NOT NULL,
            PRIMARY KEY  (id),
            KEY child_created (child_id, created_at),
            UNIQUE KEY child_dedup (child_id, dedup_key)
        ) {$charsetCollate};"
    );
};
