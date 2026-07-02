<?php

declare(strict_types=1);

namespace GuardKids\Progression;

use DateTimeImmutable;
use GuardKids\Database\AwardRepository;
use GuardKids\Database\ProgressionRepository;

/**
 * Engine de ganho: cada conteúdo distinto aberto no dia rende XP/coins
 * (anti-farm via ledger). Streak por dias consecutivos com atividade, com
 * bônus de coins no primeiro ganho do dia.
 */
final class Progression
{
    private const XP_PER_OPEN = 10;
    private const COINS_PER_OPEN = 5;
    private const DAILY_BONUS_COINS = 5;

    private readonly ProgressionRepository $wallet;
    private readonly AwardRepository $awards;

    public function __construct(?ProgressionRepository $wallet = null, ?AwardRepository $awards = null)
    {
        $this->wallet = $wallet ?? new ProgressionRepository();
        $this->awards = $awards ?? new AwardRepository();
    }

    public function awardForOpen(int $childId, int $contentId, DateTimeImmutable $now): void
    {
        $date = $now->format('Y-m-d');
        if ($this->awards->existsFor($childId, $contentId, $date)) {
            return;
        }

        $wallet = $this->wallet->ensure($childId);
        $last = $wallet['last_activity_date'] ?? null;
        $yesterday = $now->modify('-1 day')->format('Y-m-d');

        if ($last === $date) {
            $streak = (int) $wallet['streak_days'];
            $bonus = 0;
        } elseif ($last === $yesterday) {
            $streak = (int) $wallet['streak_days'] + 1;
            $bonus = self::DAILY_BONUS_COINS;
        } else {
            $streak = 1;
            $bonus = self::DAILY_BONUS_COINS;
        }

        $this->awards->record($childId, $contentId, $date, self::XP_PER_OPEN, self::COINS_PER_OPEN);
        $this->wallet->apply($childId, self::XP_PER_OPEN, self::COINS_PER_OPEN + $bonus, $streak, $date);
    }
}
