<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration\Api;

use GuardKids\Api\Controllers\ReportsController;
use GuardKids\Database\ChildRepository;
use GuardKids\License\Gate;
use GuardKids\Tests\Integration\ControllerIntegrationTestCase;
use GuardKids\Tests\Support\AlwaysAllowGate;

/**
 * Integration tests do ReportsController.
 *
 * Esse controller e o que mais depende de SQL agregado (kpis, daily,
 * topDomains). UsageEventRepositoryTest cobre os calculos por unidade;
 * aqui foco no contract REST: shape, validacao de range, gating de
 * 'full_history', e integracao end-to-end (children + events).
 */
final class ReportsControllerTest extends ControllerIntegrationTestCase
{
    private function freeController(): ReportsController
    {
        return new ReportsController(new Gate());
    }

    private function premiumController(): ReportsController
    {
        return new ReportsController(new AlwaysAllowGate());
    }

    private function seedChild(string $name = 'Maria', int $limitMinutes = 60): int
    {
        return (new ChildRepository())->insert([
            'slug'          => strtolower($name),
            'name'          => $name,
            'status'        => 'offline',
            'used_minutes'  => 0,
            'limit_minutes' => $limitMinutes,
        ]);
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

    public function test_invalid_range_returns_422(): void
    {
        $resp = $this->freeController()->index($this->makeRequest('GET', '/reports', ['range' => 'year']));
        $this->assertWpError('invalid_range', $resp);
        $this->assertResponseStatus(422, $resp);
    }

    public function test_default_range_is_week(): void
    {
        $data = $this->dataOf($this->freeController()->index($this->makeRequest('GET', '/reports')));
        $this->assertSame('week', $data['range']);
    }

    public function test_month_on_free_plan_degrades_to_week_silently(): void
    {
        $data = $this->dataOf($this->freeController()->index($this->makeRequest('GET', '/reports', ['range' => 'month'])));
        $this->assertSame('week', $data['range']);
    }

    public function test_month_on_premium_stays_month(): void
    {
        $data = $this->dataOf($this->premiumController()->index($this->makeRequest('GET', '/reports', ['range' => 'month'])));
        $this->assertSame('month', $data['range']);
    }

    public function test_response_shape_includes_all_expected_sections(): void
    {
        $data = $this->dataOf($this->freeController()->index($this->makeRequest('GET', '/reports')));
        $this->assertArrayHasKey('range', $data);
        $this->assertArrayHasKey('from', $data);
        $this->assertArrayHasKey('to', $data);
        $this->assertArrayHasKey('kpis', $data);
        $this->assertArrayHasKey('dailyByChild', $data);
        $this->assertArrayHasKey('topSites', $data);
        $this->assertArrayHasKey('perChild', $data);
    }

    public function test_empty_database_returns_zero_kpis(): void
    {
        $data = $this->dataOf($this->freeController()->index($this->makeRequest('GET', '/reports')));
        $this->assertSame(0, $data['kpis']['totalMinutes']);
        $this->assertSame(0, $data['kpis']['avgMinutesPerDay']);
        $this->assertNull($data['kpis']['deltaPctVsPrevious']);
        $this->assertSame([], $data['dailyByChild']);
        $this->assertSame([], $data['topSites']);
    }

    public function test_perChild_lists_all_children_even_without_data(): void
    {
        $alice = $this->seedChild('Alice');
        $bob   = $this->seedChild('Bob');

        $data = $this->dataOf($this->freeController()->index($this->makeRequest('GET', '/reports')));

        $this->assertCount(2, $data['perChild']);
        $names = array_column($data['perChild'], 'name');
        sort($names);
        $this->assertSame(['Alice', 'Bob'], $names);
        foreach ($data['perChild'] as $entry) {
            $this->assertSame(0, $entry['totalMinutes']);
        }
        unset($alice, $bob); // suppress unused-warning sem mudar logica
    }

    public function test_kpis_aggregate_minutes_from_heartbeats(): void
    {
        $alice = $this->seedChild('Alice');
        $now   = current_time('mysql', true);
        // Janela do controller e [from, to) com to=now. Eventos em "now"
        // exato caem fora — seeda 1h antes pra entrar no range.
        $oneHourAgo = gmdate('Y-m-d H:i:s', strtotime($now) - 3600);
        $yesterday  = gmdate('Y-m-d H:i:s', strtotime($now) - 86400);

        $this->seedHeartbeat($alice, $yesterday,  1800); // 30 min
        $this->seedHeartbeat($alice, $oneHourAgo, 1200); // 20 min → total 50

        $data = $this->dataOf($this->freeController()->index($this->makeRequest('GET', '/reports')));
        $this->assertSame(50, $data['kpis']['totalMinutes']);
    }

    public function test_topSites_filters_site_open_only(): void
    {
        $alice = $this->seedChild('Alice');
        $now   = current_time('mysql', true);
        $earlier = gmdate('Y-m-d H:i:s', strtotime($now) - 3600);

        $this->seedSiteOpen($alice, $earlier, 'youtube.com');
        $this->seedSiteOpen($alice, $earlier, 'youtube.com');
        $this->seedSiteOpen($alice, $earlier, 'roblox.com');
        $this->seedHeartbeat($alice, $earlier, 60); // não conta no topSites

        $data = $this->dataOf($this->freeController()->index($this->makeRequest('GET', '/reports')));

        $this->assertCount(2, $data['topSites']);
        $this->assertSame('youtube.com', $data['topSites'][0]['domain']);
        $this->assertSame(2, $data['topSites'][0]['opens']);
        $this->assertSame($alice, $data['topSites'][0]['topChildId']);
    }

    public function test_dailyByChild_pivots_per_day(): void
    {
        $alice = $this->seedChild('Alice');
        $bob   = $this->seedChild('Bob');
        $now   = current_time('mysql', true);
        $yesterday = gmdate('Y-m-d 12:00:00', strtotime($now) - 86400);

        $this->seedHeartbeat($alice, $yesterday, 1800); // 30 min
        $this->seedHeartbeat($bob,   $yesterday, 900);  // 15 min

        $data = $this->dataOf($this->freeController()->index($this->makeRequest('GET', '/reports')));

        $this->assertGreaterThanOrEqual(1, count($data['dailyByChild']));
        $dayRow = $data['dailyByChild'][0];
        $this->assertArrayHasKey('day', $dayRow);
        $this->assertArrayHasKey('byChild', $dayRow);
        $this->assertSame(30, $dayRow['byChild'][$alice]);
        $this->assertSame(15, $dayRow['byChild'][$bob]);
    }

    public function test_child_id_filter_isolates_perChild(): void
    {
        $alice = $this->seedChild('Alice');
        $bob   = $this->seedChild('Bob');
        $now   = current_time('mysql', true);
        $oneHourAgo = gmdate('Y-m-d H:i:s', strtotime($now) - 3600);

        $this->seedHeartbeat($alice, $oneHourAgo, 600);  // 10 min
        $this->seedHeartbeat($bob,   $oneHourAgo, 1200); // 20 min

        $data = $this->dataOf($this->freeController()->index(
            $this->makeRequest('GET', '/reports', ['child_id' => $alice])
        ));

        // KPIs e perChild ficam isolados a Alice
        $this->assertSame(10, $data['kpis']['totalMinutes']);
        $this->assertCount(1, $data['perChild']);
        $this->assertSame('Alice', $data['perChild'][0]['name']);
        $this->assertSame(10, $data['perChild'][0]['totalMinutes']);
    }
}
