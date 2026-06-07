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
 * Ordem de precedência: weekday > bedtime (dia inteiro bloqueado
 * não vira false-positive de "bedtime curto às 14h").
 */
final class ScheduleEvaluator
{
    /**
     * @param array{
     *   bedtime_enabled?: int|bool|null,
     *   bedtime_start?:   ?string,
     *   bedtime_end?:     ?string,
     *   allowed_weekdays?: ?string,
     * } $config
     *
     * @return array{
     *   isBlocked: bool,
     *   reason:    'bedtime'|'weekday'|null,
     *   unlockAt:  ?string,
     * }
     */
    public function evaluate(array $config, DateTimeImmutable $now): array
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

        if ($enabled && is_string($start) && is_string($end) && $start !== $end) {
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
                        'unlockAt'  => $endDt->setTimezone(new DateTimeZone('UTC'))
                                             ->format('Y-m-d\TH:i:s\Z'),
                    ];
                }
            } else {
                // Cross-midnight: bloqueado se now >= start OR now < end
                if ($now >= $startDt) {
                    $unlock = $endDt->modify('+1 day');
                    return [
                        'isBlocked' => true,
                        'reason'    => 'bedtime',
                        'unlockAt'  => $unlock->setTimezone(new DateTimeZone('UTC'))
                                              ->format('Y-m-d\TH:i:s\Z'),
                    ];
                }
                if ($now < $endDt) {
                    return [
                        'isBlocked' => true,
                        'reason'    => 'bedtime',
                        'unlockAt'  => $endDt->setTimezone(new DateTimeZone('UTC'))
                                              ->format('Y-m-d\TH:i:s\Z'),
                    ];
                }
            }
        }

        return [
            'isBlocked' => false,
            'reason'    => null,
            'unlockAt'  => null,
        ];
    }

    private function nextAllowedMidnightUtc(string $weekdays, DateTimeImmutable $now): ?string
    {
        for ($offset = 1; $offset <= 7; $offset++) {
            $candidate = $now->modify("+{$offset} day")->setTime(0, 0, 0);
            $candIdx   = (int) $candidate->format('N') - 1;
            if ($weekdays[$candIdx] === 'Y') {
                return $candidate->setTimezone(new DateTimeZone('UTC'))
                                 ->format('Y-m-d\TH:i:s\Z');
            }
        }
        return null; // 'NNNNNNN' — sem horizonte de liberação
    }
}
