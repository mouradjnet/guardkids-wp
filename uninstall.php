<?php

declare(strict_types=1);

/**
 * GuardKids WP — uninstall.
 *
 * Roda quando o usuário clica em "Excluir" o plugin no wp-admin (não em
 * desativação). Drop das tabelas e limpeza das opções persistentes.
 *
 * A lista de tabelas vem de database/Schema.php (fonte única, testada contra
 * as migrations). Require direto: o WP não carrega o plugin no uninstall.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/database/Schema.php';

global $wpdb;

foreach (\GuardKids\Database\Schema::TABLES as $suffix) {
    $table = $wpdb->prefix . 'guardkids_' . $suffix;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

delete_option('guardkids_db_version');
delete_option('guardkids_license');
delete_option('guardkids_license_revoked');
delete_option('guardkids_anthropic_key');
delete_transient('gk_revoked_jti');
