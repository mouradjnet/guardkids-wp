<?php

declare(strict_types=1);

namespace GuardKids\Security;

/**
 * Rate-limit best-effort por childId+endpoint via WP transient.
 *
 * Cenário coberto: token de child vazado floodando `/child/events` ou
 * `/child/location` até esgotar storage. Sem dedupe explícita, mas o cap
 * de 60/min é generoso pra fluxo legítimo (heartbeat ~1/min, location
 * ~1/min) e barra ataque trivial.
 *
 * Limitação conhecida: get_transient + set_transient não é atômico, então
 * em concorrência alta pode passar do limite por algumas unidades. Pra
 * mitigação de DoS isso é aceitável; pra rate-limit de billing, não.
 */
final class RateLimiter
{
    public const WINDOW_SECONDS = 60;
    public const DEFAULT_LIMIT  = 60;

    public function __construct(
        private readonly int $limit = self::DEFAULT_LIMIT,
        private readonly int $window = self::WINDOW_SECONDS,
    ) {
    }

    /**
     * Tenta consumir 1 slot. Retorna true se passou, false se excedeu.
     * Caller transforma `false` em WP_Error 429 com Retry-After.
     */
    public function allow(string $endpoint, int $childId): bool
    {
        $key   = $this->key($endpoint, $childId);
        $count = (int) get_transient($key);
        if ($count >= $this->limit) {
            return false;
        }
        set_transient($key, $count + 1, $this->window);
        return true;
    }

    /**
     * Segundos até a janela atual resetar (best-effort: usa o TTL como
     * estimativa máxima). Útil pro header Retry-After.
     */
    public function retryAfter(): int
    {
        return $this->window;
    }

    private function key(string $endpoint, int $childId): string
    {
        return sprintf('gk_rate:%s:%d', $endpoint, $childId);
    }
}
