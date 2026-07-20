<?php

declare(strict_types=1);

namespace GuardKids\Geo;

use GuardKids\Database\ChildPlaceRepository;
use GuardKids\Database\SafeZoneRepository;

/**
 * Orquestra o geofencing por filho: resolve a zona observada, aplica a máquina
 * de confirmação (anti-spam) sobre child_place e emite a transição confirmada.
 *
 * Puro no sentido de que I/O é só pelos repos injetados; a lógica de estado é
 * testável com repos fakes.
 */
final class PlaceTracker
{
    /** Fixes seguidos no mesmo estado antes de confirmar uma transição. */
    public const CONFIRM_FIXES = 2;

    public function __construct(
        private readonly SafeZoneRepository $zones,
        private readonly ChildPlaceRepository $places,
    ) {
    }

    /**
     * @return array{type:string, zoneId:int, placeName:string, icon:string, token:string}|null
     */
    public function evaluate(int $childId, float $lat, float $lng, ?int $accuracy): ?array
    {
        $zoneRows = $this->zones->findAll('id', 'ASC');
        $observed = PlaceResolver::resolve($lat, $lng, $accuracy, $zoneRows);

        $state = $this->places->get($childId);
        $current = isset($state['current_zone_id']) && $state['current_zone_id'] !== null
            ? (int) $state['current_zone_id']
            : null;
        $pending = isset($state['pending_zone_id']) && $state['pending_zone_id'] !== null
            ? (int) $state['pending_zone_id']
            : null;
        $pendingCount = (int) ($state['pending_count'] ?? 0);
        $currentSince = $state['current_since'] ?? null;

        // Estável: observado == atual. Zera qualquer candidato.
        if ($observed === $current) {
            $this->places->upsert($childId, [
                'current_zone_id' => $current,
                'current_since'   => $currentSince,
                'pending_zone_id' => null,
                'pending_count'   => 0,
                'pending_since'   => null,
            ]);
            return null;
        }

        // Candidato diferente do atual.
        if ($observed === $pending) {
            $pendingCount++;
        } else {
            $pending = $observed;
            $pendingCount = 1;
        }

        if ($pendingCount < self::CONFIRM_FIXES) {
            $this->places->upsert($childId, [
                'current_zone_id' => $current,
                'current_since'   => $currentSince,
                'pending_zone_id' => $pending,
                'pending_count'   => $pendingCount,
                'pending_since'   => current_time('mysql', true),
            ]);
            return null;
        }

        // Confirma a transição.
        $now = current_time('mysql', true);
        $leftZoneId = $current;
        $this->places->upsert($childId, [
            'current_zone_id' => $observed,
            'current_since'   => $now,
            'pending_zone_id' => null,
            'pending_count'   => 0,
            'pending_since'   => null,
        ]);

        if ($observed !== null) {
            $zone = $this->zoneById($zoneRows, $observed);
            return [
                'type'      => 'entered',
                'zoneId'    => $observed,
                'placeName' => (string) ($zone['name'] ?? ''),
                'icon'      => (string) ($zone['icon'] ?? ''),
                'token'     => $now,
            ];
        }

        $zone = $this->zoneById($zoneRows, (int) $leftZoneId);
        return [
            'type'      => 'left',
            'zoneId'    => (int) $leftZoneId,
            'placeName' => (string) ($zone['name'] ?? ''),
            'icon'      => (string) ($zone['icon'] ?? ''),
            'token'     => $now,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $zoneRows
     * @return array<string, mixed>
     */
    private function zoneById(array $zoneRows, int $id): array
    {
        foreach ($zoneRows as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }
        return [];
    }
}
