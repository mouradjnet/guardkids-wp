<?php

declare(strict_types=1);

/**
 * Migration 026 — Localização Inteligente por Endereço.
 *
 * safe_zones ganha `icon` (emoji opcional do Local). child_place guarda o
 * estado de geofencing por filho (local atual confirmado + candidato pendente).
 *
 * $wpdb->query direto (nunca dbDelta — no-op silencioso na 003). ALTER guardado
 * por SHOW COLUMNS pra ser idempotente mesmo se rodar de novo.
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $zones = $wpdb->prefix . 'guardkids_safe_zones';
    $place = $wpdb->prefix . 'guardkids_child_place';

    $hasIcon = $wpdb->get_var(
        $wpdb->prepare('SHOW COLUMNS FROM ' . $zones . ' LIKE %s', 'icon')
    );
    if ($hasIcon === null) {
        $wpdb->query("ALTER TABLE {$zones} ADD COLUMN icon VARCHAR(32) NULL AFTER name;");
    }

    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS {$place} (
            child_id        BIGINT UNSIGNED   NOT NULL,
            current_zone_id BIGINT UNSIGNED   NULL,
            current_since   DATETIME          NULL,
            pending_zone_id BIGINT UNSIGNED   NULL,
            pending_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            pending_since   DATETIME          NULL,
            updated_at      DATETIME          NOT NULL,
            PRIMARY KEY (child_id)
        ) {$charsetCollate};"
    );
};
