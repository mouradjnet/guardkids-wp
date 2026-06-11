<?php

declare(strict_types=1);

namespace GuardKids\Invite;

/**
 * Geracao + hash de tokens de convite de guardiao.
 *
 * Plaintext (64 hex chars) so' aparece UMA vez no response do create/resend
 * e nunca persiste. A tabela guarda apenas `sha256(plaintext)`.
 */
final class InviteToken
{
    public const TOKEN_BYTES = 32;
    public const TTL_SECONDS = 7 * 24 * 3600;

    public static function generate(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
