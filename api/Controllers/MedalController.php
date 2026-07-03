<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Auth\ChildAuth;
use GuardKids\Database\MedalUnlockRepository;
use GuardKids\Database\ProgressionRepository;
use GuardKids\Medals\MedalCatalog;
use GuardKids\Medals\MedalEvaluator;
use GuardKids\Progression\LevelCurve;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Endpoint das medalhas (fatia 3c). Calcula o estado no read e credita o
 * desbloqueio de forma preguiçosa e idempotente (ledger permanente). O
 * response reflete permanência: medalha já no ledger aparece unlocked mesmo
 * que o sinal caia depois. Crédito envolto em try/catch (nunca quebra a
 * resposta), igual ao MissionController.
 */
final class MedalController
{
    private readonly MedalUnlockRepository $unlocks;
    private readonly ProgressionRepository $wallet;
    private readonly ChildAuth $auth;

    public function __construct()
    {
        $this->unlocks = new MedalUnlockRepository();
        $this->wallet  = new ProgressionRepository();
        $this->auth    = new ChildAuth();
    }

    public function childMedals(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }

        $tz   = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $date = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');

        $row    = $this->wallet->findByChild($childId);
        $xp     = $row !== null ? (int) $row['xp'] : 0;
        $streak = $row !== null ? (int) $row['streak_days'] : 0;

        $counts  = $this->unlocks->signalsFor($childId);
        $signals = [
            'level'                     => LevelCurve::levelForXp($xp),
            'streakDays'                => $streak,
            'totalContentOpened'        => $counts['totalContentOpened'],
            'totalMissionsCompleted'    => $counts['totalMissionsCompleted'],
            'distinctCategoriesAllTime' => $counts['distinctCategoriesAllTime'],
        ];

        $medals  = MedalEvaluator::evaluate($signals);
        $catalog = [];
        foreach (MedalCatalog::all() as $c) {
            $catalog[$c['key']] = $c;
        }

        $out = [];
        foreach ($medals as $m) {
            $already      = $this->unlocks->existsFor($childId, $m['key']);
            $justUnlocked = false;
            if ($m['unlocked'] && ! $already) {
                try {
                    $this->unlocks->record($childId, $m['key'], $date, $catalog[$m['key']]['xpReward'], $catalog[$m['key']]['coinsReward']);
                    $this->creditBonus($childId, $catalog[$m['key']]['xpReward'], $catalog[$m['key']]['coinsReward'], $date);
                    $justUnlocked = true;
                    $already      = true;
                } catch (\Throwable $e) {
                    error_log('[GuardKids] medal credit falhou: ' . $e->getMessage());
                }
            }
            $out[] = [
                'key'          => $m['key'],
                'title'        => $m['title'],
                'description'  => $m['description'],
                'icon'         => $m['icon'],
                'target'       => $m['target'],
                'progress'     => $m['progress'],
                'unlocked'     => $already || $m['unlocked'],
                'justUnlocked' => $justUnlocked,
                'xpReward'     => $catalog[$m['key']]['xpReward'],
                'coinsReward'  => $catalog[$m['key']]['coinsReward'],
            ];
        }

        return rest_ensure_response($out);
    }

    /**
     * Credita o bônus na carteira sem alterar streak/última atividade.
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
