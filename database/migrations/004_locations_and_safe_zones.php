<?php

declare(strict_types=1);

/**
 * Migration 004 — locations + safe_zones.
 *
 * locations: append-only (sem updated_at). recorded_at é server-set no controller.
 * safe_zones: zonas globais (uma "Casa" vale pra todos os filhos nesta fase).
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $prefix = $wpdb->prefix . 'guardkids_';

    $locations = "CREATE TABLE {$prefix}locations (
        id          BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
        child_id    BIGINT UNSIGNED   NOT NULL,
        latitude    DECIMAL(10,7)     NOT NULL,
        longitude   DECIMAL(10,7)     NOT NULL,
        accuracy    SMALLINT UNSIGNED NULL,
        battery     TINYINT UNSIGNED  NULL,
        recorded_at DATETIME          NOT NULL,
        created_at  DATETIME          NOT NULL,
        PRIMARY KEY  (id),
        KEY child_recorded (child_id, recorded_at)
    ) {$charsetCollate};";

    $safeZones = "CREATE TABLE {$prefix}safe_zones (
        id            BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
        name          VARCHAR(120)      NOT NULL,
        address       VARCHAR(255)      NULL,
        latitude      DECIMAL(10,7)     NOT NULL,
        longitude     DECIMAL(10,7)     NOT NULL,
        radius_meters SMALLINT UNSIGNED NOT NULL DEFAULT 100,
        created_at    DATETIME          NOT NULL,
        updated_at    DATETIME          NOT NULL,
        PRIMARY KEY  (id),
        KEY name (name)
    ) {$charsetCollate};";

    dbDelta($locations);
    dbDelta($safeZones);
};
