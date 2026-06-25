<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Security\RateLimiter;
use GuardKids\Security\RecoveryCodes;
use GuardKids\Security\Totp;
use GuardKids\Security\TwoFactorStore;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * 2FA (TOTP) do usuário logado. Auth via `requireAdmin` (nonce). O segredo
 * nunca volta depois de ativado; os códigos de recuperação só aparecem uma vez.
 */
final class TwoFactorController
{
    private readonly Totp $totp;
    private readonly RecoveryCodes $recovery;

    public function __construct(
        private readonly ?TwoFactorStore $store = null,
        ?Totp $totp = null,
        ?RecoveryCodes $recovery = null,
    ) {
        $this->totp     = $totp ?? new Totp();
        $this->recovery = $recovery ?? new RecoveryCodes();
    }

    private function store(): TwoFactorStore
    {
        return $this->store ?? new TwoFactorStore(\get_current_user_id());
    }

    public function status(): WP_REST_Response
    {
        $store = $this->store();
        return \rest_ensure_response([
            'enabled'           => $store->isEnabled(),
            'recoveryRemaining' => count($store->getRecoveryHashes()),
        ]);
    }

    public function setup(): WP_REST_Response
    {
        $store  = $this->store();
        $secret = $this->totp->generateSecret();
        $store->setPendingSecret($secret);

        $user   = \wp_get_current_user();
        $label  = $user->user_email !== '' ? $user->user_email : $user->user_login;
        $issuer = (string) \get_bloginfo('name');

        return \rest_ensure_response([
            'secret'     => $secret,
            'otpauthUri' => $this->totp->provisioningUri($secret, (string) $label, $issuer),
        ]);
    }

    public function activate(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $store = $this->store();
        if (! $this->rateOk()) {
            return $this->tooMany();
        }
        $secret = $store->getPendingSecret();
        $code   = (string) $req->get_param('code');
        if ($secret === '' || ! $this->totp->verify($secret, $code)) {
            return new WP_Error('invalid_code', 'Código inválido. Confira o app autenticador.', ['status' => 422]);
        }
        $codes = $this->recovery->generate();
        $store->enable($secret, $this->recovery->hashAll($codes));
        return \rest_ensure_response(['enabled' => true, 'recoveryCodes' => $codes]);
    }

    public function regenerateRecovery(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $store = $this->store();
        $err   = $this->requireValidCode($store, (string) $req->get_param('code'));
        if ($err instanceof WP_Error) {
            return $err;
        }
        $codes = $this->recovery->generate();
        $store->setRecoveryHashes($this->recovery->hashAll($codes));
        return \rest_ensure_response(['recoveryCodes' => $codes]);
    }

    public function disable(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $store = $this->store();
        $err   = $this->requireValidCode($store, (string) $req->get_param('code'));
        if ($err instanceof WP_Error) {
            return $err;
        }
        $store->disable();
        return \rest_ensure_response(['enabled' => false]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function codeArgs(): array
    {
        return [
            'code' => [
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    private function requireValidCode(TwoFactorStore $store, string $code): ?WP_Error
    {
        if (! $store->isEnabled()) {
            return new WP_Error('not_enabled', '2FA não está ativa.', ['status' => 409]);
        }
        if (! $this->rateOk()) {
            return $this->tooMany();
        }
        if ($this->totp->verify($store->getSecret(), $code)) {
            return null;
        }
        $consumed = $this->recovery->verifyAndConsume($code, $store->getRecoveryHashes());
        if ($consumed !== null) {
            $store->setRecoveryHashes($consumed);
            return null;
        }
        return new WP_Error('invalid_code', 'Código inválido.', ['status' => 422]);
    }

    private function rateOk(): bool
    {
        return (new RateLimiter(10))->allow('2fa', \get_current_user_id());
    }

    private function tooMany(): WP_Error
    {
        return new WP_Error('too_many', 'Muitas tentativas. Espere um minuto.', ['status' => 429]);
    }
}
