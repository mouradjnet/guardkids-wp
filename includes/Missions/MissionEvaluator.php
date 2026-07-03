<?php

declare(strict_types=1);

namespace GuardKids\Missions;

/**
 * Avaliação pura: recebe os sinais já computados e devolve o estado de cada
 * missão (progress clampado ao alvo + completed). Não toca no banco.
 */
final class MissionEvaluator
{
    /**
     * @param array{contentOpenedToday:int, categoriesToday:int, streakActiveToday:bool} $signals
     * @return array<int, array{key:string, title:string, description:string, icon:string, target:int, progress:int, completed:bool, xpReward:int, coinsReward:int}>
     */
    public static function evaluate(array $signals): array
    {
        $out = [];
        foreach (MissionCatalog::all() as $m) {
            $raw = match ($m['key']) {
                'explore_3'    => $signals['contentOpenedToday'],
                'categories_2' => $signals['categoriesToday'],
                'streak_today' => $signals['streakActiveToday'] ? 1 : 0,
                default        => 0,
            };
            $progress = min((int) $raw, $m['target']);
            $out[] = [
                'key'         => $m['key'],
                'title'       => $m['title'],
                'description' => $m['description'],
                'icon'        => $m['icon'],
                'target'      => $m['target'],
                'progress'    => $progress,
                'completed'   => $progress >= $m['target'],
                'xpReward'    => $m['xpReward'],
                'coinsReward' => $m['coinsReward'],
            ];
        }
        return $out;
    }
}
