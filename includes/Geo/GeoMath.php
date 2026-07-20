<?php

declare(strict_types=1);

namespace GuardKids\Geo;

/**
 * Matemática geográfica pura (sem $wpdb, sem I/O). Distância entre dois pontos
 * pela fórmula de Haversine, em metros.
 */
final class GeoMath
{
    private const EARTH_RADIUS_M = 6_371_000.0;

    public static function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_M * $c;
    }
}
