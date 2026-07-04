<?php

declare(strict_types=1);

namespace GuardKids\Avatars;

use GuardKids\Medals\MedalCatalog;

/**
 * Avaliação pura: recebe nível + medalhas desbloqueadas e devolve cada avatar
 * com unlocked + requirementLabel. Não toca no banco.
 */
final class AvatarEvaluator
{
    /**
     * @param array{level:int, unlockedMedals:array<int,string>} $signals
     * @return array<int, array{key:string, emoji:string, label:string, gate:string, requirementLabel:string, unlocked:bool}>
     */
    public static function evaluate(array $signals): array
    {
        $level          = (int) ($signals['level'] ?? 0);
        $unlockedMedals = $signals['unlockedMedals'] ?? [];
        $medalLabels    = self::medalLabels();

        $out = [];
        foreach (AvatarCatalog::all() as $a) {
            if ($a['gate'] === 'free') {
                $unlocked = true;
                $label    = 'Grátis';
            } elseif ($a['gate'] === 'level') {
                $unlocked = $level >= $a['threshold'];
                $label    = 'Nível ' . $a['threshold'];
            } else {
                $unlocked = in_array($a['medalKey'], $unlockedMedals, true);
                $label    = 'Medalha ' . ($medalLabels[$a['medalKey']] ?? (string) $a['medalKey']);
            }
            $out[] = [
                'key'              => $a['key'],
                'emoji'            => $a['emoji'],
                'label'            => $a['label'],
                'gate'             => $a['gate'],
                'requirementLabel' => $label,
                'unlocked'         => $unlocked,
            ];
        }
        return $out;
    }

    /**
     * @return array<string, string>
     */
    private static function medalLabels(): array
    {
        $map = [];
        foreach (MedalCatalog::all() as $m) {
            $map[$m['key']] = $m['title'];
        }
        return $map;
    }
}
