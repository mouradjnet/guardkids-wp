<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Medals;

use GuardKids\Medals\MedalCatalog;
use PHPUnit\Framework\TestCase;

final class MedalCatalogTest extends TestCase
{
    public function testHasSixCuratedMedals(): void
    {
        $keys = array_column(MedalCatalog::all(), 'key');
        self::assertSame(
            ['explorer_10', 'devourer_50', 'achiever_10', 'faithful_7', 'veteran_10', 'curious_master_5'],
            $keys,
        );
    }

    public function testEachMedalHasSignalTargetAndRewards(): void
    {
        $validSignals = ['level', 'streakDays', 'totalContentOpened', 'totalMissionsCompleted', 'distinctCategoriesAllTime'];
        foreach (MedalCatalog::all() as $m) {
            self::assertArrayHasKey('title', $m);
            self::assertArrayHasKey('description', $m);
            self::assertArrayHasKey('icon', $m);
            self::assertContains($m['signal'], $validSignals);
            self::assertGreaterThan(0, $m['target']);
            self::assertGreaterThan(0, $m['xpReward']);
            self::assertGreaterThan(0, $m['coinsReward']);
        }
    }

    public function testSignalsAndTargetsMatchCatalog(): void
    {
        $bySignal = [];
        $byTarget = [];
        foreach (MedalCatalog::all() as $m) {
            $bySignal[$m['key']] = $m['signal'];
            $byTarget[$m['key']] = $m['target'];
        }
        self::assertSame('totalContentOpened', $bySignal['explorer_10']);
        self::assertSame(10, $byTarget['explorer_10']);
        self::assertSame('totalContentOpened', $bySignal['devourer_50']);
        self::assertSame(50, $byTarget['devourer_50']);
        self::assertSame('totalMissionsCompleted', $bySignal['achiever_10']);
        self::assertSame('streakDays', $bySignal['faithful_7']);
        self::assertSame('level', $bySignal['veteran_10']);
        self::assertSame('distinctCategoriesAllTime', $bySignal['curious_master_5']);
        self::assertSame(5, $byTarget['curious_master_5']);
    }
}
