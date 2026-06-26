<?php

declare(strict_types=1);

namespace GuardKids\Schedule;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Avalia se uma criança está bloqueada agora segundo bedtime + weekday.
 *
 * Função pura — recebe a config (linha de wp_guardkids_children) e o
 * instante atual já no timezone local do site, devolve estado.
 *
 * Ordem de precedência: weekday > bedtime > limit (dia inteiro bloqueado
 * não vira false-positive de "bedtime curto às 14h"; e bedtime ativo não
 * vira "limit"). O limite diário é o bloqueio mais leve e fica por último.
 */
final class ScheduleEvaluator
{
    /**
     * @param array{
     *   bedtime_enabled?:     int|bool|null,
     *   bedtime_start?:       ?string,
     *   bedtime_end?:         ?string,
     *   allowed_weekdays?:    ?string,
     *   daily_limit_enabled?: int|bool|null,
     *   limit_minutes?:       int|null,
     * } $config
     * @param int $minutesUsedToday Minutos de uso já acumulados hoje (dia local).
     *
     * @return array{
     *   isBlocked: bool,
     *   reason:    'bedtime'|'weekday'|'limit'|null,
     *   unlockAt:  ?string,
     * }
     */
    public function evaluate(array $config, DateTimeImmutable $now, int $minutesUsedToday = 0): array
    {
        $weekdays = (string) ($config['allowed_weekdays'] ?? 'YYYYYYY');
        if (! preg_match('/^[YN]{7}$/', $weekdays)) {
            $weekdays = 'YYYYYYY';
        }

        $dayIdx = (int) $now->format('N') - 1; // 0=Mon

        if ($weekdays[$dayIdx] === 'N') {
            return [
                'isBlocked' => true,
                'reason'    => 'weekday',
                'unlockAt'  => $this->nextAllowedMidnightUtc($weekdays, $now),
            ];
        }

        $enabled = (int) ($config['bedtime_enabled'] ?? 0) === 1;
        $start   = $config['bedtime_start']   ?? null;
        $end     = $config['bedtime_end']     ?? null;

        if (
            $enabled
            && is_string($start) && is_string($end)
            && preg_match('/^\d{2}:\d{2}:\d{2}$/', $start) === 1
            && preg_match('/^\d{2}:\d{2}:\d{2}$/', $end) === 1
            && $start !== $end
        ) {
            $startDt = $now->setTime(
                (int) substr($start, 0, 2),
                (int) substr($start, 3, 2),
                (int) substr($start, 6, 2)
            );
            $endDt = $now->setTime(
                (int) substr($end, 0, 2),
                (int) substr($end, 3, 2),
                (int) substr($end, 6, 2)
            );

            if ($startDt < $endDt) {
                // Janela normal: [start, end)
                if ($now >= $startDt && $now < $endDt) {
                    return [
                        'isBlocked' => true,
                        'reason'    => 'bedtime',
                        'unlockAt'  => $this->toUtcIso($endDt),
                    ];
                }
            } else {
                // Cross-midnight: bloqueado se now >= start OR now < end
                if ($now >= $startDt) {
                    $unlock = $endDt->modify('+1 day');
                    return [
                        'isBlocked' => true,
                        'reason'    => 'bedtime',
                        'unlockAt'  => $this->toUtcIso($unlock),
                    ];
                }
                if ($now < $endDt) {
                    return [
                        'isBlocked' => true,
                        'reason'    => 'bedtime',
                        'unlockAt'  => $this->toUtcIso($endDt),
                    ];
                }
            }
        }

        $dailyEnabled = (int) ($config['daily_limit_enabled'] ?? 0) === 1;
        $limit        = (int) ($config['limit_minutes'] ?? 0);

        if ($dailyEnabled && $limit > 0 && $minutesUsedToday >= $limit) {
            // Reseta na virada do dia local: unlockAt = próxima meia-noite local.
            $midnight = $now->modify('+1 day')->setTime(0, 0, 0);
            return [
                'isBlocked' => true,
                'reason'    => 'limit',
                'unlockAt'  => $this->toUtcIso($midnight),
            ];
        }

        return [
            'isBlocked' => false,
            'reason'    => null,
            'unlockAt'  => null,
        ];
    }

    /**
     * Próxima fronteira de relógio determinística (bedtime/weekday), em UTC ISO.
     *
     * Candidatos: a próxima meia-noite local (piso — cobre reset de limite e
     * virada de weekday) e os bedtime_start/bedtime_end de hoje e de amanhã.
     * Devolve o menor candidato que seja futuro. O boundary do limite diário
     * NÃO entra (depende de uso, não de relógio) — é capturado por polling.
     *
     * @param array{bedtime_enabled?:int|bool|null,bedtime_start?:?string,bedtime_end?:?string} $config
     */
    public function nextDeterministicChange(array $config, DateTimeImmutable $now): ?string
    {
        $candidates = [$now->modify('+1 day')->setTime(0, 0, 0)]; // meia-noite local

        $enabled = (int) ($config['bedtime_enabled'] ?? 0) === 1;
        $start   = $config['bedtime_start'] ?? null;
        $end     = $config['bedtime_end'] ?? null;

        if (
            $enabled
            && is_string($start) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $start) === 1
            && is_string($end) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $end) === 1
            && $start !== $end
        ) {
            foreach ([0, 1] as $offset) {
                $day = $now->modify("+{$offset} day");
                $candidates[] = $day->setTime(
                    (int) substr($start, 0, 2),
                    (int) substr($start, 3, 2),
                    (int) substr($start, 6, 2)
                );
                $candidates[] = $day->setTime(
                    (int) substr($end, 0, 2),
                    (int) substr($end, 3, 2),
                    (int) substr($end, 6, 2)
                );
            }
        }

        $future = array_values(array_filter(
            $candidates,
            static fn (DateTimeImmutable $dt): bool => $dt > $now
        ));
        if ($future === []) {
            return null;
        }
        usort($future, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);

        return $this->toUtcIso($future[0]);
    }

    private function nextAllowedMidnightUtc(string $weekdays, DateTimeImmutable $now): ?string
    {
        for ($offset = 1; $offset <= 7; $offset++) {
            $candidate = $now->modify("+{$offset} day")->setTime(0, 0, 0);
            $candIdx   = (int) $candidate->format('N') - 1;
            if ($weekdays[$candIdx] === 'Y') {
                return $this->toUtcIso($candidate);
            }
        }
        return null; // 'NNNNNNN' — sem horizonte de liberação
    }

    private function toUtcIso(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
