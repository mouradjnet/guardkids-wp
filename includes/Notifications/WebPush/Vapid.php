<?php

declare(strict_types=1);

namespace GuardKids\Notifications\WebPush;

final class Vapid
{
    private const SUBJECT = 'mailto:contato@guardiaokids.site';
    private const TTL     = 43200; // 12h

    private readonly VapidKeys $keys;

    public function __construct(?VapidKeys $keys = null)
    {
        $this->keys = $keys ?? new VapidKeys();
    }

    public function header(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');

        $header = Base64Url::encode((string) wp_json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = Base64Url::encode((string) wp_json_encode([
            'aud' => $origin,
            'exp' => time() + self::TTL,
            'sub' => self::SUBJECT,
        ]));
        $signingInput = $header . '.' . $claims;

        $key = EcKeys::privateFromRaw($this->keys->privateRaw(), $this->keys->publicRaw());
        $der = '';
        openssl_sign($signingInput, $der, $key, OPENSSL_ALGO_SHA256);

        $jwt = $signingInput . '.' . Base64Url::encode(self::derToJose($der));
        return 'vapid t=' . $jwt . ', k=' . $this->keys->publicKey();
    }

    /** DER ECDSA-Sig-Value (SEQUENCE{INTEGER r, INTEGER s}) -> JOSE r||s (64 bytes). */
    private static function derToJose(string $der): string
    {
        $offset = 2; // pula 30 <len> (len < 128 pra assinatura P-256)
        $rlen = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rlen);
        $offset = $offset + 2 + $rlen;
        $slen = ord($der[$offset + 1]);
        $s = substr($der, $offset + 2, $slen);

        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
        return $r . $s;
    }
}
