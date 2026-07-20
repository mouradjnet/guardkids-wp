<?php

declare(strict_types=1);

namespace GuardKids\Geo;

/**
 * Resolve em qual Local (safe_zone) um fix GPS caiu. Puro: recebe o fix e a
 * lista de zonas, devolve o id da zona observada ou null (fora de tudo).
 *
 * Sobreposição: vence o menor raio (mais específico); empate → menor distância.
 * accuracy > raio: candidato ignorado (fix impreciso demais pra um raio pequeno).
 */
final class PlaceResolver
{
    /**
     * @param array<int, array{id:int, latitude:float, longitude:float, radius_meters:int}> $zones
     */
    public static function resolve(float $lat, float $lng, ?int $accuracy, array $zones): ?int
    {
        $best = null; // ['id'=>int,'radius'=>int,'dist'=>float]

        foreach ($zones as $zone) {
            $radius = (int) $zone['radius_meters'];
            if ($accuracy !== null && $accuracy > $radius) {
                continue; // impreciso demais pra confiar neste raio
            }
            $dist = GeoMath::haversineMeters($lat, $lng, (float) $zone['latitude'], (float) $zone['longitude']);
            if ($dist > $radius) {
                continue; // fora deste Local
            }
            if (
                $best === null
                || $radius < $best['radius']
                || ($radius === $best['radius'] && $dist < $best['dist'])
            ) {
                $best = ['id' => (int) $zone['id'], 'radius' => $radius, 'dist' => $dist];
            }
        }

        return $best['id'] ?? null;
    }
}
