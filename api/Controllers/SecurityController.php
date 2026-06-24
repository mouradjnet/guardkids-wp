<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Auth\ChildPin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Gestão do PIN dos pais (destrava o ambiente seguro no aparelho da criança).
 *
 * Auth: `requireAdmin` (igual às outras rotas do app-parent). O PIN em si
 * nunca trafega de volta — só expomos `pinSet` (se existe ou não).
 */
final class SecurityController
{
    private readonly ChildPin $pin;

    public function __construct(?ChildPin $pin = null)
    {
        $this->pin = $pin ?? new ChildPin();
    }

    public function status(): WP_REST_Response
    {
        return rest_ensure_response(['pinSet' => $this->pin->isSet()]);
    }

    public function setPin(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $pin = (string) $req->get_param('pin');
        if (! $this->pin->set($pin)) {
            return new WP_Error(
                'invalid_pin',
                'O PIN precisa ter de 4 a 6 dígitos numéricos.',
                ['status' => 422],
            );
        }
        return rest_ensure_response(['pinSet' => true]);
    }

    public function clearPin(): WP_REST_Response
    {
        $this->pin->clear();
        return rest_ensure_response(['pinSet' => false]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function setPinArgs(): array
    {
        return [
            'pin' => [
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }
}
