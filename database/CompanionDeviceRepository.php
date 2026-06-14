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
    protected function tableSuffix(): string
    {
        return 'companion_devices';
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
     * Marca last_sync = NOW e atualiza flags reportadas pelo Companion.
     * Usado por POST /companion/heartbeat e /companion/sync.
     *
     * @param array<string, mixed> $patch
     */
    public function touchSync(int $id, array $patch = []): bool
    {
        $patch['last_sync'] = current_time('mysql', true);
        return $this->update($id, $patch);
    }
}
