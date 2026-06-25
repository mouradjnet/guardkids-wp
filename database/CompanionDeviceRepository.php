<?php

declare(strict_types=1);

namespace GuardKids\Database;

/**
 * Dispositivos pareados via GuardKids Companion (v1.5.0+).
 *
 * v1.5.0 só prepara o backend — o Companion Android ainda não foi implementado,
 * mas todos os campos e operações ficam prontos para receber os reports.
 */
final class CompanionDeviceRepository extends Repository
{
    /** Janela deslizante do token de sessão (renovada a cada sync). */
    public const SESSION_TTL_DAYS = 30;

    protected function tableSuffix(): string
    {
        return 'companion_devices';
    }

    /** Timestamp UTC (mysql) de expiração do token de sessão a partir de agora. */
    public function expiryFromNow(): string
    {
        return gmdate('Y-m-d H:i:s', time() + self::SESSION_TTL_DAYS * 86400);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByChildId(int $childId): ?array
    {
        $sql = $this->db->prepare(
            'SELECT * FROM ' . $this->table()
            . ' WHERE child_id = %d ORDER BY id DESC LIMIT 1',
            $childId,
        );
        $row = $this->db->get_row($sql, ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUuid(string $uuid): ?array
    {
        $sql = $this->db->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE device_uuid = %s LIMIT 1',
            $uuid,
        );
        $row = $this->db->get_row($sql, ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * Resolve o device pelo hash do token de sessão (auth de sync/heartbeat).
     * O hash mora na linha do device desde a migration 010 — re-parear o
     * limpa, revogando a sessão antiga.
     *
     * @return array<string, mixed>|null
     */
    public function findBySessionTokenHash(string $hash): ?array
    {
        if ($hash === '') {
            return null;
        }
        $sql = $this->db->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE session_token_hash = %s LIMIT 1',
            $hash,
        );
        $row = $this->db->get_row($sql, ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * Marca last_sync = NOW e atualiza flags reportadas pelo Companion.
     * Usado por POST /companion/heartbeat e /companion/sync.
     *
     * @param array<string, mixed> $patch
     */
    public function touchSync(int $id, array $patch = []): bool
    {
        $patch['last_sync'] = current_time('mysql', true);
        $patch['session_expires_at'] = $this->expiryFromNow();
        return $this->update($id, $patch);
    }
}
