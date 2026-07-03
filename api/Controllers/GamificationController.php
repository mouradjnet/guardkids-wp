<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Auth\ChildAuth;
use GuardKids\Database\MissionCompletionRepository;
use GuardKids\Database\ProgressionRepository;
use GuardKids\Progression\LevelCurve;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Endpoints da gamificação (fatia 3a): carteira/progressão do filho (token)
 * e visão dos pais por filho (admin).
 */
final class GamificationController
{
    private readonly ProgressionRepository $progression;
    private readonly MissionCompletionRepository $missions;
    private readonly ChildAuth $auth;

    public function __construct()
    {
        $this->progression = new ProgressionRepository();
        $this->missions = new MissionCompletionRepository();
        $this->auth        = new ChildAuth();
    }

    public function childProgression(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        return rest_ensure_response($this->walletJson($childId));
    }

    public function progression(WP_REST_Request $req): WP_REST_Response
    {
        $childId = (int) $req->get_param('child_id');
        $w = $this->walletJson($childId);
        return rest_ensure_response([
            'xp'                => $w['xp'],
            'coins'             => $w['coins'],
            'level'             => $w['level'],
            'streakDays'        => $w['streakDays'],
            'missionsCompleted' => $this->missions->countCompleted($childId),
        ]);
    }

    /**
     * @return array{xp:int, coins:int, level:int, xpIntoLevel:int, xpForNextLevel:int, streakDays:int}
     */
    private function walletJson(int $childId): array
    {
        $row = $this->progression->findByChild($childId);
        $xp     = $row !== null ? (int) $row['xp'] : 0;
        $coins  = $row !== null ? (int) $row['coins'] : 0;
        $streak = $row !== null ? (int) $row['streak_days'] : 0;
        $p = LevelCurve::progressInLevel($xp);
        return [
            'xp'             => $xp,
            'coins'          => $coins,
            'level'          => $p['level'],
            'xpIntoLevel'    => $p['xpIntoLevel'],
            'xpForNextLevel' => $p['xpForNextLevel'],
            'streakDays'     => $streak,
        ];
    }
}
