<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Missions;

use GuardKids\Missions\MissionEvaluator;
use PHPUnit\Framework\TestCase;

final class MissionEvaluatorTest extends TestCase
{
    /** @param array{contentOpenedToday:int,categoriesToday:int,streakActiveToday:bool} $signals */
    private function progressOf(array $signals, string $key): array
    {
        foreach (MissionEvaluator::evaluate($signals) as $m) {
            if ($m['key'] === $key) {
                return $m;
            }
        }
        self::fail("mission {$key} not found");
    }

    public function testAllZeroWhenNothingDone(): void
    {
        $signals = ['contentOpenedToday' => 0, 'categoriesToday' => 0, 'streakActiveToday' => false];
        foreach (MissionEvaluator::evaluate($signals) as $m) {
            self::assertSame(0, $m['progress']);
            self::assertFalse($m['completed']);
        }
    }

    public function testPartialProgressNotCompleted(): void
    {
        $signals = ['contentOpenedToday' => 2, 'categoriesToday' => 1, 'streakActiveToday' => false];
        self::assertSame(2, $this->progressOf($signals, 'explore_3')['progress']);
        self::assertFalse($this->progressOf($signals, 'explore_3')['completed']);
    }

    public function testExactTargetCompletes(): void
    {
        $signals = ['contentOpenedToday' => 3, 'categoriesToday' => 2, 'streakActiveToday' => true];
        foreach (MissionEvaluator::evaluate($signals) as $m) {
            self::assertTrue($m['completed'], $m['key']);
        }
    }

    public function testProgressClampedToTarget(): void
    {
        $signals = ['contentOpenedToday' => 10, 'categoriesToday' => 9, 'streakActiveToday' => true];
        self::assertSame(3, $this->progressOf($signals, 'explore_3')['progress']);
        self::assertSame(2, $this->progressOf($signals, 'categories_2')['progress']);
        self::assertSame(1, $this->progressOf($signals, 'streak_today')['progress']);
    }

    public function testStreakBooleanMapsToProgress(): void
    {
        $on  = ['contentOpenedToday' => 0, 'categoriesToday' => 0, 'streakActiveToday' => true];
        $off = ['contentOpenedToday' => 0, 'categoriesToday' => 0, 'streakActiveToday' => false];
        self::assertTrue($this->progressOf($on, 'streak_today')['completed']);
        self::assertFalse($this->progressOf($off, 'streak_today')['completed']);
    }
}
