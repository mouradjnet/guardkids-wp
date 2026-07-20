<?php

declare(strict_types=1);

namespace GuardKids\Database;

/**
 * Estado de geofencing por filho (uma linha por child_id). PK é child_id, então
 * não herda o insert/update do Repository base (que assume `id`).
 */
final class ChildPlaceRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'child_place';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $childId): ?array
    {
        $sql = $this->db->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE child_id = %d LIMIT 1',
            $childId,
        );
        $row = $this->db->get_row($sql, ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * INSERT ... ON DUPLICATE KEY UPDATE. $data: current_zone_id, current_since,
     * pending_zone_id, pending_count, pending_since (updated_at é injetado aqui).
     *
     * @param array<string, mixed> $data
     */
    public function upsert(int $childId, array $data): void
    {
        $now = current_time('mysql', true);
        $czid = $data['current_zone_id'] ?? null;
        $pzid = $data['pending_zone_id'] ?? null;

        $sql = $this->db->prepare(
            'INSERT INTO ' . $this->table() . ' '
            . '(child_id, current_zone_id, current_since, pending_zone_id, pending_count, pending_since, updated_at) '
            . 'VALUES (%d, ' . ($czid === null ? 'NULL' : '%d') . ', ' . ($data['current_since'] === null ? 'NULL' : '%s') . ', '
            . ($pzid === null ? 'NULL' : '%d') . ', %d, ' . ($data['pending_since'] === null ? 'NULL' : '%s') . ', %s) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'current_zone_id = VALUES(current_zone_id), current_since = VALUES(current_since), '
            . 'pending_zone_id = VALUES(pending_zone_id), pending_count = VALUES(pending_count), '
            . 'pending_since = VALUES(pending_since), updated_at = VALUES(updated_at)',
            ...array_values(array_filter([
                $childId,
                $czid,
                $data['current_since'],
                $pzid,
                (int) $data['pending_count'],
                $data['pending_since'],
                $now,
            ], static fn ($v) => $v !== null)),
        );
        $this->db->query($sql);
    }

    public function deleteByChild(int $childId): void
    {
        $this->db->delete($this->table(), ['child_id' => $childId]);
    }
}
