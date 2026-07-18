<?php

declare(strict_types=1);

namespace GuardKids\License;

/**
 * API pública de gating de features.
 *
 * Lê a licença persistida em `wp_options.guardkids_license` e expõe consultas
 * idempotentes pra controllers REST e UI:
 *
 *   $gate = new Gate();
 *   if (! $gate->can('browser')) { return new WP_Error('plan_limit', …, 402); }
 *
 * O resolver é leniente — qualquer falha de leitura/parse degrada pro plano
 * `free`, nunca pra erro 500. Premium só é concedido quando a verificação
 * passa por todos os checks (assinatura, domain lock, expiry, revogação).
 */
/**
 * Não é `final` propositadamente: testes que não exercitam o gating real
 * estendem em {@see GuardKids\Tests\Support\AlwaysAllowGate} pra liberar tudo
 * sem precisar instalar fixture de licença.
 */
class Gate
{
    /**
     * Features bloqueadas no plano Free — única source-of-truth do servidor.
     * Espelhado no client em `hooks/useLicense.ts` (PREMIUM_FEATURES); o
     * comparativo de marketing vive em `data/planCatalog.ts`.
     *
     * @var list<string>
     */
    public const PREMIUM_FEATURES = [
        'browser',
        'categories',
        'schedule',
        'reports',
        'location',
        'unlimited_kids',
        'full_history',
    ];

    private readonly Verifier $verifier;
    private readonly RevocationCache $revocations;
    private ?Payload $cachedPayload = null;
    private bool $payloadResolved = false;

    public function __construct(?Verifier $verifier = null, ?RevocationCache $revocations = null)
    {
        $this->verifier    = $verifier ?? new Verifier();
        $this->revocations = $revocations ?? new RevocationCache();
    }

    public function plan(): string
    {
        return $this->status() === 'active' ? 'premium' : 'free';
    }

    /**
     * Status canônico da licença atualmente persistida. Recalculado a cada
     * chamada — barato porque a verificação Ed25519 é O(1) com sodium.
     *
     * @return 'none'|'active'|'expired'|'domain_mismatch'|'revoked'
     */
    public function status(): string
    {
        $payload = $this->loadPayload();
        if ($payload === null) {
            return 'none';
        }

        if ($this->isRevoked($payload)) {
            return 'revoked';
        }

        if (! $this->matchesSiteUrl($payload)) {
            return 'domain_mismatch';
        }

        if ($payload->isExpired()) {
            return 'expired';
        }

        return 'active';
    }

    /**
     * Verifica se uma feature está disponível pra esta instalação.
     *
     * - Features não premium são sempre liberadas.
     * - Features premium exigem `status() === 'active'` E presença em
     *   `payload.features` (issuer pode emitir Premium parcial no futuro).
     */
    public function can(string $featureId): bool
    {
        if (! \in_array($featureId, self::PREMIUM_FEATURES, true)) {
            return true;
        }

        if ($this->status() !== 'active') {
            return false;
        }

        $payload = $this->loadPayload();
        return $payload !== null && \in_array($featureId, $payload->features, true);
    }

    public function expiresAt(): ?int
    {
        return $this->loadPayload()?->exp;
    }

    public function daysLeft(): ?int
    {
        return $this->loadPayload()?->daysLeft();
    }

    public function payload(): ?Payload
    {
        return $this->loadPayload();
    }

    /**
     * Memoiza payload na instância — endpoints com gating chamam status(),
     * can() e plan() em sequência (~3 calls cada), evitando 3 verifys
     * Ed25519 redundantes. Cache vive só durante o request: cada controller
     * instancia um Gate novo.
     */
    private function loadPayload(): ?Payload
    {
        if ($this->payloadResolved) {
            return $this->cachedPayload;
        }
        $this->payloadResolved = true;

        $stored = get_option('guardkids_license', null);
        if (! \is_array($stored) || ! isset($stored['key_b64']) || ! \is_string($stored['key_b64'])) {
            return null;
        }
        $this->cachedPayload = $this->verifier->verify($stored['key_b64']);
        return $this->cachedPayload;
    }

    private function matchesSiteUrl(Payload $payload): bool
    {
        $current = (string) get_option('siteurl', '');
        if ($current === '') {
            return false;
        }
        return rtrim($payload->sub, '/') === rtrim($current, '/');
    }

    private function isRevoked(Payload $payload): bool
    {
        // Fonte de verdade: lista remota do license server (cron diário, falha aberta).
        if ($this->revocations->isRevoked($payload->jti)) {
            return true;
        }
        // Override manual de emergência (revogar na unha sem depender do servidor).
        $list = get_option('guardkids_license_revoked', []);
        return \is_array($list) && \in_array($payload->jti, $list, true);
    }
}
