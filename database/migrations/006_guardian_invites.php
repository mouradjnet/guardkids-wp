<?php

declare(strict_types=1);

/**
 * Migration 006 — campos de convite (token + expira) em guardians.
 *
 * - invite_token VARCHAR(64) NULL — hash sha256 hex; o plaintext só
 *   aparece UMA vez (no response do create / resend) e nunca persiste.
 * - invite_expires_at DATETIME NULL — UTC.
 *
 * Idempotência via MigrationRunner; dbDelta nao funciona com ALTER TABLE.
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $table = $wpdb->prefix . 'guardkids_guardians';

    // dbDelta nao aplica ALTER TABLE de forma confiavel — usa query direto.
    // Idempotencia ja e' garantida pelo MigrationRunner (version tracking).
    $wpdb->query("ALTER TABLE {$table}
        ADD COLUMN invite_token      VARCHAR(64) NULL AFTER status,
        ADD COLUMN invite_expires_at DATETIME    NULL AFTER invite_token,
        ADD KEY invite_token (invite_token);");
};
