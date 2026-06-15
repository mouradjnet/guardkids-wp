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
        return rest_ensure_response($this->publicBag($this->repo->all()));
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
            if (! self::isPublicKey($key)) {
                continue;
            }
            $this->repo->set($key, $value);
        }
        return rest_ensure_response($this->publicBag($this->repo->all()));
    }

    /**
     * Keys com `:` são reservadas pra storage interno de tokens
     * (`child_token:<hash>`, `companion_token:<hash>`) e jamais devem
     * ser leituradas/escritas via REST público — caso contrário um admin
     * com nonce comprometido (ou XSS num plugin terceiro) consegue forjar
     * tokens de criança calculando SHA-256 de um plaintext escolhido.
     */
    private static function isPublicKey(string $key): bool
    {
        return ! str_contains($key, ':');
    }

    /**
     * @param array<string, mixed> $bag
     * @return array<string, mixed>
     */
    private function publicBag(array $bag): array
    {
        return array_filter(
            $bag,
            static fn (string $key): bool => self::isPublicKey($key),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
