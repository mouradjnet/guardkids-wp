<?php

declare(strict_types=1);

namespace GuardKids\Progression;

/**
 * Curva de nível 1..100 (pura, sem $wpdb).
 *
 * Subir de L → L+1 custa 100*L XP; o total acumulado para atingir o nível L
 * é 50*L*(L-1) (L1=0, L2=100, L3=300, L10=4500, L100=495000).
 */
final class LevelCurve
{
    private const MAX = 100;

    public static function totalToReach(int $level): int
    {
        return 50 * $level * ($level - 1);
    }

    public static function levelForXp(int $xp): int
    {
        if ($xp <= 0) {
            return 1;
        }
        for ($l = self::MAX; $l >= 1; $l--) {
            if ($xp >= self::totalToReach($l)) {
                return $l;
            }
        }
        return 1;
    }

    /**
     * @return array{level:int, xpIntoLevel:int, xpForNextLevel:int}
     */
    public static function progressInLevel(int $xp): array
    {
        $level = self::levelForXp($xp);
        return [
            'level'          => $level,
            'xpIntoLevel'    => $xp - self::totalToReach($level),
            'xpForNextLevel' => $level >= self::MAX ? 0 : 100 * $level,
        ];
    }
}
