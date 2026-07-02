<?php

declare(strict_types=1);

namespace GuardKids\Notifications\WebPush;

final class Base64Url
{
    public static function encode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function decode(string $b64u): string
    {
        $pad = strlen($b64u) % 4;
        if ($pad > 0) {
            $b64u .= str_repeat('=', 4 - $pad);
        }
        return (string) base64_decode(strtr($b64u, '-_', '+/'), true);
    }
}
