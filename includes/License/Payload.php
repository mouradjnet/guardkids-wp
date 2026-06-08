<?php

declare(strict_types=1);

namespace GuardKids\License;

/**
 * Payload imutável extraído de uma chave de licença válida.
 *
 * Construído exclusivamente pelo {@see Verifier::verify()} após validar a
 * assinatura Ed25519 — fora dele, qualquer instância é suspeita.
 */
final class Payload
{
    /**
     * @param list<string> $features
     */
    public function __construct(
        public readonly string $iss,
        public readonly string $sub,
        public readonly string $jti,
        public readonly int $iat,
        public readonly int $exp,
        public readonly string $plan,
        public readonly array $features,
        public readonly ?string $email,
    ) {
    }

    public function isExpired(?int $now = null): bool
    {
        return ($now ?? time()) >= $this->exp;
    }

    public function daysLeft(?int $now = null): int
    {
        $seconds = $this->exp - ($now ?? time());
        if ($seconds <= 0) {
            return 0;
        }
        return (int) ceil($seconds / 86_400);
    }

    /**
     * @return array{
     *     iss: string, sub: string, jti: string,
     *     iat: int, exp: int,
     *     plan: string, features: list<string>, email: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'iss'      => $this->iss,
            'sub'      => $this->sub,
            'jti'      => $this->jti,
            'iat'      => $this->iat,
            'exp'      => $this->exp,
            'plan'     => $this->plan,
            'features' => $this->features,
            'email'    => $this->email,
        ];
    }
}
