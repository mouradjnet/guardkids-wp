<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration\Repository;

use GuardKids\Database\UsageEventRepository;
use GuardKids\Tests\Integration\IntegrationTestCase;

/**
 * Valida agregações (SUM/COUNT/GROUP BY) do UsageEventRepository contra MySQL real.
 *
 * Esse repo alimenta o ReportsController — é o que mais depende de SQL real
 * (subqueries, DATE(), COALESCE, LIMIT clamp). Stubs unit não conseguem
 * cobrir esse tipo de lógica.
 */
final class UsageEventRepositoryTest extends IntegrationTestCase
{
    public function test_insert_omits_updated_at_column(): void
    {
        $repo = new UsageEventRepository();

        $id = $repo->insert([
            'child_id'         => 1,
            'type'             => 'heartbeat',
            'duration_seconds' => 60,
        ]);

        $this->assertGreaterThan(0, $id);

        $row = $this->db->get_row(
            "SELECT * FROM `{$this->db->prefix}guardkids_usage_events` WHERE id = {$id}",
            'ARRAY_A',
        );
        $this->assertSame(1, (int) $row['child_id']);
        $this->assertSame('heartbeat', $row['type']);
        $this->assertSame(60, (int) $row['duration_seconds']);
        $this->assertArrayNotHasKey('updated_at', $row);
    }

    public function test_aggregateDailyMinutes_sums_seconds_grouped_by_day(): void
    {
        $repo = new UsageEventRepository();
        $this->seedHeartbeat(1, '2026-01-01 10:00:00', 600);  // 10 min
        $this->seedHeartbeat(1, '2026-01-01 14:00:00', 300);  // 5 min  → dia 01 = 15 min
        $this->seedHeartbeat(1, '2026-01-02 09:00:00', 1800); // 30 min → dia 02 = 30 min

        $rows = $repo->aggregateDailyMinutes(1, '2026-01-01 00:00:00', '2026-01-03 00:00:00');

        $this->assertCount(2, $rows);
        $this->assertSame('2026-01-01', $rows[0]['day']);
        $this->assertSame(15, $rows[0]['minutes']);
        $this->assertSame('2026-01-02', $rows[1]['day']);
        $this->assertSame(30, $rows[1]['minutes']);
    }

    public function test_aggregateDailyMinutes_filters_by_child_when_id_positive(): void
    {
        $repo = new UsageEventRepository();
        $this->seedHeartbeat(1, '2026-01-01 10:00:00', 600);
        $this->seedHeartbeat(2, '2026-01-01 10:00:00', 1200);

        $child1 = $repo->aggregateDailyMinutes(1, '2026-01-01 00:00:00', '2026-01-02 00:00:00');
        $this->assertCount(1, $child1);
        $this->assertSame(1, $child1[0]['child_id']);
        $this->assertSame(10, $child1[0]['minutes']);
    }

    public function test_aggregateDailyMinutes_returns_all_children_when_id_zero(): void
    {
        $repo = new UsageEventRepository();
        $this->seedHeartbeat(1, '2026-01-01 10:00:00', 600);
        $this->seedHeartbeat(2, '2026-01-01 10:00:00', 1200);

        $all = $repo->aggregateDailyMinutes(0, '2026-01-01 00:00:00', '2026-01-02 00:00:00');
        $this->assertCount(2, $all);
        $childIds = array_column($all, 'child_id');
        sort($childIds);
        $this->assertSame([1, 2], $childIds);
    }

    public function test_aggregateDailyMinutes_respects_half_open_window(): void
    {
        $repo = new UsageEventRepository();
        $this->seedHeartbeat(1, '2026-01-01 00:00:00', 600);   // dentro (>=)
        $this->seedHeartbeat(1, '2026-01-02 00:00:00', 600);   // fora (< to estrito)
        $this->seedHeartbeat(1, '2026-01-01 23:59:59', 600);   // dentro

        $rows = $repo->aggregateDailyMinutes(1, '2026-01-01 00:00:00', '2026-01-02 00:00:00');
        $this->assertCount(1, $rows);
        $this->assertSame(20, $rows[0]['minutes']); // 600+600 = 1200s = 20min
    }

    public function test_topDomains_counts_site_open_ignoring_heartbeat(): void
    {
        $repo = new UsageEventRepository();
        $this->seedSiteOpen(1, '2026-01-01 10:00:00', 'youtube.com');
        $this->seedSiteOpen(1, '2026-01-01 11:00:00', 'youtube.com');
        $this->seedSiteOpen(1, '2026-01-01 12:00:00', 'roblox.com');
        $this->seedHeartbeat(1, '2026-01-01 13:00:00', 60); // não conta

        $rows = $repo->topDomains(1, '2026-01-01 00:00:00', '2026-01-02 00:00:00');

        $this->assertCount(2, $rows);
        $this->assertSame('youtube.com', $rows[0]['domain']);
        $this->assertSame(2, $rows[0]['opens']);
        $this->assertSame('roblox.com', $rows[1]['domain']);
        $this->assertSame(1, $rows[1]['opens']);
    }

    public function test_topDomains_respects_limit(): void
    {
        $repo = new UsageEventRepository();
        $this->seedSiteOpen(1, '2026-01-01 10:00:00', 'a.com');
        $this->seedSiteOpen(1, '2026-01-01 11:00:00', 'b.com');
        $this->seedSiteOpen(1, '2026-01-01 12:00:00', 'c.com');

        $rows = $repo->topDomains(1, '2026-01-01 00:00:00', '2026-01-02 00:00:00', 2);
        $this->assertCount(2, $rows);
    }

