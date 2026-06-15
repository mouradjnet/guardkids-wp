<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class UsageEventRepository extends Repository
{
    private const DEFAULT_TOP_DOMAINS_LIMIT = 10;
    private const MAX_TOP_DOMAINS_LIMIT = 100;

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
    public function topDomains(int $childId, string $fromIso, string $toIso, int $limit = self::DEFAULT_TOP_DOMAINS_LIMIT): array
    {
        $limit = max(1, min(self::MAX_TOP_DOMAINS_LIMIT, $limit));

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

    /**
     * @return array{total_minutes: int, total_minutes_prev: int, range_days: int}
     */
    public function kpisForRange(int $childId, string $fromIso, string $toIso): array
    {
        $fromTs = strtotime($fromIso);
        $toTs   = strtotime($toIso);
        $rangeDays = (int) round(($toTs - $fromTs) / 86400);

        $prevToIso   = $fromIso;
        $prevFromIso = gmdate('Y-m-d H:i:s', $fromTs - ($toTs - $fromTs));

        $current  = $this->sumDurationSeconds($childId, $fromIso, $toIso);
        $previous = $this->sumDurationSeconds($childId, $prevFromIso, $prevToIso);

        return [
            'total_minutes'      => (int) floor($current / 60),
            'total_minutes_prev' => (int) floor($previous / 60),
            'range_days'         => $rangeDays,
        ];
    }

    /**
     * Soma minutos de heartbeat/site_open por hora do dia (0..23) num dia
     * específico. Filtra pelo timezone do site (wp_timezone_string).
     * Ignora schedule_block (não consome tempo). Retorna sempre 24 buckets,
     * mesmo que minutos=0, pra simplificar o consumo no frontend.
     *
     * @return array<int, array{hour: int, minutes: int}>
     */
    public function minutesByHourOfDay(int $childId, string $dateIso): array
    {
        $tzString = function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC';
        $fromLocal = "{$dateIso} 00:00:00";
        $toLocal   = "{$dateIso} 23:59:59";

        $sql = $this->db->prepare(
            'SELECT HOUR(CONVERT_TZ(created_at, "+00:00", %s)) AS h,'
            . ' SUM(duration_seconds) AS total_seconds'
            . ' FROM ' . $this->table()
            . ' WHERE child_id = %d AND type IN ("heartbeat", "site_open")'
            . ' AND CONVERT_TZ(created_at, "+00:00", %s) BETWEEN %s AND %s'
            . ' GROUP BY h',
            $tzString,
            $childId,
            $tzString,
            $fromLocal,
            $toLocal,
        );

        $rows = $this->db->get_results($sql, ARRAY_A);
        $byHour = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $hour = (int) $r['h'];
                $byHour[$hour] = (int) floor(((int) $r['total_seconds']) / 60);
            }
        }

        $out = [];
        for ($h = 0; $h < 24; $h++) {
            $out[] = ['hour' => $h, 'minutes' => $byHour[$h] ?? 0];
        }
        return $out;
    }

    /**
     * Últimos eventos type='schedule_block' com nome do filho via JOIN.
     * Ordenado por created_at DESC. Limit clamped pra evitar payload absurdo.
     *
     * @return array<int, array{
     *     id: int,
     *     child_id: int,
     *     child_name: string,
     *     detail: string,
     *     created_at: string,
     * }>
     */
    public function recentBlocks(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $children = $this->db->prefix . 'guardkids_children';

        $sql = $this->db->prepare(
            'SELECT e.id, e.child_id, COALESCE(c.name, %s) AS child_name,'
            . ' COALESCE(e.detail, %s) AS detail, e.created_at'
            . ' FROM ' . $this->table() . ' e'
            . ' LEFT JOIN ' . $children . ' c ON c.id = e.child_id'
            . ' WHERE e.type = %s'
            . ' ORDER BY e.created_at DESC, e.id DESC'
            . ' LIMIT ' . $limit,
            '',
            '',
            'schedule_block',
        );

        $rows = $this->db->get_results($sql, ARRAY_A);
        if (! is_array($rows)) {
            return [];
        }

        return array_map(static fn (array $r): array => [
            'id'         => (int) $r['id'],
            'child_id'   => (int) $r['child_id'],
            'child_name' => (string) $r['child_name'],
            'detail'     => (string) $r['detail'],
            'created_at' => (string) $r['created_at'],
        ], $rows);
    }

    /**
     * Minutos consumidos por um filho numa janela [fromUtc, toUtc) (UTC, half-open).
     *
     * Fonte de verdade do enforcement de limite diário: soma duration_seconds
     * de heartbeat + site_open (ignora schedule_block, que não consome tempo).
     * O caller passa as bordas do dia local já convertidas pra UTC — mesmo
     * contrato de aggregateDailyMinutes/kpisForRange (created_at é UTC).
     */
    public function minutesUsedInWindow(int $childId, string $fromUtc, string $toUtc): int
    {
        $sql = $this->db->prepare(
            'SELECT COALESCE(SUM(duration_seconds), 0) FROM ' . $this->table()
            . ' WHERE child_id = %d AND type IN ("heartbeat", "site_open")'
            . ' AND created_at >= %s AND created_at < %s',
            $childId,
            $fromUtc,
            $toUtc,
        );

        return (int) floor(((int) $this->db->get_var($sql)) / 60);
    }

    private function sumDurationSeconds(int $childId, string $fromIso, string $toIso): int
    {
        $base = 'SELECT COALESCE(SUM(duration_seconds), 0) FROM ' . $this->table()
            . ' WHERE created_at >= %s AND created_at < %s';

        if ($childId > 0) {
            $sql = $this->db->prepare($base . ' AND child_id = %d', $fromIso, $toIso, $childId);
        } else {
            $sql = $this->db->prepare($base, $fromIso, $toIso);
        }

        return (int) $this->db->get_var($sql);
    }
}
