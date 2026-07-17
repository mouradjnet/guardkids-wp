<?php

declare(strict_types=1);

namespace GuardKids\License;

/**
 * Verifica chaves de licença assinadas com Ed25519.
 *
 * Formato da chave: `<base64url(payload_json)>.<base64url(signature)>`. A
 * assinatura cobre os bytes do payload **encoded** (não o JSON decodificado)
 * pra evitar discrepâncias entre encoders. A pubkey Ed25519 é embarcada como
 * constante — não há roundtrip de rede.
 *
 * O `Verifier` aceita pubkey via construtor (DI) pra testes injetarem chaves
 * geradas em tempo de execução. Em runtime, usa o default
 * `DEFAULT_ISSUER_PUBKEY_B64` que é substituído pelo Step 3 do roadmap (CLI).
 */
final class Verifier
{
    /**
     * Pubkey Ed25519 do issuer GuardKids. Pareia com a privkey local
     * `~/.guardkids/issuer.key` gerada por `scripts/issue-license.php
     * --gen-keys`. Rotacionar essa constante invalida todas as chaves
     * emitidas com a privkey anterior — não faça isso à toa.
     */
    public const DEFAULT_ISSUER_PUBKEY_B64 = 'b7YkmSwjK1QXFGDnY8CblwJhuNLSgnH8z2pF0Ikr2F8=';

    private readonly string $pubkey;

    public function __construct(string $pubkey_b64 = self::DEFAULT_ISSUER_PUBKEY_B64)
    {
        $raw = base64_decode($pubkey_b64, true);
        $this->pubkey = $raw === false ? '' : $raw;
    }

    /**
     * Verifica uma chave. Retorna o payload se válida, ou null em qualquer
     * falha (formato, base64, assinatura, json).
     *
     * Nunca lança — todo erro vira null. Cabe ao caller diferenciar entre
     * "sem licença" (`Gate::status() === 'none'`) e "licença inválida".
     */
    public function verify(string $key): ?Payload
    {
        if (\strlen($this->pubkey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return null;
        }

        $parts = explode('.', $key, 3);
        if (\count($parts) !== 2) {
            return null;
        }

        [$payloadB64, $sigB64] = $parts;

        $payloadJson = self::base64UrlDecode($payloadB64);
        $signature   = self::base64UrlDecode($sigB64);
        if ($payloadJson === null || $signature === null) {
            return null;
        }

        if (\strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }

        $signed = false;
        try {
            $signed = sodium_crypto_sign_verify_detached($signature, $payloadB64, $this->pubkey);
        } catch (\SodiumException) {
            return null;
        }
        if (! $signed) {
            return null;
        }

        $data = json_decode($payloadJson, true);
        if (! \is_array($data)) {
            return null;
        }

        return self::hydrate($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function hydrate(array $data): ?Payload
    {
        $required = ['iss', 'sub', 'jti', 'iat', 'exp', 'plan', 'features'];
        foreach ($required as $field) {
            if (! \array_key_exists($field, $data)) {
                return null;
            }
        }
        if (! \is_string($data['iss']) || ! \is_string($data['sub']) || ! \is_string($data['jti'])) {
            return null;
        }
        if (! \is_int($data['iat']) || ! \is_int($data['exp'])) {
            return null;
        }
        if (! \is_string($data['plan']) || ! \is_array($data['features'])) {
            return null;
        }

        $features = [];
        foreach ($data['features'] as $f) {
            if (\is_string($f)) {
                $features[] = $f;
            }
        }

        $email = $data['email'] ?? null;
        if ($email !== null && ! \is_string($email)) {
            return null;
        }

        return new Payload(
            iss:      $data['iss'],
            sub:      $data['sub'],
            jti:      $data['jti'],
            iat:      $data['iat'],
            exp:      $data['exp'],
            plan:     $data['plan'],
            features: $features,
            email:    $email,
        );
    }

    private static function base64UrlDecode(string $input): ?string
    {
        $remainder = \strlen($input) % 4;
        if ($remainder !== 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($input, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
