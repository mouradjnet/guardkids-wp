<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Database\ChildRepository;
use GuardKids\Database\UsageEventRepository;
use GuardKids\License\Gate;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /reports?range=week|month&child_id=*
 *
 * Janela rolling terminando em now(). Sem cron, sem pre-agregação —
 * SQL no read via UsageEventRepository.
 */
final class ReportsController
{
    private readonly UsageEventRepository $events;
    private readonly ChildRepository $children;
    private readonly Gate $gate;

    public function __construct(?Gate $gate = null)
    {
        $this->events   = new UsageEventRepository();
        $this->children = new ChildRepository();
        $this->gate     = $gate ?? new Gate();
    }

    public function index(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $range = (string) ($req->get_param('range') ?: 'week');
        if (! in_array($range, ['week', 'month'], true)) {
            return new WP_Error('invalid_range', 'range inválido.', ['status' => 422]);
        }

        // Free só vê 7 dias. Se pedir mês, força pra week (não 402 — UX
        // melhor: degrada silenciosamente em vez de derrubar a tela inteira).
        if ($range === 'month' && ! $this->gate->can('full_history')) {
            $range = 'week';
        }

        $rangeDays = $range === 'week' ? 7 : 30;
        $now    = current_time('mysql', true);
        $nowTs  = strtotime($now);
        $fromTs = $nowTs - ($rangeDays * 86400);
        $fromIso = gmdate('Y-m-d H:i:s', $fromTs);

        $childParam = $req->get_param('child_id');
        $childId = is_numeric($childParam) ? (int) $childParam : 0;

        $kpisRaw = $this->events->kpisForRange($childId, $fromIso, $now);
        $daily   = $this->events->aggregateDailyMinutes($childId, $fromIso, $now);
        $top     = $this->events->topDomains($childId, $fromIso, $now, 10);

        $children = $childId > 0
            ? array_filter($this->children->findAll(), fn ($c) => (int) $c['id'] === $childId)
            : $this->children->findAll();

        return rest_ensure_response([
            'range' => $range,
            'from'  => $fromIso,
            'to'    => $now,
            'kpis'  => $this->buildKpis($kpisRaw, $children),
            'dailyByChild' => $this->pivotDaily($daily),
            'topSites'     => array_map(static fn ($r) => [
                'domain'      => $r['domain'],
                'opens'       => $r['opens'],
                'topChildId'  => $r['top_child_id'],
            ], $top),
            'perChild' => $this->buildPerChild($daily, $children, $rangeDays),
        ]);
    }

    /**
     * @param array{total_minutes:int,total_minutes_prev:int,range_days:int} $kpis
     * @param array<int, array<string, mixed>> $children
     * @return array{totalMinutes:int,avgMinutesPerDay:int,percentOfLimit:float|null,deltaPctVsPrevious:float|null}
     */
    private function buildKpis(array $kpis, array $children): array
    {
        $total = $kpis['total_minutes'];
        $prev  = $kpis['total_minutes_prev'];
        $days  = max(1, $kpis['range_days']);

        $limitSum = 0;
        foreach ($children as $c) {
            $limit = (int) ($c['limit_minutes'] ?? 0);
            if ($limit <= 0) {
                $limitSum = 0;
                break;
            }
            $limitSum += $limit;
        }
        $denominator = $limitSum * $days;

        return [
            'totalMinutes'        => $total,
            'avgMinutesPerDay'    => (int) floor($total / $days),
            'percentOfLimit'      => $denominator > 0 ? round($total / $denominator, 2) : null,
            'deltaPctVsPrevious'  => $prev > 0 ? round(($total - $prev) / $prev, 2) : null,
        ];
    }

    /**
     * @param array<int, array{day:string,child_id:int,minutes:int}> $daily
     * @return array<int, array{day:string, byChild: array<int,int>}>
     */
    private function pivotDaily(array $daily): array
    {
        $byDay = [];
        foreach ($daily as $row) {
            $day = $row['day'];
            if (! isset($byDay[$day])) {
                $byDay[$day] = ['day' => $day, 'byChild' => []];
            }
            $byDay[$day]['byChild'][$row['child_id']] = $row['minutes'];
        }
        return array_values($byDay);
    }

    /**
     * GET /blocks/recent?limit=10 — últimos bloqueios de schedule (bedtime/weekly/limit).
     */
    public function recentBlocks(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $limitParam = $req->get_param('limit');
        $limit = is_numeric($limitParam) ? (int) $limitParam : 10;

        $rows = $this->events->recentBlocks($limit);

        return rest_ensure_response(array_map(static fn ($r) => [
            'id'        => $r['id'],
            'childId'   => $r['child_id'],
            'childName' => $r['child_name'],
            'detail'    => $r['detail'],
            'createdAt' => $r['created_at'],
        ], $rows));
    }

    /**
     * @param array<int, array{day:string,child_id:int,minutes:int}> $daily
     * @param array<int, array<string, mixed>> $children
     * @return array<int, array{childId:int,name:string,totalMinutes:int,avgMinutesPerDay:int}>
     */
    private function buildPerChild(array $daily, array $children, int $rangeDays): array
    {
        $totalByChild = [];
        foreach ($daily as $row) {
            $cid = $row['child_id'];
            $totalByChild[$cid] = ($totalByChild[$cid] ?? 0) + $row['minutes'];
        }

        $out = [];
        foreach ($children as $c) {
            $cid = (int) $c['id'];
            $total = $totalByChild[$cid] ?? 0;
            $out[] = [
                'childId'          => $cid,
                'name'             => (string) ($c['name'] ?? ''),
                'totalMinutes'     => $total,
                'avgMinutesPerDay' => (int) floor($total / max(1, $rangeDays)),
            ];
        }
        return $out;
    }
}
