<?php

declare(strict_types=1);

namespace GuardKids\Notifications\WebPush;

use OpenSSLAsymmetricKey;

/**
 * Cifra o payload no content-encoding aes128gcm (RFC 8188) com a derivação de
 * chave do Web Push (RFC 8291). Um único record.
 */
final class Payload
{
    private const RECORD_SIZE = 4096;

    /**
     * @param array{key:OpenSSLAsymmetricKey,public:string}|null $server efêmero (injeta em teste)
     */
    public function encrypt(
        string $plaintext,
        string $uaPublicRaw,
        string $authSecret,
        ?string $salt = null,
        ?array $server = null
    ): string {
        $salt   = $salt ?? random_bytes(16);
        $server = $server ?? (function (): array {
            $g = EcKeys::generate();
            return ['key' => $g['key'], 'public' => $g['public']];
        })();

        $uaPublic = EcKeys::publicFromRaw($uaPublicRaw);
        $ecdh = openssl_pkey_derive($uaPublic, $server['key'], 32);
        if ($ecdh === false) {
            throw new \RuntimeException('ECDH falhou.');
        }

        // RFC 8291: IKM = HKDF(salt=auth, ikm=ecdh, info="WebPush: info"||0x00||ua||as, 32)
        $keyInfo = "WebPush: info\x00" . $uaPublicRaw . $server['public'];
        $ikm = hash_hkdf('sha256', $ecdh, 32, $keyInfo, $authSecret);

        // RFC 8188: CEK e NONCE derivados do IKM com o salt de 16 bytes.
        $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        $padded = $plaintext . "\x02"; // delimitador do último record, sem padding extra
        $tag = '';
        $cipher = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('AES-GCM falhou.');
        }

        // Header aes128gcm: salt(16) | rs(uint32be) | idlen(1) | keyid(serverPublic 65) | ciphertext+tag
        return $salt
            . pack('N', self::RECORD_SIZE)
            . chr(65)
            . $server['public']
            . $cipher
            . $tag;
    }
}
