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

    /**
     * Top domains por nº de aberturas (type = 'site_open'). Ignora heartbeats.
     *
     * @return array<int, array{domain: string, opens: int, top_child_id: int|null}>
     */
    public function topDomains(int $childId, string $fromIso, string $toIso, int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));

        $base = "SELECT domain, COUNT(*) AS opens,"
            . " (SELECT child_id FROM " . $this->table() . " e2"
            . "  WHERE e2.domain = e1.domain AND e2.type = 'site_open'"
            . "    AND e2.created_at >= %s AND e2.created_at < %s"
            . ($childId > 0 ? "    AND e2.child_id = %d" : '')
            . "  GROUP BY child_id ORDER BY COUNT(*) DESC LIMIT 1) AS top_child_id"
            . " FROM " . $this->table() . " e1"
            . " WHERE e1.type = 'site_open' AND e1.created_at >= %s AND e1.created_at < %s";

        if ($childId > 0) {
            $sql = $this->db->prepare(
                $base . ' AND e1.child_id = %d GROUP BY domain ORDER BY opens DESC LIMIT ' . $limit,
                $fromIso, $toIso, $childId,
                $fromIso, $toIso, $childId,
            );
        } else {
            $sql = $this->db->prepare(
                $base . ' GROUP BY domain ORDER BY opens DESC LIMIT ' . $limit,
                $fromIso, $toIso,
                $fromIso, $toIso,
            );
        }

        $rows = $this->db->get_results($sql, ARRAY_A);
        if (! is_array($rows)) {
            return [];
        }

        return array_map(static fn (array $r): array => [
            'domain'       => (string) $r['domain'],
            'opens'        => (int) $r['opens'],
            'top_child_id' => isset($r['top_child_id']) ? (int) $r['top_child_id'] : null,
        ], $rows);
    }
}
