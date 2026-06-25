<?php

declare(strict_types=1);

namespace GuardKids\Security;

/**
 * Normaliza a lista crua do WP_Session_Tokens::get_all() em DTOs prontos pro
 * front: aparelho amigável, IP, último acesso e flag da sessão atual. Puro —
 * recebe a sessão atual já resolvida pra não depender de WP aqui.
 */
final class SessionPresenter
{
    /**
     * @param array<int, array<string, mixed>> $rawSessions
     * @param array<string, mixed>|null        $current
     * @return array<int, array{device: string, browser: string, os: string, ip: string, lastAccess: int, current: bool}>
     */
    public static function present(array $rawSessions, ?array $current): array
    {
        $out = [];
        foreach ($rawSessions as $s) {
            $parsed = UserAgent::parse(isset($s['ua']) ? (string) $s['ua'] : '');
            $ip = isset($s['ip']) && (string) $s['ip'] !== '' ? (string) $s['ip'] : 'Desconhecido';
            $out[] = [
                'device'     => $parsed['browser'] . ' · ' . $parsed['os'],
                'browser'    => $parsed['browser'],
                'os'         => $parsed['os'],
                'ip'         => $ip,
                'lastAccess' => isset($s['login']) ? (int) $s['login'] : 0,
                'current'    => $current !== null && self::sameSession($s, $current),
            ];
        }
        usort($out, static fn (array $a, array $b): int => $b['lastAccess'] <=> $a['lastAccess']);
        return $out;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function sameSession(array $a, array $b): bool
    {
        foreach (['login', 'ip', 'ua', 'expiration'] as $k) {
            if (($a[$k] ?? null) !== ($b[$k] ?? null)) {
                return false;
            }
        }
        return true;
    }
}
