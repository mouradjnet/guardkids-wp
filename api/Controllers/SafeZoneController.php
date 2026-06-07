<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Database\SafeZoneRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * CRUD de zonas seguras (parent, nonce auth).
 *
 * Zonas são globais nesta fase — uma "Casa" vale pra todos os filhos.
 * Per-child só entra se houver demanda real.
 */
final class SafeZoneController
{
    private readonly SafeZoneRepository $repo;

    public function __construct()
    {
        $this->repo = new SafeZoneRepository();
    }

    public function index(): WP_REST_Response
    {
        $rows = $this->repo->findAll('name', 'ASC');
        return new WP_REST_Response(array_map([$this, 'toJson'], $rows), 200);
    }

    public function create(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $data = $this->extractData($req);
        $id   = $this->repo->insert($data);

        if ($id === 0) {
            return new WP_Error('db_error', 'Não foi possível salvar.', ['status' => 500]);
        }

        $row = $this->repo->findById($id);
        return new WP_REST_Response($this->toJson($row ?? []), 201);
    }

    public function update(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id = (int) $req['id'];
        $existing = $this->repo->findById($id);
        if ($existing === null) {
            return new WP_Error('not_found', 'Zona não encontrada.', ['status' => 404]);
        }

        $data = $this->extractData($req);
        if (! $this->repo->update($id, $data)) {
            return new WP_Error('db_error', 'Falha ao salvar.', ['status' => 500]);
        }

        $row = $this->repo->findById($id);
        return new WP_REST_Response($this->toJson($row ?? []), 200);
    }

    public function destroy(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id = (int) $req['id'];
        if ($this->repo->findById($id) === null) {
            return new WP_Error('not_found', 'Zona não encontrada.', ['status' => 404]);
        }
        $this->repo->delete($id);
        return new WP_REST_Response(null, 204);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function createArgs(): array
    {
        return [
            'name' => [
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'address' => [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'latitude' => [
                'type'     => 'number',
                'required' => true,
                'minimum'  => -90,
                'maximum'  => 90,
            ],
            'longitude' => [
                'type'     => 'number',
                'required' => true,
                'minimum'  => -180,
                'maximum'  => 180,
            ],
            'radius_meters' => [
                'type'     => 'integer',
                'required' => true,
                'minimum'  => 10,
                'maximum'  => 5000,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function updateArgs(): array
    {
        return $this->createArgs();
    }

    /**
     * @return array<string, mixed>
     */
    private function extractData(WP_REST_Request $req): array
    {
        $address = $req->get_param('address');
        return [
            'name'          => (string) $req->get_param('name'),
            'address'       => is_string($address) && $address !== '' ? $address : null,
            'latitude'      => (float) $req->get_param('latitude'),
            'longitude'     => (float) $req->get_param('longitude'),
            'radius_meters' => (int) $req->get_param('radius_meters'),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toJson(array $row): array
    {
        return [
            'id'           => (int) ($row['id'] ?? 0),
            'name'         => (string) ($row['name'] ?? ''),
            'address'      => $row['address'] ?? null,
            'latitude'     => isset($row['latitude'])  ? (float) $row['latitude']  : 0.0,
            'longitude'    => isset($row['longitude']) ? (float) $row['longitude'] : 0.0,
            'radiusMeters' => (int) ($row['radius_meters'] ?? 100),
            'createdAt'    => $row['created_at'] ?? null,
            'updatedAt'    => $row['updated_at'] ?? null,
        ];
    }
}
