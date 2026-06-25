<?php

declare(strict_types=1);

/**
 * Migration 011 — expiração do token de sessão do Companion (janela deslizante).
 *
 * enroll/sync gravam `session_expires_at = now + 30d`; authenticateSession
 * rejeita expirado. NULL = device legado (aceito até a próxima sync gravar o
 * expiry — ninguém é deslogado no deploy). ALTER via query direto (dbDelta não
 * aplica ALTER de forma confiável — mesmo padrão das 003/006/009/010).
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $table = $wpdb->prefix . 'guardkids_companion_devices';

    $wpdb->query("ALTER TABLE {$table}
        ADD COLUMN session_expires_at DATETIME NULL AFTER session_token_hash;");
};
