<?php

declare(strict_types=1);

namespace GuardKids\Security;

/**
 * TOTP (RFC 6238) artesanal — sem dependências externas.
 *
 * Algoritmo SHA-1, 6 dígitos, período de 30s (compatível com Google
 * Authenticator/Authy/etc). `codeAt` é público pra ser testável com os
 * vetores oficiais; `verify` usa a janela ±1 pra tolerar drift de relógio.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const DIGITS   = 6;
    private const PERIOD   = 30;

    public function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public function provisioningUri(string $secret, string $label, string $issuer): string
    {
        $path  = rawurlencode($issuer) . ':' . rawurlencode($label);
        $query = http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ]);
        return 'otpauth://totp/' . $path . '?' . $query;
    }

    public function verify(string $secret, string $code, int $window = 1): bool
    {
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }
        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals($this->codeAt($secret, $now + $i * self::PERIOD), $code)) {
                return true;
            }
        }
        return false;
    }

    public function codeAt(string $secret, int $timestamp): string
    {
        $counter    = intdiv($timestamp, self::PERIOD);
        $binCounter = pack('N', 0) . pack('N', $counter);
        $key        = self::base32Decode($secret);
        $hash       = hash_hmac('sha1', $binCounter, $key, true);
        $offset     = ord($hash[strlen($hash) - 1]) & 0x0f;
        $binary     = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        $otp = $binary % (10 ** self::DIGITS);
        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }
        $bits = '';
        foreach (str_split($bytes) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32  = strtoupper($b32);
        $bits = '';
        $len  = strlen($b32);
        for ($i = 0; $i < $len; $i++) {
            $pos = strpos(self::ALPHABET, $b32[$i]);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }
        return $bytes;
    }
}
