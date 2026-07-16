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
    public const USAGE_EVENTS_DAYS     = 90;
    public const LOCATIONS_DAYS        = 30;
    public const DECIDED_REQUESTS_DAYS = 90;
    public const GUARDIAN_DEDUP_DAYS   = 30;
    public const PAIRING_TOKEN_PREFIX  = 'companion_token:';

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
     * Roda os purges do cron. Chamada via hook `guardkids_daily_purge`.
     */
    public function run(): void
    {
        $this->purgeOldUsageEvents(self::USAGE_EVENTS_DAYS);
        $this->purgeOldLocations(self::LOCATIONS_DAYS);
        $this->purgeExpiredPairingTokens();
        $this->purgeOldGuardianDedup(self::GUARDIAN_DEDUP_DAYS);
    }

    /**
     * Chaves de dedupe do push do guardião viram lixo assim que a janela do
     * evento passa (as chaves diárias já embutem a data). Ninguém lê linha
     * velha; sem TTL a tabela cresceria pra sempre.
     *
     * @return int linhas removidas (0 quando wpdb falha).
     */
    public function purgeOldGuardianDedup(int $daysOld): int
    {
        return $this->purgeBefore(
            $this->db->prefix . 'guardkids_guardian_push_dedup',
            'created_at',
            $daysOld,
        );
    }

    /**
     * Remove pairing tokens (companion_token:*) com expiresAt vencido. O delete
     * on-demand cobre tokens reapresentados; este sweep limpa os abandonados.
     *
     * @return int linhas removidas
     */
    public function purgeExpiredPairingTokens(): int
    {
        $table = $this->db->prefix . 'guardkids_settings';
        $rows = $this->db->get_results(
            "SELECT setting_key, value FROM {$table} WHERE setting_key LIKE '" . self::PAIRING_TOKEN_PREFIX . "%'",
            ARRAY_A,
        );
        if (! is_array($rows)) {
            return 0;
        }
        $now = time();
        $removed = 0;
        foreach ($rows as $row) {
            $data = json_decode((string) ($row['value'] ?? ''), true);
            $exp = is_array($data) && isset($data['expiresAt']) ? strtotime((string) $data['expiresAt']) : 0;
            if ($exp === false || $exp < $now) {
                $this->db->delete($table, ['setting_key' => $row['setting_key']]);
                $removed++;
            }
        }
        return $removed;
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

    /**
     * Apaga pedidos já decididos (approve/deny) mais antigos que a janela.
     * Pendentes têm `decided_at` NULL e são preservados. NÃO entra no run()
     * do cron — é exclusivo da ação manual "Limpar histórico".
     *
     * @return int linhas removidas (0 quando wpdb falha).
     */
    public function purgeOldDecidedRequests(int $daysOld): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $daysOld * 86400);
        $sql = $this->db->prepare(
            'DELETE FROM ' . $this->db->prefix . 'guardkids_requests'
            . ' WHERE decided_at IS NOT NULL AND decided_at < %s',
            $cutoff,
        );
        $result = $this->db->query($sql);
        return is_numeric($result) ? (int) $result : 0;
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
