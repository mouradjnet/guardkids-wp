<?php

declare(strict_types=1);

namespace GuardKids\Maintenance;

/**
 * Retenção de dados append-only do GuardKids.
 *
 * `usage_events` e `locations` crescem sem teto natural (heartbeat ~1/min/child,
 * location ~1/min/child quando enabled). Sem TTL, instalações ativas chegam em
 * milhões de linhas em ~3 meses. Esta classe roda no cron diário e descarta
 * linhas além da janela útil pros relatórios.
 *
 * Trade-off de retenção:
 * - `usage_events` 90 dias: cobre histórico até "mês" + 60d de buffer pros
 *   relatórios premium full_history.
 * - `locations` 30 dias: mapa do parent só mostra "última posição"; histórico
 *   maior fica como feature futura (não há UI consumindo > 30d hoje).
 */
final class Purger
{
    public const USAGE_EVENTS_DAYS = 90;
    public const LOCATIONS_DAYS    = 30;

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
     * Roda os 2 purges. Chamada via hook `guardkids_daily_purge`.
     */
    public function run(): void
    {
        $this->purgeOldUsageEvents(self::USAGE_EVENTS_DAYS);
        $this->purgeOldLocations(self::LOCATIONS_DAYS);
    }

    /**
     * @return int linhas removidas (0 quando wpdb falha).
     */
    public function purgeOldUsageEvents(int $daysOld): int
    {
        return $this->purgeBefore(
            $this->db->prefix . 'guardkids_usage_events',
            'created_at',
            $daysOld,
        );
    }

    /**
     * @return int linhas removidas (0 quando wpdb falha).
     */
    public function purgeOldLocations(int $daysOld): int
    {
        return $this->purgeBefore(
            $this->db->prefix . 'guardkids_locations',
            'recorded_at',
            $daysOld,
        );
    }

    private function purgeBefore(string $table, string $column, int $daysOld): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $daysOld * 86400);
        $sql = $this->db->prepare(
            "DELETE FROM {$table} WHERE {$column} < %s",
            $cutoff,
        );
        $result = $this->db->query($sql);
        return is_numeric($result) ? (int) $result : 0;
    }
}