    public function test_topDomains_returns_top_child_id_per_domain(): void
    {
        $repo = new UsageEventRepository();
        $this->seedSiteOpen(1, '2026-01-01 10:00:00', 'tiktok.com');
        $this->seedSiteOpen(1, '2026-01-01 11:00:00', 'tiktok.com');
        $this->seedSiteOpen(2, '2026-01-01 12:00:00', 'tiktok.com');

        $rows = $repo->topDomains(0, '2026-01-01 00:00:00', '2026-01-02 00:00:00');

        $this->assertCount(1, $rows);
        $this->assertSame('tiktok.com', $rows[0]['domain']);
        $this->assertSame(3, $rows[0]['opens']);
        $this->assertSame(1, $rows[0]['top_child_id']); // child 1 tem 2 opens, child 2 tem 1
    }

    public function test_kpisForRange_computes_current_and_previous_window(): void
    {
        $repo = new UsageEventRepository();
        // Current: 2026-01-08 → 2026-01-15 (7 dias)
        // Previous: 2026-01-01 → 2026-01-08
        $this->seedHeartbeat(1, '2026-01-10 10:00:00', 600);  // current: 10min
        $this->seedHeartbeat(1, '2026-01-12 10:00:00', 1200); // current: 20min → 30min
        $this->seedHeartbeat(1, '2026-01-03 10:00:00', 900);  // previous: 15min

        $kpis = $repo->kpisForRange(1, '2026-01-08 00:00:00', '2026-01-15 00:00:00');

        $this->assertSame(30, $kpis['total_minutes']);
        $this->assertSame(15, $kpis['total_minutes_prev']);
        $this->assertSame(7, $kpis['range_days']);
    }

    public function test_kpisForRange_returns_zero_when_no_data(): void
    {
        $repo = new UsageEventRepository();
        $kpis = $repo->kpisForRange(99, '2026-01-08 00:00:00', '2026-01-15 00:00:00');

        $this->assertSame(0, $kpis['total_minutes']);
        $this->assertSame(0, $kpis['total_minutes_prev']);
        $this->assertSame(7, $kpis['range_days']);
    }

    public function test_minutesUsedInWindow_sums_heartbeat_and_site_open(): void
    {
        $repo = new UsageEventRepository();
        $this->seedHeartbeat(1, '2026-06-15 10:00:00', 600);  // 10 min
        $this->seedSiteOpenWithDuration(1, '2026-06-15 11:00:00', 'youtube.com', 300); // 5 min

        $minutes = $repo->minutesUsedInWindow(1, '2026-06-15 00:00:00', '2026-06-16 00:00:00');
        $this->assertSame(15, $minutes);
    }

    public function test_minutesUsedInWindow_excludes_schedule_block(): void
    {
        $repo = new UsageEventRepository();
        $this->seedHeartbeat(1, '2026-06-15 10:00:00', 600); // 10 min conta
        // schedule_block com duration alto — não pode entrar na soma (filtro por type).
        $this->db->query(sprintf(
            "INSERT INTO `%sguardkids_usage_events` (child_id, type, domain, detail, duration_seconds, created_at) VALUES (1, 'schedule_block', NULL, 'limit', 9999, '2026-06-15 12:00:00')",
            $this->db->prefix,
        ));

        $minutes = $repo->minutesUsedInWindow(1, '2026-06-15 00:00:00', '2026-06-16 00:00:00');
        $this->assertSame(10, $minutes);
    }

    public function test_minutesUsedInWindow_respects_half_open_window(): void
    {
        $repo = new UsageEventRepository();
        $this->seedHeartbeat(1, '2026-06-15 00:00:00', 600); // dentro (>= from)
        $this->seedHeartbeat(1, '2026-06-15 23:59:59', 600); // dentro (< to)
        $this->seedHeartbeat(1, '2026-06-16 00:00:00', 600); // fora (== to, exclusivo)

        $minutes = $repo->minutesUsedInWindow(1, '2026-06-15 00:00:00', '2026-06-16 00:00:00');
        $this->assertSame(20, $minutes); // 600 + 600 = 1200s = 20min
    }

    public function test_minutesUsedInWindow_filters_by_child(): void
    {
        $repo = new UsageEventRepository();
        $this->seedHeartbeat(1, '2026-06-15 10:00:00', 600);
        $this->seedHeartbeat(2, '2026-06-15 10:00:00', 1800);

        $this->assertSame(10, $repo->minutesUsedInWindow(1, '2026-06-15 00:00:00', '2026-06-16 00:00:00'));
        $this->assertSame(0, $repo->minutesUsedInWindow(99, '2026-06-15 00:00:00', '2026-06-16 00:00:00'));
    }

    private function seedSiteOpenWithDuration(int $childId, string $createdAt, string $domain, int $durationSeconds): void
    {
        $this->db->query(sprintf(
            "INSERT INTO `%sguardkids_usage_events` (child_id, type, domain, duration_seconds, created_at) VALUES (%d, 'site_open', '%s', %d, '%s')",
            $this->db->prefix,
            $childId,
            $domain,
            $durationSeconds,
            $createdAt,
        ));
    }

    private function seedHeartbeat(int $childId, string $createdAt, int $durationSeconds): void
    {
        $this->db->query(sprintf(
            "INSERT INTO `%sguardkids_usage_events` (child_id, type, domain, duration_seconds, created_at) VALUES (%d, 'heartbeat', NULL, %d, '%s')",
            $this->db->prefix,
            $childId,
            $durationSeconds,
            $createdAt,
        ));
    }

    private function seedSiteOpen(int $childId, string $createdAt, string $domain): void
    {
        $this->db->query(sprintf(
            "INSERT INTO `%sguardkids_usage_events` (child_id, type, domain, duration_seconds, created_at) VALUES (%d, 'site_open', '%s', 0, '%s')",
            $this->db->prefix,
            $childId,
            $domain,
            $createdAt,
        ));
    }
}
