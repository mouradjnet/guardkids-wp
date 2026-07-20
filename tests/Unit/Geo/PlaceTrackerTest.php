<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Geo;

use GuardKids\Database\ChildPlaceRepository;
use GuardKids\Database\SafeZoneRepository;
use GuardKids\Geo\PlaceTracker;
use PHPUnit\Framework\TestCase;

final class PlaceTrackerTest extends TestCase
{
    private function tracker(): PlaceTracker
    {
        $zones = new class () extends SafeZoneRepository {
            public function __construct()
            {
            }
            public function findAll(string $orderBy = 'id', string $direction = 'ASC'): array
            {
                return [
                    ['id' => 1, 'name' => 'Escola', 'icon' => '🏫', 'latitude' => -8.0500, 'longitude' => -34.8800, 'radius_meters' => 100],
                    ['id' => 2, 'name' => 'Casa',   'icon' => '🏠', 'latitude' => -8.0700, 'longitude' => -34.8900, 'radius_meters' => 100],
                ];
            }
        };
        $place = new class () extends ChildPlaceRepository {
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];
            public function __construct()
            {
            }
            public function get(int $childId): ?array
            {
                return $this->rows[$childId] ?? null;
            }
            public function upsert(int $childId, array $data): void
            {
                $this->rows[$childId] = $data + ['child_id' => $childId];
            }
        };
        return new PlaceTracker($zones, $place);
    }

    public function testSingleFixDoesNotConfirm(): void
    {
        self::assertNull($this->tracker()->evaluate(9, -8.0500, -34.8800, null));
    }

    public function testTwoConsecutiveFixesConfirmEntered(): void
    {
        $t = $this->tracker();
        self::assertNull($t->evaluate(9, -8.0500, -34.8800, null));
        $ev = $t->evaluate(9, -8.0500, -34.8800, null);
        self::assertIsArray($ev);
        self::assertSame('entered', $ev['type']);
        self::assertSame(1, $ev['zoneId']);
        self::assertSame('Escola', $ev['placeName']);
    }

    public function testStableStateEmitsNothing(): void
    {
        $t = $this->tracker();
        $t->evaluate(9, -8.0500, -34.8800, null);
        $t->evaluate(9, -8.0500, -34.8800, null);
        self::assertNull($t->evaluate(9, -8.0500, -34.8800, null));
    }

    public function testLeavingToOutsideConfirmsLeft(): void
    {
        $t = $this->tracker();
        $t->evaluate(9, -8.0500, -34.8800, null);
        $t->evaluate(9, -8.0500, -34.8800, null);
        self::assertNull($t->evaluate(9, -8.2000, -34.9500, null));
        $ev = $t->evaluate(9, -8.2000, -34.9500, null);
        self::assertIsArray($ev);
        self::assertSame('left', $ev['type']);
        self::assertSame('Escola', $ev['placeName']);
    }
}
