<?php

declare(strict_types=1);

/**
 * Migration 002 — tabela de eventos de uso.
 *
 * Append-only: heartbeat (tempo de tela no PWA) e site_open (clique de atalho).
 * Agregação rola no read em ReportsController via SUM/GROUP BY.
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $table = $wpdb->prefix . 'guardkids_usage_events';

    $sql = "CREATE TABLE {$table} (
        id               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        child_id         BIGINT UNSIGNED  NOT NULL,
        type             VARCHAR(20)      NOT NULL,
        domain           VARCHAR(191)     NULL,
        duration_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        created_at       DATETIME         NOT NULL,
        PRIMARY KEY  (id),
        KEY child_day (child_id, created_at),
        KEY child_domain (child_id, domain)
    ) {$charsetCollate};";

    dbDelta($sql);
};
