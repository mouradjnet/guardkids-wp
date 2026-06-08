<?php

declare(strict_types=1);

namespace GuardKids\Tests\Support;

use GuardKids\License\Gate;

/**
 * Gate fake pra testes que NÃO estão testando gating em si — só precisam
 * passar do gating pra exercitar o resto do controller.
 *
 * Tests que exercitam o gating real (Free vs Premium) devem usar
 * `new Gate()` direto, com fixture de option `guardkids_license` instalada
 * por keypair de teste.
 */
final class AlwaysAllowGate extends Gate
{
    public function __construct()
    {
        // Pula construtor pai (que cria Verifier real) — fake não precisa.
    }

    public function plan(): string
    {
        return 'premium';
    }

    public function status(): string
    {
        return 'active';
    }

    public function can(string $featureId): bool
    {
        return true;
    }

    public function expiresAt(): ?int
    {
        return PHP_INT_MAX;
    }

    public function daysLeft(): ?int
    {
        return 365;
    }
}
