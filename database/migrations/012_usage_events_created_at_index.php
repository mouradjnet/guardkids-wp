<?php

declare(strict_types=1);

/**
 * Migration 012 — índice standalone em created_at de usage_events.
 *
 * A view de Relatórios "todos os filhos" (sem child_id) filtra só por
 * created_at, mas o único índice com created_at é o composto
 * `child_day (child_id, created_at)` — inútil sem o líder child_id → full scan
 * (kpisForRange ×2, aggregateDailyMinutes, topDomains). Este índice cobre a
 * janela rolling da view padrão.
 *
 * ALTER via query direto (dbDelta não aplica ALTER de forma confiável — mesmo
 * padrão das migrations 003/006/009/010/011).
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $table = $wpdb->prefix . 'guardkids_usage_events';

    $wpdb->query("ALTER TABLE {$table} ADD KEY created_at (created_at);");
};
