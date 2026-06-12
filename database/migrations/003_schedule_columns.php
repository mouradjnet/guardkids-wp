<?php

declare(strict_types=1);

/**
 * Migration 003 — schedule (bedtime + allowed_weekdays) em children.
 *
 * Adiciona 4 colunas em wp_guardkids_children:
 *   - bedtime_start TIME NULL
 *   - bedtime_end   TIME NULL
 *   - bedtime_enabled TINYINT(1) NOT NULL DEFAULT 0
 *   - allowed_weekdays CHAR(7) NOT NULL DEFAULT 'YYYYYYY' (pos 0 = Mon)
 *
 * Idempotência garantida pelo MigrationRunner (version tracking).
 * Usa $wpdb->query direto — dbDelta NÃO aplica ALTER TABLE de forma
 * confiável (silenciosamente vira no-op em prod). Mesmo padrão da
 * migration 006.
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $table = $wpdb->prefix . 'guardkids_children';

    $wpdb->query("ALTER TABLE {$table}
        ADD COLUMN bedtime_start    TIME       NULL                        AFTER limit_minutes,
        ADD COLUMN bedtime_end      TIME       NULL                        AFTER bedtime_start,
        ADD COLUMN bedtime_enabled  TINYINT(1) NOT NULL DEFAULT 0          AFTER bedtime_end,
        ADD COLUMN allowed_weekdays CHAR(7)    NOT NULL DEFAULT 'YYYYYYY'  AFTER bedtime_enabled;");
};
