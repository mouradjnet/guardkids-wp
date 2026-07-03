<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Auth\ChildAuth;
use GuardKids\Database\MissionCompletionRepository;
use GuardKids\Database\ProgressionRepository;
use GuardKids\Missions\MissionEvaluator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Endpoint das missões diárias (fatia 3b). Calcula o estado no read e credita
 * a conclusão de forma preguiçosa e idempotente (ledger anti-duplo). Segue o
 * modelo do awardForOpen: o crédito é envolto em try/catch e nunca quebra a
 * resposta.
 */
final class MissionController
{
    private readonly MissionCompletionRepository $completions;
    private readonly ProgressionRepository $wallet;
    private readonly ChildAuth $auth;

    public function __construct()
    {
        $this->completions = new MissionCompletionRepository();
        $this->wallet      = new ProgressionRepository();
        $this->auth        = new ChildAuth();
    }

    public function childMissions(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }

        $tz   = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $date = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');

        $signals  = $this->completions->signalsFor($childId, $date);
        $missions = MissionEvaluator::evaluate($signals);

        $out = [];
        foreach ($missions as $m) {
            $justCompleted = false;
            if ($m['completed'] && !$this->completions->existsFor($childId, $m['key'], $date)) {
                try {
                    $this->completions->record($childId, $m['key'], $date, $m['xpReward'], $m['coinsReward']);
                    $this->creditBonus($childId, $m['xpReward'], $m['coinsReward'], $date);
                    $justCompleted = true;
                } catch (\Throwable $e) {
                    error_log('[GuardKids] mission credit falhou: ' . $e->getMessage());
                }
            }
            $out[] = [
                'key'           => $m['key'],
                'title'         => $m['title'],
                'description'   => $m['description'],
                'icon'          => $m['icon'],
                'target'        => $m['target'],
                'progress'      => $m['progress'],
                'completed'     => $m['completed'],
                'justCompleted' => $justCompleted,
                'xpReward'      => $m['xpReward'],
                'coinsReward'   => $m['coinsReward'],
            ];
        }

        return rest_ensure_response($out);
    }

    /**
     * Credita o bônus na carteira sem alterar streak/última atividade (o bônus
     * não conta como novo dia de atividade). A conclusão só ocorre quando já
     * houve atividade hoje, então last_activity_date já é hoje.
     */
    private function creditBonus(int $childId, int $xp, int $coins, string $date): void
    {
        $row    = $this->wallet->ensure($childId);
        $streak = (int) ($row['streak_days'] ?? 0);
        $last   = (string) ($row['last_activity_date'] ?? '');
        if ($last === '') {
            $last = $date;
        }
        $this->wallet->apply($childId, $xp, $coins, $streak, $last);
    }
}
