<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Geo\Geocoder;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /guardkids/v1/geocode?q=<endereço> — converte endereço em coordenada via
 * Nominatim (server-side). Só admin; resposta no-store (não cachear no edge).
 */
final class GeocodeController
{
    private readonly Geocoder $geocoder;

    public function __construct(?Geocoder $geocoder = null)
    {
        $this->geocoder = $geocoder ?? new Geocoder();
    }

    public function index(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $q = trim((string) $req->get_param('q'));
        if ($q === '') {
            return new WP_Error('missing_query', 'Informe um endereço.', ['status' => 400]);
        }

        $result = $this->geocoder->geocode($q);
        if ($result === null) {
            return new WP_Error('not_found', 'Endereço não encontrado.', ['status' => 404]);
        }

        $res = new WP_REST_Response($result, 200);
        $res->header('Cache-Control', 'no-store');
        return $res;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function indexArgs(): array
    {
        return [
            'q' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
        ];
    }
}
