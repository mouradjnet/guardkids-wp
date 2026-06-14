<?php

declare(strict_types=1);

/**
 * Migration 007 — coluna detail em usage_events.
 *
 * Guarda motivo do bloqueio (bedtime/weekly/limit) pros eventos
 * schedule_block. NULL pra heartbeat e site_open.
 *
 * Usa $wpdb->query direto — dbDelta NÃO aplica ALTER TABLE de forma
 * confiável (silenciosamente vira no-op em prod). Mesmo padrão das
 * migrations 003 e 006.
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $table = $wpdb->prefix . 'guardkids_usage_events';

    $wpdb->query("ALTER TABLE {$table}
        ADD COLUMN detail VARCHAR(120) NULL AFTER domain;");
};
