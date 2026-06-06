<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class UsageEventRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'usage_events';
    }

    /**
     * Override do insert: usage_events não tem coluna updated_at.
     *
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        $data['created_at'] = current_time('mysql', true);
        $ok = $this->db->insert($this->table(), $data);
        if ($ok === false) {
            return 0;
        }
        return (int) $this->db->insert_id;
    }

    /**
     * Agrupa por dia (YYYY-MM-DD), somando duration_seconds e devolvendo minutos.
     *
     * @return array<int, array{day: string, child_id: int, minutes: int}>
     */
    public function aggregateDailyMinutes(int $childId, string $fromIso, string $toIso): array
    {
        $base = 'SELECT DATE(created_at) AS day, child_id, SUM(duration_seconds) AS total_seconds'
            . ' FROM ' . $this->table()
            . ' WHERE created_at >= %s AND created_at < %s';

        if ($childId > 0) {
            $sql = $this->db->prepare(
                $base . ' AND child_id = %d GROUP BY DATE(created_at), child_id ORDER BY day ASC',
                $fromIso,
                $toIso,
                $childId,
            );
        } else {
            $sql = $this->db->prepare(
                $base . ' GROUP BY DATE(created_at), child_id ORDER BY day ASC',
                $fromIso,
                $toIso,
            );
        }

        $rows = $this->db->get_results($sql, ARRAY_A);
        if (! is_array($rows)) {
            return [];
        }

        return array_map(static fn (array $r): array => [
            'day'      => (string) $r['day'],
            'child_id' => (int) $r['child_id'],
            'minutes'  => (int) floor(((int) $r['total_seconds']) / 60),
        ], $rows);
    }
}
