<?php

declare(strict_types=1);

namespace GuardKids\Security;

/**
 * Parse simples de user-agent pra rótulo amigável (navegador + SO). Não
 * pretende ser exaustivo — só legível pro pai reconhecer o aparelho.
 * A ordem importa: Edge antes de Chrome, Chrome antes de Safari, iOS/Android
 * antes de macOS/Linux (os UAs deles contêm essas strings).
 */
final class UserAgent
{
    /**
     * @return array{browser: string, os: string}
     */
    public static function parse(string $ua): array
    {
        $ua = trim($ua);
        if ($ua === '') {
            return ['browser' => 'Desconhecido', 'os' => 'Desconhecido'];
        }

        $browser = 'Desconhecido';
        if (preg_match('/Edg/i', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/OPR|Opera/i', $ua)) {
            $browser = 'Opera';
        } elseif (preg_match('/Firefox/i', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Chrome|CriOS/i', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Safari/i', $ua)) {
            $browser = 'Safari';
        }

        $os = 'Desconhecido';
        if (preg_match('/Windows/i', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/iPhone|iPad|iOS/i', $ua)) {
            $os = 'iOS';
        } elseif (preg_match('/Android/i', $ua)) {
            $os = 'Android';
        } elseif (preg_match('/Mac OS X|Macintosh/i', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/Linux/i', $ua)) {
            $os = 'Linux';
        }

        return ['browser' => $browser, 'os' => $os];
    }
}
