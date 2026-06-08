<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\License\Gate;
use GuardKids\License\Payload;
use GuardKids\License\Verifier;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Expõe a licença premium via REST. Único entrypoint que mexe na option
 * `guardkids_license` — UI sempre passa por aqui, nunca grava direto.
 *
 * Auth: `manage_options` (configurado em RestApi). O lado infantil não tem
 * rota nenhuma aqui — child não enxerga licença.
 */
final class LicenseController
{
    private readonly Gate $gate;
    private readonly Verifier $verifier;

    public function __construct(?Gate $gate = null, ?Verifier $verifier = null)
    {
        $this->verifier = $verifier ?? new Verifier();
        $this->gate     = $gate ?? new Gate($this->verifier);
    }

    public function index(): WP_REST_Response
    {
        return rest_ensure_response($this->snapshot());
    }

    /**
     * POST /license — ativa uma chave. Persiste primeiro pra Gate re-validar
     * contra siteurl + revogação atual; se algo falhar, faz rollback.
     */
    public function activate(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $key = trim((string) $req->get_param('key'));
        if ($key === '') {
            return new WP_Error(
                'invalid_payload',
                'Campo "key" é obrigatório.',
                ['status' => 422]
            );
        }

        $payload = $this->verifier->verify($key);
        if ($payload === null) {
            return new WP_Error(
                'invalid_license',
                'Chave inválida ou assinatura corrompida.',
                ['status' => 422]
            );
        }

        update_option('guardkids_license', [
            'key_b64'      => $key,
            'activated_at' => current_time('mysql', true),
        ]);

        $status = $this->gate->status();
        if ($status !== 'active') {
            delete_option('guardkids_license');
            return new WP_Error(
                'license_' . $status,
                $this->messageFor($status),
                ['status' => 422]
            );
        }

        return rest_ensure_response($this->snapshot());
    }

    public function deactivate(): WP_REST_Response
    {
        delete_option('guardkids_license');
        return rest_ensure_response($this->snapshot());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function activateArgs(): array
    {
        return [
            'key' => [
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /**
     * @return array{
     *     plan: string, status: string,
     *     features: list<string>,
     *     expiresAt: string|null, daysLeft: int|null,
     *     email: string|null, activatedAt: string|null,
     *     upgradeUrl: string|null,
     * }
     */
    private function snapshot(): array
    {
        $payload = $this->gate->payload();

        return [
            'plan'        => $this->gate->plan(),
            'status'      => $this->gate->status(),
            'features'    => $payload?->features ?? [],
            'expiresAt'   => $payload !== null ? gmdate('Y-m-d\TH:i:s\Z', $payload->exp) : null,
            'daysLeft'    => $this->gate->daysLeft(),
            'email'       => $payload?->email,
            'activatedAt' => $this->readActivatedAt(),
            'upgradeUrl'  => $this->readUpgradeUrl(),
        ];
    }

    private function readActivatedAt(): ?string
    {
        $stored = get_option('guardkids_license', null);
        if (\is_array($stored) && isset($stored['activated_at']) && \is_string($stored['activated_at'])) {
            return $stored['activated_at'];
        }
        return null;
    }

    private function readUpgradeUrl(): ?string
    {
        $url = get_option('guardkids_upgrade_url', '');
        return \is_string($url) && $url !== '' ? $url : null;
    }

    private function messageFor(string $status): string
    {
        return match ($status) {
            'domain_mismatch' => 'Esta chave foi emitida pra outro domínio. Peça uma nova ou desative no domínio anterior.',
            'expired'         => 'Esta chave já expirou. Solicite a renovação.',
            'revoked'         => 'Esta chave foi revogada. Entre em contato com o suporte.',
            default           => 'Não foi possível ativar a licença.',
        };
    }
}
