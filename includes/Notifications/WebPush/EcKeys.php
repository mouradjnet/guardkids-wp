<?php

declare(strict_types=1);

namespace GuardKids\Notifications\WebPush;

use OpenSSLAsymmetricKey;

/**
 * Utilidades de chaves EC P-256 (prime256v1) pro Web Push.
 *
 * Converte entre a forma "raw" (ponto público de 65 bytes 0x04||X||Y, escalar
 * privado de 32 bytes) e recursos OpenSSL, via prefixos DER fixos da curva.
 */
final class EcKeys
{
    // SubjectPublicKeyInfo header pra prime256v1, seguido do BIT STRING (03 42 00).
    private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** @return array{key:OpenSSLAsymmetricKey,public:string,privateRaw:string} */
    public static function generate(): array
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        if (! $key instanceof OpenSSLAsymmetricKey) {
            throw new \RuntimeException('Falha ao gerar chave EC.');
        }
        return [
            'key'        => $key,
            'public'     => self::rawPublicOf($key),
            'privateRaw' => self::rawPrivateOf($key),
        ];
    }

    public static function publicFromRaw(string $raw65): OpenSSLAsymmetricKey
    {
        $der = (string) hex2bin(self::SPKI_PREFIX) . $raw65;
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if (! $key instanceof OpenSSLAsymmetricKey) {
            throw new \RuntimeException('Ponto público EC inválido.');
        }
        return $key;
    }

    public static function privateFromRaw(string $d32, string $q65): OpenSSLAsymmetricKey
    {
        // PKCS#8 PrivateKeyInfo pra P-256: template fixo + d(32) + Q(65).
        $der = (string) hex2bin('308187020100301306072a8648ce3d020106082a8648ce3d030107046d306b0201010420')
            . $d32
            . (string) hex2bin('a144034200')
            . $q65;
        $pem = "-----BEGIN PRIVATE KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PRIVATE KEY-----\n";
        $key = openssl_pkey_get_private($pem);
        if (! $key instanceof OpenSSLAsymmetricKey) {
            throw new \RuntimeException('Chave privada EC inválida.');
        }
        return $key;
    }

    public static function rawPublicOf(OpenSSLAsymmetricKey $key): string
    {
        $d = openssl_pkey_get_details($key);
        return "\x04"
            . str_pad($d['ec']['x'], 32, "\x00", STR_PAD_LEFT)
            . str_pad($d['ec']['y'], 32, "\x00", STR_PAD_LEFT);
    }

    public static function rawPrivateOf(OpenSSLAsymmetricKey $key): string
    {
        $d = openssl_pkey_get_details($key);
        return str_pad($d['ec']['d'], 32, "\x00", STR_PAD_LEFT);
    }
}
