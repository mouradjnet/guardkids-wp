<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Database\LocationRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /locations?child_id=&limit= — leitura pelo parent (nonce auth).
 *
 * Append-only: posições retornam ordenadas decrescentes por recorded_at.
 */
final class LocationController
{
    private readonly LocationRepository $repo;

    public function __construct()
    {
        $this->repo = new LocationRepository();
    }

    public function index(WP_REST_Request $req): WP_REST_Response
    {
        $childId = (int) $req->get_param('child_id');
        $limit   = (int) ($req->get_param('limit') ?? 1);

        if ($childId <= 0) {
            return new WP_REST_Response([], 200);
        }

        $rows = $this->repo->findByChildId($childId, $limit);
        return new WP_REST_Response(array_map([$this, 'toJson'], $rows), 200);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toJson(array $row): array
    {
        $recordedAt = (string) ($row['recorded_at'] ?? '');
        $isoUtc = $recordedAt !== '' ? gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($recordedAt)) : null;

        return [
            'id'         => (int) ($row['id'] ?? 0),
            'childId'    => (int) ($row['child_id'] ?? 0),
            'latitude'   => isset($row['latitude'])  ? (float) $row['latitude']  : 0.0,
            'longitude'  => isset($row['longitude']) ? (float) $row['longitude'] : 0.0,
            'accuracy'   => isset($row['accuracy'])  ? (int) $row['accuracy']    : null,
            'battery'    => isset($row['battery'])   ? (int) $row['battery']     : null,
            'recordedAt' => $isoUtc,
        ];
    }
}
