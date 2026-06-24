<?php

declare(strict_types=1);

namespace GuardKids\Notifications;

use GuardKids\Database\GuardianRepository;
use GuardKids\Database\SettingsRepository;

/**
 * Envia os digests por email aos guardiões ativos. Gated pelos toggles
 * `notifications.email` (diário) e `notifications.weekly_report` (semanal).
 */
final class DigestMailer
{
    private const BRAND = '#1E3A8A';

    private readonly DigestData $data;
    private readonly GuardianRepository $guardians;
    private readonly SettingsRepository $settings;

    public function __construct(
        ?DigestData $data = null,
        ?GuardianRepository $guardians = null,
        ?SettingsRepository $settings = null,
    ) {
        $this->data      = $data ?? new DigestData();
        $this->guardians = $guardians ?? new GuardianRepository();
        $this->settings  = $settings ?? new SettingsRepository();
    }

    public function sendDaily(): int
    {
        if (! (bool) $this->settings->get('notifications.email', false)) {
            return 0;
        }
        return $this->dispatch('GuardKids — Resumo de hoje', $this->renderDailyHtml($this->data->buildDaily()));
    }

    public function sendWeekly(): int
    {
        if (! (bool) $this->settings->get('notifications.weekly_report', false)) {
            return 0;
        }
        return $this->dispatch('GuardKids — Relatório da semana', $this->renderWeeklyHtml($this->data->buildWeekly()));
    }

    private function dispatch(string $subject, string $html): int
    {
        $sent = 0;
        foreach ($this->guardians->findActive() as $g) {
            $email = (string) ($g['email'] ?? '');
            if ($email === '') {
                continue;
            }
            if (\wp_mail($email, $subject, $html, ['Content-Type: text/html; charset=UTF-8'])) {
                $sent++;
            }
        }
        return $sent;
    }

    /**
     * @param array{children: array<int, array{name: string, usedMinutes: int, limitMinutes: int}>, pendingRequests: int, blocksToday: int} $d
     */
    private function renderDailyHtml(array $d): string
    {
        $rows = '';
        foreach ($d['children'] as $c) {
            $rows .= '<li>' . \esc_html($c['name']) . ': '
                . (int) $c['usedMinutes'] . ' / ' . (int) $c['limitMinutes'] . ' min</li>';
        }
        return $this->wrap('Resumo de hoje', '<p>Pedidos pendentes: <b>' . (int) $d['pendingRequests']
            . '</b><br>Bloqueios hoje: <b>' . (int) $d['blocksToday'] . '</b></p>'
            . '<h3>Tempo de tela hoje</h3><ul>' . $rows . '</ul>');
    }

    /**
     * @param array{children: array<int, array{name: string, weekMinutes: int}>, blocksWeek: int, requestsApproved: int, requestsDenied: int} $d
     */
    private function renderWeeklyHtml(array $d): string
    {
        $rows = '';
        foreach ($d['children'] as $c) {
            $rows .= '<li>' . \esc_html($c['name']) . ': ' . (int) $c['weekMinutes'] . ' min</li>';
        }
        return $this->wrap('Relatório da semana', '<p>Bloqueios na semana: <b>' . (int) $d['blocksWeek']
            . '</b><br>Pedidos aprovados: <b>' . (int) $d['requestsApproved']
            . '</b> / negados: <b>' . (int) $d['requestsDenied'] . '</b></p>'
            . '<h3>Tempo de tela na semana</h3><ul>' . $rows . '</ul>');
    }

    private function wrap(string $title, string $body): string
    {
        return '<div style="font-family:sans-serif;max-width:560px;margin:0 auto">'
            . '<div style="background:' . self::BRAND . ';color:#fff;padding:16px 20px;border-radius:12px 12px 0 0">'
            . '<strong>GuardKids</strong> — ' . \esc_html($title) . '</div>'
            . '<div style="border:1px solid #e5e7eb;border-top:0;padding:20px;border-radius:0 0 12px 12px">'
            . $body . '</div></div>';
    }
}
