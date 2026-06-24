<?php

declare(strict_types=1);

namespace GuardKids\Notifications;

/**
 * Agrega os números dos digests de notificação. Janelas em UTC via gmdate
 * (created_at é gravado em UTC), no estilo do Purger/UsageEventRepository.
 */
final class DigestData
{
    private readonly \wpdb $db;

    public function __construct(?\wpdb $db = null)
    {
        if ($db === null) {
            global $wpdb;
            $db = $wpdb;
        }
        $this->db = $db;
    }

    /**
     * @return array{children: array<int, array{name: string, usedMinutes: int, limitMinutes: int}>, pendingRequests: int, blocksToday: int}
     */
    public function buildDaily(): array
    {
        $p = $this->db->prefix;
        $children = $this->db->get_results(
            'SELECT name, used_minutes, limit_minutes FROM ' . $p . 'guardkids_children ORDER BY name ASC',
            ARRAY_A,
        );
        $children = is_array($children) ? $children : [];

        $pending = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$p}guardkids_requests WHERE status = 'pending'",
        );

        $todayStart = gmdate('Y-m-d 00:00:00');
        $blocks = (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$p}guardkids_usage_events WHERE type = 'schedule_block' AND created_at >= %s",
            $todayStart,
        ));

        return [
            'children' => array_map(static fn (array $c): array => [
                'name'         => (string) $c['name'],
                'usedMinutes'  => (int) $c['used_minutes'],
                'limitMinutes' => (int) $c['limit_minutes'],
            ], $children),
            'pendingRequests' => $pending,
            'blocksToday'     => $blocks,
        ];
    }

    /**
     * @return array{children: array<int, array{name: string, weekMinutes: int}>, blocksWeek: int, requestsApproved: int, requestsDenied: int}
     */
    public function buildWeekly(): array
    {
        $p = $this->db->prefix;
        $weekStart = gmdate('Y-m-d H:i:s', time() - 7 * 86400);

        $rows = $this->db->get_results($this->db->prepare(
            "SELECT c.name AS name, COALESCE(SUM(e.duration_seconds), 0) AS secs"
            . " FROM {$p}guardkids_children c"
            . " LEFT JOIN {$p}guardkids_usage_events e"
            . "   ON e.child_id = c.id AND e.created_at >= %s"
            . " GROUP BY c.id, c.name ORDER BY c.name ASC",
            $weekStart,
        ), ARRAY_A);
        $rows = is_array($rows) ? $rows : [];

        $blocks = (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$p}guardkids_usage_events WHERE type = 'schedule_block' AND created_at >= %s",
            $weekStart,
        ));
        $approved = (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$p}guardkids_requests WHERE status = 'approved' AND decided_at >= %s",
            $weekStart,
        ));
        $denied = (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$p}guardkids_requests WHERE status = 'denied' AND decided_at >= %s",
            $weekStart,
        ));

        return [
            'children' => array_map(static fn (array $r): array => [
                'name'        => (string) $r['name'],
                'weekMinutes' => (int) floor(((int) $r['secs']) / 60),
            ], $rows),
            'blocksWeek'       => $blocks,
            'requestsApproved' => $approved,
            'requestsDenied'   => $denied,
        ];
    }
}
