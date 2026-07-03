<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Missions;

use GuardKids\Missions\MissionCatalog;
use PHPUnit\Framework\TestCase;

final class MissionCatalogTest extends TestCase
{
    public function testHasThreeCuratedMissions(): void
    {
        $keys = array_column(MissionCatalog::all(), 'key');
        self::assertSame(['explore_3', 'categories_2', 'streak_today'], $keys);
    }

    public function testEachMissionHasTargetAndRewards(): void
    {
        foreach (MissionCatalog::all() as $m) {
            self::assertArrayHasKey('title', $m);
            self::assertArrayHasKey('description', $m);
            self::assertArrayHasKey('icon', $m);
            self::assertGreaterThan(0, $m['target']);
            self::assertGreaterThan(0, $m['xpReward']);
            self::assertGreaterThan(0, $m['coinsReward']);
        }
    }

    public function testTargetsMatchCatalog(): void
    {
        $byKey = array_column(MissionCatalog::all(), 'target', 'key');
        self::assertSame(3, $byKey['explore_3']);
        self::assertSame(2, $byKey['categories_2']);
        self::assertSame(1, $byKey['streak_today']);
    }
}
