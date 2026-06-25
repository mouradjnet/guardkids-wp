<?php

declare(strict_types=1);

namespace GuardKids\Security;

/**
 * Única unidade da 2FA acoplada ao WordPress: lê/grava `user_meta` por usuário.
 * 2FA é por usuário (cada guardião tem o próprio app), diferente do PIN que é
 * global por conta.
 */
final class TwoFactorStore
{
    private const ENABLED     = 'guardkids_2fa_enabled';
    private const SECRET      = 'guardkids_2fa_secret';
    private const PENDING     = 'guardkids_2fa_pending_secret';
    private const RECOVERY    = 'guardkids_2fa_recovery';
    private const LOGIN_NONCE = 'guardkids_2fa_login_nonce';
    private const NONCE_TTL   = 600;

    public function __construct(private readonly int $userId)
    {
    }

    public function isEnabled(): bool
    {
        return (bool) \get_user_meta($this->userId, self::ENABLED, true);
    }

    public function getSecret(): string
    {
        $v = \get_user_meta($this->userId, self::SECRET, true);
        return is_string($v) ? $v : '';
    }

    public function setPendingSecret(string $secret): void
    {
        \update_user_meta($this->userId, self::PENDING, $secret);
    }

    public function getPendingSecret(): string
    {
        $v = \get_user_meta($this->userId, self::PENDING, true);
        return is_string($v) ? $v : '';
    }

    /**
     * @param array<int, string> $recoveryHashes
     */
    public function enable(string $secret, array $recoveryHashes): void
    {
        \update_user_meta($this->userId, self::SECRET, $secret);
        \update_user_meta($this->userId, self::RECOVERY, $recoveryHashes);
        \update_user_meta($this->userId, self::ENABLED, '1');
        \delete_user_meta($this->userId, self::PENDING);
    }

    public function disable(): void
    {
        \delete_user_meta($this->userId, self::SECRET);
        \delete_user_meta($this->userId, self::RECOVERY);
        \delete_user_meta($this->userId, self::ENABLED);
        \delete_user_meta($this->userId, self::PENDING);
        \delete_user_meta($this->userId, self::LOGIN_NONCE);
    }

    /** @return array<int, string> */
    public function getRecoveryHashes(): array
    {
        $v = \get_user_meta($this->userId, self::RECOVERY, true);
        return is_array($v) ? $v : [];
    }

    /** @param array<int, string> $hashes */
    public function setRecoveryHashes(array $hashes): void
    {
        \update_user_meta($this->userId, self::RECOVERY, $hashes);
    }

    public function setLoginNonce(string $nonce): void
    {
        \update_user_meta($this->userId, self::LOGIN_NONCE, [
            'hash'    => password_hash($nonce, PASSWORD_DEFAULT),
            'expires' => time() + self::NONCE_TTL,
        ]);
    }

    public function verifyLoginNonce(string $nonce): bool
    {
        $v = \get_user_meta($this->userId, self::LOGIN_NONCE, true);
        if (! is_array($v) || ! isset($v['hash'], $v['expires'])) {
            return false;
        }
        if (time() > (int) $v['expires']) {
            return false;
        }
        return password_verify($nonce, (string) $v['hash']);
    }

    public function clearLoginNonce(): void
    {
        \delete_user_meta($this->userId, self::LOGIN_NONCE);
    }
}
