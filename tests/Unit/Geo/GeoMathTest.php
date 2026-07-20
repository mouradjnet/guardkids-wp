<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Geo;

use GuardKids\Geo\GeoMath;
use PHPUnit\Framework\TestCase;

final class GeoMathTest extends TestCase
{
    public function testZeroDistanceForSamePoint(): void
    {
        self::assertSame(0.0, GeoMath::haversineMeters(-8.05, -34.88, -8.05, -34.88));
    }

    public function testKnownDistanceAboutOneDegreeLatitude(): void
    {
        // 1 grau de latitude ≈ 111.19 km. Tolerância de 1 km.
        $d = GeoMath::haversineMeters(0.0, 0.0, 1.0, 0.0);
        self::assertEqualsWithDelta(111_190.0, $d, 1_000.0);
    }

    public function testShortDistanceInMeters(): void
    {
        // ~100m ao norte em Recife.
        $d = GeoMath::haversineMeters(-8.0500, -34.8800, -8.0491, -34.8800);
        self::assertEqualsWithDelta(100.0, $d, 5.0);
    }
}
