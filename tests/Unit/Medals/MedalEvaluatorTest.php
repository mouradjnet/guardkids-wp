<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Medals;

use GuardKids\Medals\MedalEvaluator;
use PHPUnit\Framework\TestCase;

final class MedalEvaluatorTest extends TestCase
{
    /** @return array<string, int> */
    private function signals(int $level = 0, int $streak = 0, int $opened = 0, int $missions = 0, int $categories = 0): array
    {
        return [
            'level'                     => $level,
            'streakDays'                => $streak,
            'totalContentOpened'        => $opened,
            'totalMissionsCompleted'    => $missions,
            'distinctCategoriesAllTime' => $categories,
        ];
    }

    /** @param array<string,int> $signals */
    private function medal(array $signals, string $key): array
    {
        foreach (MedalEvaluator::evaluate($signals) as $m) {
            if ($m['key'] === $key) {
                return $m;
            }
        }
        self::fail("medal {$key} not found");
    }

    public function testAllLockedWhenNothingDone(): void
    {
        foreach (MedalEvaluator::evaluate($this->signals()) as $m) {
            self::assertSame(0, $m['progress']);
            self::assertFalse($m['unlocked']);
        }
    }

    public function testContentAxisTwoThresholds(): void
    {
        $s = $this->signals(opened: 10);
        self::assertTrue($this->medal($s, 'explorer_10')['unlocked']);
        self::assertSame(10, $this->medal($s, 'explorer_10')['progress']);
        self::assertFalse($this->medal($s, 'devourer_50')['unlocked']);
        self::assertSame(10, $this->medal($s, 'devourer_50')['progress']);
    }

    public function testEachSignalMapsToItsMedal(): void
    {
        self::assertTrue($this->medal($this->signals(missions: 10), 'achiever_10')['unlocked']);
        self::assertTrue($this->medal($this->signals(streak: 7), 'faithful_7')['unlocked']);
        self::assertTrue($this->medal($this->signals(level: 10), 'veteran_10')['unlocked']);
        self::assertTrue($this->medal($this->signals(categories: 5), 'curious_master_5')['unlocked']);
    }

    public function testProgressClampedToTarget(): void
    {
        $s = $this->signals(opened: 999, level: 99, streak: 99, missions: 99, categories: 99);
        self::assertSame(10, $this->medal($s, 'explorer_10')['progress']);
        self::assertSame(50, $this->medal($s, 'devourer_50')['progress']);
        self::assertSame(5, $this->medal($s, 'curious_master_5')['progress']);
    }

    public function testPartialProgressNotUnlocked(): void
    {
        $s = $this->signals(missions: 9);
        self::assertSame(9, $this->medal($s, 'achiever_10')['progress']);
        self::assertFalse($this->medal($s, 'achiever_10')['unlocked']);
    }
}
