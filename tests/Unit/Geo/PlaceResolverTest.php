<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Geo;

use GuardKids\Geo\PlaceResolver;
use PHPUnit\Framework\TestCase;

final class PlaceResolverTest extends TestCase
{
    /** @return array<int, array{id:int, latitude:float, longitude:float, radius_meters:int}> */
    private function zones(): array
    {
        return [
            ['id' => 1, 'latitude' => -8.0500, 'longitude' => -34.8800, 'radius_meters' => 100], // Casa
            ['id' => 2, 'latitude' => -8.0500, 'longitude' => -34.8800, 'radius_meters' => 500], // Bairro (engloba a Casa)
            ['id' => 3, 'latitude' => -8.0700, 'longitude' => -34.8900, 'radius_meters' => 100], // Escola (longe)
        ];
    }

    public function testReturnsNullWhenOutsideEveryZone(): void
    {
        self::assertNull(PlaceResolver::resolve(-8.2000, -34.9500, null, $this->zones()));
    }

    public function testSmallestRadiusWinsOnOverlap(): void
    {
        // No centro exato de Casa+Bairro → vence Casa (raio 100 < 500).
        self::assertSame(1, PlaceResolver::resolve(-8.0500, -34.8800, null, $this->zones()));
    }

    public function testFallsBackToLargerZoneWhenOutsideSmaller(): void
    {
        // ~300m do centro: fora da Casa (100m), dentro do Bairro (500m).
        self::assertSame(2, PlaceResolver::resolve(-8.0473, -34.8800, null, $this->zones()));
    }

    public function testIgnoresCandidateWhenAccuracyWorseThanRadius(): void
    {
        // No centro da Casa, mas accuracy 300m > raio 100m da Casa → Casa ignorada;
        // Bairro (500m) ainda vale porque 300 <= 500.
        self::assertSame(2, PlaceResolver::resolve(-8.0500, -34.8800, 300, $this->zones()));
    }

    public function testReturnsNullWhenAccuracyWorseThanAllRadii(): void
    {
        self::assertNull(PlaceResolver::resolve(-8.0500, -34.8800, 600, $this->zones()));
    }
}
