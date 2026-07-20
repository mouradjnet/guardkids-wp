<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Geo;

use GuardKids\Geo\Geocoder;
use PHPUnit\Framework\TestCase;

final class GeocoderTest extends TestCase
{
    public function testParsesFirstResult(): void
    {
        $geocoder = new class () extends Geocoder {
            protected function fetch(string $query): ?string
            {
                return '[{"lat":"-8.0501","lon":"-34.8811","display_name":"Rua X, Recife"}]';
            }
            protected function cacheGet(string $key): mixed
            {
                return false;
            }
            protected function cacheSet(string $key, mixed $value): void
            {
            }
        };
        $r = $geocoder->geocode('Rua X, Recife');
        self::assertNotNull($r);
        self::assertEqualsWithDelta(-8.0501, $r['lat'], 0.0001);
        self::assertEqualsWithDelta(-34.8811, $r['lng'], 0.0001);
        self::assertSame('Rua X, Recife', $r['displayName']);
    }

    public function testReturnsNullOnEmptyResult(): void
    {
        $geocoder = new class () extends Geocoder {
            protected function fetch(string $query): ?string
            {
                return '[]';
            }
            protected function cacheGet(string $key): mixed
            {
                return false;
            }
            protected function cacheSet(string $key, mixed $value): void
            {
            }
        };
        self::assertNull($geocoder->geocode('inexistente'));
    }

    public function testReturnsNullOnHttpError(): void
    {
        $geocoder = new class () extends Geocoder {
            protected function fetch(string $query): ?string
            {
                return null;
            }
            protected function cacheGet(string $key): mixed
            {
                return false;
            }
            protected function cacheSet(string $key, mixed $value): void
            {
            }
        };
        self::assertNull($geocoder->geocode('qualquer'));
    }
}
