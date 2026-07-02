<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Progression;

use GuardKids\Progression\LevelCurve;
use PHPUnit\Framework\TestCase;

final class LevelCurveTest extends TestCase
{
    public function testLevelForXpKeyPoints(): void
    {
        self::assertSame(1, LevelCurve::levelForXp(0));
        self::assertSame(1, LevelCurve::levelForXp(99));
        self::assertSame(2, LevelCurve::levelForXp(100));
        self::assertSame(3, LevelCurve::levelForXp(300));
        self::assertSame(10, LevelCurve::levelForXp(4500));
        self::assertSame(100, LevelCurve::levelForXp(495000));
        self::assertSame(100, LevelCurve::levelForXp(999999999));
    }

    public function testProgressInLevel(): void
    {
        $p = LevelCurve::progressInLevel(150);
        self::assertSame(2, $p['level']);
        self::assertSame(50, $p['xpIntoLevel']);      // 150 - 100
        self::assertSame(200, $p['xpForNextLevel']);  // 100 * 2

        $max = LevelCurve::progressInLevel(495000);
        self::assertSame(100, $max['level']);
        self::assertSame(0, $max['xpForNextLevel']);
    }
}
