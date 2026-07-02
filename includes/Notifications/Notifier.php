<?php

declare(strict_types=1);

namespace GuardKids\Notifications;

use DateTimeImmutable;
use GuardKids\Database\ChildRepository;
use GuardKids\Database\NotificationRepository;
use GuardKids\Database\SiteRepository;
use GuardKids\Notifications\WebPush\PushSender;

/**
 * Funil único de criação de notificações do app-filho. Cada gatilho vira uma
 * linha (idempotente via dedup_key). É o ponto onde o Web Push (fase 2) vai
 * plugar: depois de criar a linha, um PushSender futuro entrega.
 */
final class Notifier
{
    private const WARNING_MINUTES = 10;

    private readonly NotificationRepository $repo;
    private readonly ChildRepository $children;
    private readonly PushSender $pushSender;

    public function __construct(
        ?NotificationRepository $repo = null,
        ?ChildRepository $children = null,
        ?PushSender $pushSender = null
    ) {
        $this->repo       = $repo ?? new NotificationRepository();
        $this->children   = $children ?? new ChildRepository();
        $this->pushSender = $pushSender ?? new PushSender();
    }

    /**
     * Funil único: cria a notificação e, se for nova, empurra o Web Push.
     *
     * @param array{type:string,title:string,body?:?string} $data
     */
    private function emit(int $childId, string $dedupKey, array $data): void
    {
        if ($this->repo->createIfAbsent($childId, $dedupKey, $data)) {
            $this->pushSender->sendToChild($childId, (string) $data['title'], (string) ($data['body'] ?? ''));
        }
    }

    /**
     * @param array<string, mixed> $request linha de wp_guardkids_requests
     */
    public function notifyRequestDecided(array $request, string $decision): void
    {
        $childId = (int) ($request['child_id'] ?? 0);
        if ($childId === 0) {
            return;
        }
        $label = trim(((string) ($request['description'] ?? '')) . ' ' . ((string) ($request['highlight'] ?? '')));
        $approved = $decision === 'approved';
        $this->emit($childId, 'req:' . (int) ($request['id'] ?? 0), [
            'type'  => $approved ? 'request_approved' : 'request_denied',
            'title' => $approved ? 'Seu pedido foi aprovado! 🎉' : 'Seu pedido não foi aprovado',
            'body'  => $label !== '' ? $label : null,
        ]);
    }

    /** A whitelist é da família → 1 notificação por filho. */
    public function notifySiteAllowed(string $domain): void
    {
        $domain = SiteRepository::normalizeDomain($domain);
        if ($domain === '') {
            return;
        }
        foreach ($this->children->findAll() as $child) {
            $this->emit((int) ($child['id'] ?? 0), 'site:' . $domain, [
                'type'  => 'site_allowed',
                'title' => 'Novo site liberado',
                'body'  => 'Agora você pode acessar ' . $domain,
            ]);
        }
    }

    public function notifyBlocked(int $childId, string $detail): void
    {
        $titles = ['bedtime' => 'Hora de dormir', 'weekday' => 'Dia bloqueado', 'limit' => 'Tempo esgotado'];
        $this->emit($childId, 'blocked:' . $detail . ':' . gmdate('Y-m-d'), [
            'type'  => 'blocked',
            'title' => $titles[$detail] ?? 'Acesso pausado',
            'body'  => 'O acesso está pausado agora.',
        ]);
    }

    /**
     * Persiste os avisos de aproximação (chamado pelo /child/me quando não bloqueado).
     *
     * @param array<string, mixed> $child linha de wp_guardkids_children
     */
    public function persistWarnings(int $childId, DateTimeImmutable $now, array $child, int $usedMinutes): void
    {
        foreach (self::approachingWarnings($child, $usedMinutes, $now) as $w) {
            $this->emit($childId, (string) $w['dedup_key'], [
                'type'  => (string) $w['type'],
                'title' => (string) $w['title'],
                'body'  => (string) $w['body'],
            ]);
        }
    }

    /**
     * Lógica pura dos avisos de tempo/bedtime (limiar de 10 min). Assume que o
     * filho NÃO está bloqueado agora (o caller checa schedule.isBlocked antes).
     *
     * @param array<string, mixed> $child linha de wp_guardkids_children
     * @return array<int, array{type:string,title:string,body:string,dedup_key:string}>
     */
    public static function approachingWarnings(array $child, int $usedMinutes, DateTimeImmutable $now): array
    {
        $warnings = [];
        $today = $now->format('Y-m-d');

        $limitEnabled = (int) ($child['daily_limit_enabled'] ?? 0) === 1;
        $limit        = (int) ($child['limit_minutes'] ?? 0);
        if ($limitEnabled && $limit > 0) {
            $remaining = $limit - $usedMinutes;
            if ($remaining > 0 && $remaining <= self::WARNING_MINUTES) {
                $warnings[] = [
                    'type'      => 'time_warning',
                    'title'     => 'Tempo acabando',
                    'body'      => "Faltam {$remaining} min de tela hoje.",
                    'dedup_key' => 'limit:' . $today,
                ];
            }
        }

        $bedtimeEnabled = (int) ($child['bedtime_enabled'] ?? 0) === 1;
        $start = $child['bedtime_start'] ?? null;
        if ($bedtimeEnabled && is_string($start) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $start) === 1) {
            $startDt = $now->setTime((int) substr($start, 0, 2), (int) substr($start, 3, 2), (int) substr($start, 6, 2));
            if ($now < $startDt) {
                $mins = (int) floor(($startDt->getTimestamp() - $now->getTimestamp()) / 60);
                if ($mins <= self::WARNING_MINUTES) {
                    $n = max(1, $mins);
                    $warnings[] = [
                        'type'      => 'bedtime_warning',
                        'title'     => 'Hora de dormir chegando',
                        'body'      => "A hora de dormir começa em {$n} min.",
                        'dedup_key' => 'bedtime:' . $today,
                    ];
                }
            }
        }

        return $warnings;
    }
}
