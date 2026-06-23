<?php

declare(strict_types=1);

/**
 * Migration 010 — token de sessão persistente do Companion.
 *
 * Separa o token de pareamento (efêmero, 10min, vai no QR) do token de sessão
 * (longo, usado em todo sync/heartbeat). O hash do token de sessão mora aqui,
 * na linha do device — que vira a fonte da verdade de "quem pode sincronizar
 * como este device". Re-parear sobrescreve/limpa o hash e mata a sessão antiga
 * na hora (revogação de device perdido/roubado).
 *
 * Antes desta migration o pairing token de 10min era reusado como sessão e o
 * device parava de sincronizar 10min após parear.
 *
 * NULL = device pareado mas ainda não fez enroll (ou sessão revogada).
 *
 * Idempotência via MigrationRunner; ALTER via $wpdb->query direto (dbDelta não
 * aplica ALTER de forma confiável — mesmo padrão das migrations 003/006/009).
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $table = $wpdb->prefix . 'guardkids_companion_devices';

    $wpdb->query("ALTER TABLE {$table}
        ADD COLUMN session_token_hash VARCHAR(64) NULL AFTER device_uuid,
        ADD KEY session_token_hash (session_token_hash);");
};
