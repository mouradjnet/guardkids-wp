<?php

declare(strict_types=1);

/**
 * GuardKids WP — uninstall.
 *
 * Roda quando o usuário clica em "Excluir" o plugin no wp-admin (não em
 * desativação). Drop das tabelas e limpeza das opções persistentes.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

$tables = [
    $wpdb->prefix . 'guardkids_children',
    $wpdb->prefix . 'guardkids_requests',
    $wpdb->prefix . 'guardkids_sites',
    $wpdb->prefix . 'guardkids_categories',
    $wpdb->prefix . 'guardkids_settings',
    $wpdb->prefix . 'guardkids_usage_events',
    $wpdb->prefix . 'guardkids_locations',
    $wpdb->prefix . 'guardkids_safe_zones',
    $wpdb->prefix . 'guardkids_guardians',
    $wpdb->prefix . 'guardkids_companion_devices',
    $wpdb->prefix . 'guardkids_notifications',
];

foreach ($tables as $table) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

delete_option('guardkids_db_version');
delete_option('guardkids_license');
delete_option('guardkids_license_revoked');
