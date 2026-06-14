<?php

declare(strict_types=1);

/**
 * Migration 008 — tabela de dispositivos pareados via GuardKids Companion.
 *
 * v1.5.0: prepara a estrutura para o Modo Proteção Máxima. O Companion
 * Android (ainda não implementado nesta versão) será o agente nativo que
 * reporta para esta tabela via REST.
 *
 * Campos essenciais (briefing):
 *   - child_id, device_uuid, device_name, android_version, companion_version
 *   - 4 flags de permissão (device_owner_enabled, accessibility_enabled,
 *     device_admin_enabled, plus play_store_enabled)
 *   - last_sync, status, timestamps
 *
 * Campos preparados para regras futuras (briefing "Preparar estruturas futuras"):
 *   - allowed_apps / blocked_apps  — JSON arrays
 *   - settings_locked, kiosk_mode, device_shutdown_protection — flags
 *
 * Idempotência via MigrationRunner (version tracking).
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $table = $wpdb->prefix . 'guardkids_companion_devices';

    $sql = "CREATE TABLE {$table} (
        id                            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        child_id                      BIGINT UNSIGNED NOT NULL,
        device_uuid                   VARCHAR(64)     NOT NULL,
        device_name                   VARCHAR(120)    NULL,
        android_version               VARCHAR(20)     NULL,
        companion_version             VARCHAR(20)     NULL,
        device_owner_enabled          TINYINT(1)      NOT NULL DEFAULT 0,
        accessibility_enabled         TINYINT(1)      NOT NULL DEFAULT 0,
        device_admin_enabled          TINYINT(1)      NOT NULL DEFAULT 0,
        play_store_enabled            TINYINT(1)      NOT NULL DEFAULT 1,
        settings_locked               TINYINT(1)      NOT NULL DEFAULT 0,
        kiosk_mode                    TINYINT(1)      NOT NULL DEFAULT 0,
        device_shutdown_protection    TINYINT(1)      NOT NULL DEFAULT 0,
        allowed_apps                  TEXT            NULL,
        blocked_apps                  TEXT            NULL,
        last_sync                     DATETIME        NULL,
        status                        VARCHAR(20)     NOT NULL DEFAULT 'pending',
        created_at                    DATETIME        NOT NULL,
        updated_at                    DATETIME        NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY device_uuid (device_uuid),
        KEY child_id (child_id),
        KEY status (status)
    ) {$charsetCollate};";

    dbDelta($sql);
};
