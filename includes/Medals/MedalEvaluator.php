<?php

declare(strict_types=1);

namespace GuardKids\Medals;

/**
 * Avaliação pura: recebe os sinais acumulados e devolve o estado de cada
 * medalha (progress clampado ao alvo + unlocked). Mapeia pelo campo `signal`
 * do catálogo. Não toca no banco.
 */
final class MedalEvaluator
{
    /**
     * @param array{level:int, streakDays:int, totalContentOpened:int, totalMissionsCompleted:int, distinctCategoriesAllTime:int} $signals
     * @return array<int, array{key:string, title:string, description:string, icon:string, target:int, progress:int, unlocked:bool}>
     */
    public static function evaluate(array $signals): array
    {
        $out = [];
        foreach (MedalCatalog::all() as $m) {
            $raw      = (int) ($signals[$m['signal']] ?? 0);
            $progress = min($raw, $m['target']);
            $out[] = [
                'key'         => $m['key'],
                'title'       => $m['title'],
                'description' => $m['description'],
                'icon'        => $m['icon'],
                'target'      => $m['target'],
                'progress'    => $progress,
                'unlocked'    => $progress >= $m['target'],
            ];
        }
        return $out;
    }
}
