<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use GuardKids\Schedule\ScheduleEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * ScheduleEvaluator — função pura, sem $wpdb, sem current_time.
 * Recebe (config, $now) e devolve isBlocked/reason/unlockAt.
 *
 * Convenções:
 *   - allowed_weekdays é CHAR(7) com pos 0=Mon … 6=Sun
 *   - bedtime_start/end são TIME (HH:MM:SS) em local time
 *   - unlockAt é ISO-8601 UTC ('Y-m-d\TH:i:s\Z')
 */
final class ScheduleEvaluatorTest extends TestCase
{
    private ScheduleEvaluator $svc;
    private DateTimeZone $tz;

    protected function setUp(): void
    {
        $this->svc = new ScheduleEvaluator();
        $this->tz  = new DateTimeZone('America/Sao_Paulo');
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'bedtime_enabled'  => 0,
            'bedtime_start'    => null,
            'bedtime_end'      => null,
            'allowed_weekdays' => 'YYYYYYY',
        ], $overrides);
    }

    public function testWeekdayAllowedAndNoBedtimeReturnsUnblocked(): void
    {
        // Segunda 2026-06-08 14:00 local
        $now = new DateTimeImmutable('2026-06-08 14:00:00', $this->tz);
        $res = $this->svc->evaluate($this->config(), $now);

        self::assertFalse($res['isBlocked']);
        self::assertNull($res['reason']);
        self::assertNull($res['unlockAt']);
    }

    public function testWeekdayDisallowedReturnsBlockedWithUnlockAtNextAllowedMidnight(): void
    {
        // 'YYYYYNN' = seg-sex Y, sáb-dom N. Sábado 2026-06-13 14:00 local.
        $now = new DateTimeImmutable('2026-06-13 14:00:00', $this->tz);
        $res = $this->svc->evaluate($this->config(['allowed_weekdays' => 'YYYYYNN']), $now);

        self::assertTrue($res['isBlocked']);
        self::assertSame('weekday', $res['reason']);
        // Próximo Y = Segunda 2026-06-15 00:00 local = 03:00 UTC (Sao_Paulo é -03)
        self::assertSame('2026-06-15T03:00:00Z', $res['unlockAt']);
    }

    public function testAllWeekdaysDisallowedReturnsUnlockAtNull(): void
    {
        $now = new DateTimeImmutable('2026-06-08 10:00:00', $this->tz);
        $res = $this->svc->evaluate($this->config(['allowed_weekdays' => 'NNNNNNN']), $now);

        self::assertTrue($res['isBlocked']);
        self::assertSame('weekday', $res['reason']);
        self::assertNull($res['unlockAt']);
    }

    public function testBedtimeDisabledIgnoresStartEndEvenIfPresent(): void
    {
        $now = new DateTimeImmutable('2026-06-08 14:00:00', $this->tz);
        $res = $this->svc->evaluate($this->config([
            'bedtime_enabled' => 0,
            'bedtime_start'   => '13:00:00',
            'bedtime_end'     => '15:00:00',
        ]), $now);

        self::assertFalse($res['isBlocked']);
    }

    public function testBedtimeNormalWindowBlocksMidWindow(): void
    {
        // 13:00-15:00, now=14:00 → blocked até 15:00 local (=18:00 UTC)
        $now = new DateTimeImmutable('2026-06-08 14:00:00', $this->tz);
        $res = $this->svc->evaluate($this->config([
            'bedtime_enabled' => 1,
            'bedtime_start'   => '13:00:00',
            'bedtime_end'     => '15:00:00',
        ]), $now);

        self::assertTrue($res['isBlocked']);
        self::assertSame('bedtime', $res['reason']);
        self::assertSame('2026-06-08T18:00:00Z', $res['unlockAt']);
    }

    public function testBedtimeNormalReturnsUnblockedAtExactEnd(): void
    {
        // Boundary half-open: now == end → libera
        $now = new DateTimeImmutable('2026-06-08 15:00:00', $this->tz);
        $res = $this->svc->evaluate($this->config([
            'bedtime_enabled' => 1,
            'bedtime_start'   => '13:00:00',
            'bedtime_end'     => '15:00:00',
        ]), $now);

        self::assertFalse($res['isBlocked']);
    }

    public function testBedtimeWithStartEqualsEndDoesNotBlock(): void
    {
        $now = new DateTimeImmutable('2026-06-08 14:00:00', $this->tz);
        $res = $this->svc->evaluate($this->config([
            'bedtime_enabled' => 1,
            'bedtime_start'   => '14:00:00',
            'bedtime_end'     => '14:00:00',
        ]), $now);

        self::assertFalse($res['isBlocked']);
    }

    public function testBedtimeCrossMidnightBlocksLateEveningUnlockTomorrow(): void
    {
        // 22:00-07:00, now=23:00 sex → unlockAt sáb 07:00 local = sáb 10:00 UTC
        $now = new DateTimeImmutable('2026-06-12 23:00:00', $this->tz);
        $res = $this->svc->evaluate($this->config([
            'bedtime_enabled' => 1,
            'bedtime_start'   => '22:00:00',
            'bedtime_end'     => '07:00:00',
        ]), $now);

        self::assertTrue($res['isBlocked']);
        self::assertSame('bedtime', $res['reason']);
        self::assertSame('2026-06-13T10:00:00Z', $res['unlockAt']);
    }

    public function testBedtimeCrossMidnightBlocksEarlyMorningUnlockToday(): void
    {
        // 22:00-07:00, now=06:00 sáb → unlockAt sáb 07:00 local = 10:00 UTC
        $now = new DateTimeImmutable('2026-06-13 06:00:00', $this->tz);
        $res = $this->svc->evaluate($this->config([
            'bedtime_enabled' => 1,
            'bedtime_start'   => '22:00:00',
            'bedtime_end'     => '07:00:00',
        ]), $now);

        self::assertTrue($res['isBlocked']);
        self::assertSame('2026-06-13T10:00:00Z', $res['unlockAt']);
    }

    public function testBedtimeCrossMidnightDoesNotBlockMorningAfterEnd(): void
    {
        $now = new DateTimeImmutable('2026-06-13 08:00:00', $this->tz);
        $res = $this->svc->evaluate($this->config([
            'bedtime_enabled' => 1,
            'bedtime_start'   => '22:00:00',
            'bedtime_end'     => '07:00:00',
        ]), $now);

        self::assertFalse($res['isBlocked']);
    }

    public function testWeekdayTakesPrecedenceOverBedtime(): void
    {
        // Domingo (idx 6 = N), bedtime enabled mas weekday vence
        $now = new DateTimeImmutable('2026-06-14 14:00:00', $this->tz);
        $res = $this->svc->evaluate($this->config([
            'allowed_weekdays' => 'YYYYYYN',
            'bedtime_enabled'  => 1,
            'bedtime_start'    => '13:00:00',
            'bedtime_end'      => '15:00:00',
        ]), $now);

        self::assertTrue($res['isBlocked']);
        self::assertSame('weekday', $res['reason']);
    }

    public function testInvalidWeekdaysStringFallsBackToAllAllowed(): void
    {
        $now = new DateTimeImmutable('2026-06-08 14:00:00', $this->tz);
        $res = $this->svc->evaluate($this->config(['allowed_weekdays' => 'lixo']), $now);

        self::assertFalse($res['isBlocked']);
    }

    public function testMissingFieldsDefaultsSafely(): void
    {
        $now = new DateTimeImmutable('2026-06-08 14:00:00', $this->tz);
        $res = $this->svc->evaluate([], $now);

        self::assertFalse($res['isBlocked']);
    }
}
