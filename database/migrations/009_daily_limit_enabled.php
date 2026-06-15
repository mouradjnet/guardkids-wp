<?php

declare(strict_types=1);

/**
 * Migration 009 — toggle de enforcement do limite diário em children.
 *
 * - daily_limit_enabled TINYINT(1) NOT NULL DEFAULT 0 — quando 1, o
 *   ScheduleEvaluator bloqueia o filho ao atingir `limit_minutes` de uso no
 *   dia local. Default 0 (opt-in): famílias já em produção, que têm
 *   `limit_minutes=60` padrão, NÃO passam a ser bloqueadas sem ativar.
 *
 * Idempotência via MigrationRunner; dbDelta não aplica ALTER TABLE de forma
 * confiável (mesmo padrão das migrations 003 e 006).
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $table = $wpdb->prefix . 'guardkids_children';

    $wpdb->query("ALTER TABLE {$table}
        ADD COLUMN daily_limit_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER limit_minutes;");
};
