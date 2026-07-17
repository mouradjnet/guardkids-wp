<?php
declare(strict_types=1);

namespace GuardKids\License;

/**
 * Cache local da lista de jti revogados, populado por phone-home diário ao
 * license server. FALHA ABERTA: se o servidor estiver fora, mantém o último
 * cache e nunca revoga por indisponibilidade — derrubar premium legítimo por
 * causa de um servidor offline seria pior que o atraso de até ~24h na revogação.
 */
final class RevocationCache
{
    private const TRANSIENT = 'gk_revoked_jti';
    private const TTL        = 90000; // ~25h — sobrevive a um poll perdido

    private string $base;

    public function __construct(string $serverBase = '')
    {
        if ($serverBase === '') {
            $serverBase = defined('GK_LICENSE_SERVER_BASE') ? (string) GK_LICENSE_SERVER_BASE : '';
        }
        $this->base = $serverBase;
    }

    public function isRevoked(string $jti): bool
    {
        return $jti !== '' && \in_array($jti, $this->list(), true);
    }

    /**
     * @return list<string>
     */
    public function list(): array
    {
        $cached = get_transient(self::TRANSIENT);
        return \is_array($cached) ? array_values(array_filter($cached, 'is_string')) : [];
    }

    /**
     * Aplica o corpo decodificado do /revoked. Corpo inválido/null = no-op
     * (falha aberta — preserva o cache anterior).
     */
    public function applyResponse(mixed $body): void
    {
        if (!\is_array($body) || !isset($body['revoked']) || !\is_array($body['revoked'])) {
            return;
        }
        $jtis = array_values(array_filter($body['revoked'], 'is_string'));
        set_transient(self::TRANSIENT, $jtis, self::TTL);
    }

    /**
     * Phone-home. Glue fina sobre applyResponse — exercitada no smoke E2E.
     */
    public function refresh(): void
    {
        if ($this->base === '') {
            return;
        }
        $res = wp_remote_get(trailingslashit($this->base) . 'revoked', ['timeout' => 10]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            return; // falha aberta
        }
        $this->applyResponse(json_decode((string) wp_remote_retrieve_body($res), true));
    }
}
