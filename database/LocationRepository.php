<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class LocationRepository extends Repository
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 100;

    protected function tableSuffix(): string
    {
        return 'locations';
    }

    /**
     * Override do insert: locations é append-only, não tem updated_at.
     * recorded_at é responsabilidade do caller (controller injeta UTC).
     *
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        $data['created_at'] = current_time('mysql', true);
        if (! isset($data['recorded_at'])) {
            $data['recorded_at'] = $data['created_at'];
        }
        $ok = $this->db->insert($this->table(), $data);
        if ($ok === false) {
            return 0;
        }
        return (int) $this->db->insert_id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLastByChildId(int $childId): ?array
    {
        $sql = $this->db->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE child_id = %d ORDER BY recorded_at DESC LIMIT 1',
            $childId,
        );
        $row = $this->db->get_row($sql, ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByChildId(int $childId, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $sql = $this->db->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE child_id = %d ORDER BY recorded_at DESC LIMIT %d',
            $childId,
            $limit,
        );
        $rows = $this->db->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }
}
