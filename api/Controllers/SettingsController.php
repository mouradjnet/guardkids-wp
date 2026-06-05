<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Database\SettingsRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class SettingsController
{
    private readonly SettingsRepository $repo;

    public function __construct()
    {
        $this->repo = new SettingsRepository();
    }

    public function index(): WP_REST_Response
    {
        return rest_ensure_response($this->repo->all());
    }

    public function update(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $payload = $req->get_json_params();
        if (! is_array($payload) || $payload === []) {
            return new WP_Error('invalid_payload', 'Esperado objeto JSON com pares chave/valor.', ['status' => 422]);
        }
        foreach ($payload as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            $this->repo->set($key, $value);
        }
        return rest_ensure_response($this->repo->all());
    }
}
