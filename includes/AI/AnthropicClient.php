<?php

declare(strict_types=1);

namespace GuardKids\AI;

/**
 * Client mínimo da Messages API da Anthropic, no servidor. O HTTP fica isolado
 * num {@see AnthropicClient::fetch()} protegido (sobrescrito nos testes) — molde
 * idêntico ao {@see \GuardKids\Geo\Geocoder}.
 *
 * A chave NUNCA vai no bundle nem sai por REST: vem de uma constante do
 * `wp-config.php` (`GUARDKIDS_ANTHROPIC_KEY`) ou, como fallback, da option
 * `guardkids_anthropic_key`. Ausente → {@see hasKey()} false → o caller degrada.
 *
 * Degradação leniente: qualquer falha (sem chave, erro HTTP, corpo não-JSON,
 * shape inesperado) devolve `null`, nunca lança. Quem chama trata `null` como
 * "insights indisponíveis".
 *
 * Gotchas do Opus 4.8 honrados de propósito: SEM `temperature`/`top_p` (dão 400),
 * SEM prefill, `thinking` deixado no default (omitido).
 */
class AnthropicClient
{
    private const ENDPOINT    = 'https://api.anthropic.com/v1/messages';
    private const MODEL       = 'claude-opus-4-8';
    private const API_VERSION = '2023-06-01';
    private const MAX_TOKENS  = 1024;
    private const OPTION_KEY  = 'guardkids_anthropic_key';

    /**
     * Uma volta na Messages API. Devolve o texto da resposta do modelo, ou
     * `null` em qualquer falha.
     */
    public function complete(string $system, string $user): ?string
    {
        if (! $this->hasKey()) {
            return null;
        }

        $decoded = $this->fetch([
            'model'      => self::MODEL,
            'max_tokens' => self::MAX_TOKENS,
            'system'     => $system,
            'messages'   => [
                ['role' => 'user', 'content' => $user],
            ],
        ]);

        if ($decoded === null) {
            return null;
        }

        // Resposta da Messages API: content é uma lista de blocos; pegamos o
        // primeiro bloco de texto.
        $text = $decoded['content'][0]['text'] ?? null;
        return is_string($text) && $text !== '' ? $text : null;
    }

    public function hasKey(): bool
    {
        return $this->key() !== '';
    }

    /**
     * Chave: constante do wp-config primeiro (não vaza em dump de DB), option
     * como fallback.
     */
    private function key(): string
    {
        if (defined('GUARDKIDS_ANTHROPIC_KEY')) {
            $const = \GUARDKIDS_ANTHROPIC_KEY;
            if (is_string($const) && trim($const) !== '') {
                return trim($const);
            }
        }

        $opt = get_option(self::OPTION_KEY, '');
        return is_string($opt) ? trim($opt) : '';
    }

    /**
     * HTTP real. Recebe o payload da Messages API, devolve o corpo decodificado
     * (array) ou `null` em erro. Protegido: sobrescrito nos testes pra não bater
     * na rede.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    protected function fetch(array $payload): ?array
    {
        $response = wp_remote_post(self::ENDPOINT, [
            'timeout' => 30,
            'headers' => [
                'x-api-key'         => $this->key(),
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body    = wp_remote_retrieve_body($response);
        $decoded = json_decode(is_string($body) ? $body : '', true);
        return is_array($decoded) ? $decoded : null;
    }
}
