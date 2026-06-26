<?php

declare(strict_types=1);

/**
 * Migration 013 — coluna installed_apps em companion_devices.
 *
 * Persiste a lista de apps instalados reportada pelo device (JSON array de
 * {packageName,label}), pro painel renderizar o picker de bloqueio por-app
 * entre syncs.
 *
 * ALTER direto (dbDelta não aplica ALTER confiável — padrão 003/006/009-012).
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $table = $wpdb->prefix . 'guardkids_companion_devices';

    $wpdb->query("ALTER TABLE {$table} ADD COLUMN installed_apps TEXT NULL;");
};
