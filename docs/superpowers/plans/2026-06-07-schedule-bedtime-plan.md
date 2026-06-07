# Schedule (bedtime + weekday) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fazer `BedtimeCard` e `WeeklyCard` do `TimeLimits.tsx` persistirem em `wp_guardkids_children`, e o `app-child` (PWA) entrar em `<Blocked />` automaticamente quando o servidor disser que está fora do horário/dia permitido.

**Architecture:** Migration 003 adiciona 4 colunas em `children`. `ScheduleEvaluator` (PHP puro) decide `isBlocked`/`reason`/`unlockAt` a partir de `(config, $now)`. `PATCH /children/:id` ganha 4 args novos. `GET /child/me` enriquece com bloco `schedule`. Parent persiste via `updateChild`. Child polla `/child/me` (60s + refetch on focus), `App.tsx` força `<Blocked />` quando bloqueado, `Blocked.tsx` lê `unlockAt` real.

**Tech Stack:** PHP 8.1+/WordPress, PHPUnit 9.6 (stubs minimos do WP, sem DB), React 19 + Vite + TypeScript + TanStack Query 5 + Vitest 2 + Testing Library.

**Spec:** `docs/superpowers/specs/2026-06-07-schedule-bedtime-design.md` (commit `23bb363`).

---

## File Structure

**Backend (PHP):**
- Create: `database/migrations/003_schedule_columns.php` — `ALTER TABLE` adicionando 4 colunas.
- Create: `includes/Schedule/ScheduleEvaluator.php` — serviço puro, `evaluate($config, $now): array`.
- Create: `tests/Unit/Schedule/ScheduleEvaluatorTest.php` — ~15 cases (weekday, bedtime normal, cross-midnight, edges).
- Modify: `api/Controllers/ChildController.php` — `createArgs/updateArgs` + `update()` + `toJson()` com 4 campos novos + validação de bedtime.
- Modify: `api/Controllers/ChildSelfController.php` — `me()` instancia `ScheduleEvaluator` e devolve `schedule`; `childToJson()` inclui campos novos.
- Modify: `tests/Unit/Database/MigrationRunnerTest.php` — 1 caso novo afirmando migration 003 aplica colunas.
- Create: `tests/Unit/Api/ChildControllerScheduleTest.php` — ~6 cases de PATCH ampliado.
- Create: `tests/Unit/Api/ChildSelfMeScheduleTest.php` — ~3 cases de `/me` com schedule.
- Modify: `guardkids.php` — `GUARDKIDS_DB_VERSION: 2 → 3`.

**Frontend parent (`public/app-parent/`):**
- Modify: `src/api/types.ts` — `Child` ganha `bedtimeEnabled/Start/End` + `allowedWeekdays`.
- Modify: `src/api/children.ts` — `UpdateChildInput` ganha os 4 campos.
- Create: `src/lib/weekdays.ts` — `parseWeekdays` + `serializeWeekdays`.
- Create: `src/lib/weekdays.test.ts` — ~6 cases.
- Modify: `src/pages/TimeLimits.tsx` — `BedtimeCard` e `WeeklyCard` ligados a `updateChild`, copy de `TimelineCard` ajustado, badges removidos dos persistentes.
- Modify: `src/pages/TimeLimits.test.tsx` — +5 cases novos.

**Frontend child (`public/app-child/`):**
- Modify: `package.json` — adicionar `@testing-library/react`, `@testing-library/jest-dom`, `@testing-library/user-event`, `jsdom`, `@vitejs/plugin-react`.
- Modify: `vitest.config.ts` — `environment: 'jsdom'`, `setupFiles`, plugin React.
- Create: `src/test/setup.ts` — espelha `app-parent`.
- Create: `src/api/me.ts` — `fetchMe(token)`.
- Modify: `src/App.tsx` — `useQuery(['me'])` com refetch 60s + on focus; força `<Blocked />` se `isBlocked`.
- Modify: `src/pages/Blocked.tsx` — props `{ reason, unlockAt, onNavigate }`, sem mock, contador real.
- Modify: `src/main.tsx` — envolve `<App/>` em `<QueryClientProvider>` (hoje não tem).
- Create: `src/pages/Blocked.test.tsx` — ~5 cases.
- Create: `src/App.test.tsx` — ~4 cases.

**Docs:**
- Modify: `README.md` — badge `tests-264` → `tests-309`; ajustar descrição.

---

## Task 1: Migration 003 — schema novo

**Files:**
- Create: `database/migrations/003_schedule_columns.php`
- Modify: `tests/Unit/Database/MigrationRunnerTest.php` (adiciona 1 caso)
- Modify: `guardkids.php:22` (`GUARDKIDS_DB_VERSION: 2 → 3`)

- [ ] **Step 1.1: Criar migration 003 vazia (estrutura mínima pra ser carregada)**

Criar `database/migrations/003_schedule_columns.php`:

```php
<?php

declare(strict_types=1);

/**
 * Migration 003 — schedule (bedtime + allowed_weekdays) em children.
 *
 * Adiciona 4 colunas em wp_guardkids_children:
 *   - bedtime_start TIME NULL
 *   - bedtime_end   TIME NULL
 *   - bedtime_enabled TINYINT(1) NOT NULL DEFAULT 0
 *   - allowed_weekdays CHAR(7) NOT NULL DEFAULT 'YYYYYYY' (pos 0 = Mon)
 *
 * dbDelta é idempotente em ALTER ADD COLUMN.
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $table = $wpdb->prefix . 'guardkids_children';

    $sql = "ALTER TABLE {$table}
        ADD COLUMN bedtime_start    TIME       NULL                        AFTER limit_minutes,
        ADD COLUMN bedtime_end      TIME       NULL                        AFTER bedtime_start,
        ADD COLUMN bedtime_enabled  TINYINT(1) NOT NULL DEFAULT 0          AFTER bedtime_end,
        ADD COLUMN allowed_weekdays CHAR(7)    NOT NULL DEFAULT 'YYYYYYY'  AFTER bedtime_enabled";

    dbDelta($sql);
};
```

- [ ] **Step 1.2: Bump GUARDKIDS_DB_VERSION pra 3 em guardkids.php**

Editar `guardkids.php`, linha 22:

```php
define('GUARDKIDS_DB_VERSION', 3);
```

- [ ] **Step 1.3: Adicionar caso novo em MigrationRunnerTest afirmando que 003 é detectada e roda**

Editar `tests/Unit/Database/MigrationRunnerTest.php`. Adicionar este teste ao final da classe (antes do último `}`):

```php
    public function testRealMigrationsDirectoryIncludesUpTo003(): void
    {
        // Aponta pro diretório real do plugin pra garantir que as 3 migrations
        // físicas (001, 002, 003) são descobertas em ordem.
        $dir = dirname(__DIR__, 3) . '/database/migrations/';

        $files = glob($dir . '*.php') ?: [];
        $names = array_map(static fn (string $f): string => basename($f), $files);
        sort($names);

        self::assertContains('001_initial_schema.php', $names);
        self::assertContains('002_usage_events.php', $names);
        self::assertContains('003_schedule_columns.php', $names);
    }
```

- [ ] **Step 1.4: Rodar PHPUnit pra confirmar a suíte verde com o novo caso**

Run:

```powershell
$php = "$env:APPDATA\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe"
& $php -d extension_dir="$(Split-Path $php)\ext" -d extension=openssl -d extension=mbstring -d extension=zip -d extension=fileinfo vendor/bin/phpunit
```

Expected: `OK (98 tests, ≥244 assertions)` (uma a mais que os 97 baseline).

- [ ] **Step 1.5: Commit**

```powershell
git add database/migrations/003_schedule_columns.php guardkids.php tests/Unit/Database/MigrationRunnerTest.php
git commit -m "feat(db): migration 003 adiciona schedule (bedtime + weekday) em children"
```

---

## Task 2: ScheduleEvaluator — serviço puro PHP

**Files:**
- Create: `includes/Schedule/ScheduleEvaluator.php`
- Create: `tests/Unit/Schedule/ScheduleEvaluatorTest.php`

- [ ] **Step 2.1: Escrever teste falhando — caso "weekday Y, sem bedtime → unblocked"**

Criar `tests/Unit/Schedule/ScheduleEvaluatorTest.php`:

```php
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
}
```

- [ ] **Step 2.2: Rodar e confirmar que falha por classe inexistente**

Run:

```powershell
& $php -d extension_dir="$(Split-Path $php)\ext" -d extension=openssl -d extension=mbstring -d extension=zip -d extension=fileinfo vendor/bin/phpunit --filter ScheduleEvaluatorTest
```

Expected: erro `Class "GuardKids\Schedule\ScheduleEvaluator" not found`.

- [ ] **Step 2.3: Implementar ScheduleEvaluator esqueleto pra passar o primeiro teste**

Criar `includes/Schedule/ScheduleEvaluator.php`:

```php
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
        return [
            'isBlocked' => false,
            'reason'    => null,
            'unlockAt'  => null,
        ];
    }
}
```

- [ ] **Step 2.4: Rodar e confirmar primeiro teste verde**

Run: `& $php ... vendor/bin/phpunit --filter ScheduleEvaluatorTest`

Expected: `OK (1 test)`.

- [ ] **Step 2.5: Adicionar testes de weekday N e implementar lógica**

Adicionar à `ScheduleEvaluatorTest.php`:

```php
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
```

Implementar (substituir corpo de `evaluate`):

```php
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
```

- [ ] **Step 2.6: Rodar e confirmar 3 testes verdes**

Run: `& $php ... vendor/bin/phpunit --filter ScheduleEvaluatorTest`

Expected: `OK (3 tests)`.

- [ ] **Step 2.7: Adicionar testes de bedtime normal (start < end) e implementar**

Adicionar à `ScheduleEvaluatorTest.php`:

```php
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
```

Estender `evaluate` (adicionar ANTES do return final unblocked, DEPOIS do weekday check):

```php
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
            }
        }
```

- [ ] **Step 2.8: Rodar e confirmar 7 testes verdes**

Run: `& $php ... vendor/bin/phpunit --filter ScheduleEvaluatorTest`

Expected: `OK (7 tests)`.

- [ ] **Step 2.9: Adicionar testes de cross-midnight e implementar**

Adicionar à `ScheduleEvaluatorTest.php`:

```php
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
```

Estender o `if ($startDt < $endDt)` com um `else` cobrindo cross-midnight:

```php
            if ($startDt < $endDt) {
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
```

- [ ] **Step 2.10: Rodar e confirmar 10 testes verdes**

Run: `& $php ... vendor/bin/phpunit --filter ScheduleEvaluatorTest`

Expected: `OK (10 tests)`.

- [ ] **Step 2.11: Adicionar teste de precedência weekday > bedtime**

```php
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
```

Run e confirmar: `OK (11 tests)`. Lógica atual já satisfaz (weekday tem `return` antes de bedtime).

- [ ] **Step 2.12: Adicionar smoke test de fallback pra config inválida**

```php
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
```

Run: `OK (13 tests)`. Já passam pelo fallback regex no início.

- [ ] **Step 2.13: Rodar suíte inteira pra confirmar nada quebrou**

Run: `& $php ... vendor/bin/phpunit`

Expected: `OK (110 tests, ≥256 assertions)` (baseline 97 + 1 migration + 13 evaluator).

- [ ] **Step 2.14: Commit**

```powershell
git add includes/Schedule/ScheduleEvaluator.php tests/Unit/Schedule/ScheduleEvaluatorTest.php
git commit -m "feat(schedule): ScheduleEvaluator puro PHP (weekday + bedtime, cross-midnight)"
```

---

## Task 3: PATCH /children/:id — 4 args novos + validação

**Files:**
- Modify: `api/Controllers/ChildController.php` (`createArgs`, `update`, `toJson`)
- Create: `tests/Unit/Api/ChildControllerScheduleTest.php`

- [ ] **Step 3.1: Escrever teste falhando — PATCH persiste allowed_weekdays**

Criar `tests/Unit/Api/ChildControllerScheduleTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\ChildController;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * ChildController — args + validação do schedule (Fase 8).
 *
 * Mesma estrutura de fake $wpdb que ChildControllerTest, com inspeção
 * do log['update'] pra verificar o que foi persistido.
 */
final class ChildControllerScheduleTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];
            /** @var array<int, array{method:string, args:array}> */
            public array $log = [];

            public function __construct() {}

            public function prepare($query, ...$args)
            {
                $flat = $args[0] ?? null;
                if (is_array($flat)) { $args = $flat; }
                return vsprintf(str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%F'], (string) $query), $args);
            }

            public function get_row($sql, $output = OBJECT, $y = 0)
            {
                if (preg_match('/WHERE id = (\d+)/', (string) $sql, $m) === 1) {
                    return $this->rows[(int) $m[1]] ?? null;
                }
                return null;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                $this->log[] = ['method' => 'update', 'args' => [$table, $data, $where]];
                $id = (int) ($where['id'] ?? 0);
                if (isset($this->rows[$id])) {
                    $this->rows[$id] = array_merge($this->rows[$id], $data);
                }
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->wpdb->rows[1] = [
            'id' => 1, 'slug' => 'lucas', 'name' => 'Lucas',
            'status' => 'online', 'used_minutes' => 0, 'limit_minutes' => 60,
            'bedtime_enabled' => 0, 'bedtime_start' => null, 'bedtime_end' => null,
            'allowed_weekdays' => 'YYYYYYY',
        ];
    }

    private function makeRequest(array $params): WP_REST_Request
    {
        $req = new WP_REST_Request('PATCH', '/children/1');
        $req['id'] = 1;
        foreach ($params as $k => $v) {
            $req->set_param($k, $v);
        }
        return $req;
    }

    public function testUpdateAllowedWeekdaysPersists(): void
    {
        $req = $this->makeRequest(['allowed_weekdays' => 'YYYYYNN']);
        $res = (new ChildController())->update($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        $update = array_filter($this->wpdb->log, fn ($e) => $e['method'] === 'update');
        self::assertNotEmpty($update);
        $patch = array_values($update)[0]['args'][1];
        self::assertSame('YYYYYNN', $patch['allowed_weekdays']);
    }
}
```

- [ ] **Step 3.2: Rodar e confirmar que falha (campo não passado)**

Run:

```powershell
& $php ... vendor/bin/phpunit --filter ChildControllerScheduleTest
```

Expected: `Failed asserting that array is not empty` (ou similar — `update()` filtra `null`, e `allowed_weekdays` não está no array_filter).

- [ ] **Step 3.3: Estender createArgs() em ChildController**

Editar `api/Controllers/ChildController.php`, método `createArgs`:

```php
    public function createArgs(): array
    {
        return [
            'name'          => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            'slug'          => ['type' => 'string', 'sanitize_callback' => 'sanitize_title'],
            'age'           => ['type' => 'integer', 'minimum' => 0, 'maximum' => 21],
            'avatar_url'    => ['type' => 'string', 'sanitize_callback' => 'esc_url_raw'],
            'device'        => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            'limit_minutes' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1440],
            'bedtime_enabled' => [
                'type'    => 'boolean',
                'default' => null,
            ],
            'bedtime_start' => [
                'type'              => 'string',
                'pattern'           => '^([01]\\d|2[0-3]):[0-5]\\d$',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'bedtime_end' => [
                'type'              => 'string',
                'pattern'           => '^([01]\\d|2[0-3]):[0-5]\\d$',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'allowed_weekdays' => [
                'type'              => 'string',
                'pattern'           => '^[YN]{7}$',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }
```

- [ ] **Step 3.4: Estender update() pra processar os 4 campos e validar bedtime**

Substituir o corpo do método `update` em `ChildController.php`:

```php
    public function update(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id  = (int) $req['id'];
        $row = $this->repo->findById($id);
        if ($row === null) {
            return new WP_Error('not_found', 'Filho não encontrado.', ['status' => 404]);
        }

        $bedtimeEnabledParam = $req->get_param('bedtime_enabled');
        $bedtimeStartParam   = $req->get_param('bedtime_start');
        $bedtimeEndParam     = $req->get_param('bedtime_end');

        // Validação: enabled=true exige start e end (na request ou no row atual)
        $futureEnabled = $bedtimeEnabledParam === null
            ? ((int) ($row['bedtime_enabled'] ?? 0) === 1)
            : (bool) $bedtimeEnabledParam;

        if ($futureEnabled) {
            $futureStart = $bedtimeStartParam ?? ($row['bedtime_start'] ?? null);
            $futureEnd   = $bedtimeEndParam   ?? ($row['bedtime_end']   ?? null);
            if (! is_string($futureStart) || ! is_string($futureEnd) || $futureStart === '' || $futureEnd === '') {
                return new WP_Error('invalid_payload', 'bedtime_enabled exige start e end definidos.', ['status' => 422]);
            }
        }

        $patch = array_filter([
            'name'             => $req->get_param('name'),
            'age'              => $req->get_param('age'),
            'avatar_url'       => $req->get_param('avatar_url'),
            'device'           => $req->get_param('device'),
            'limit_minutes'    => $req->get_param('limit_minutes'),
            'bedtime_enabled'  => $bedtimeEnabledParam === null ? null : ((int) ((bool) $bedtimeEnabledParam)),
            'bedtime_start'    => $this->coerceTime($bedtimeStartParam),
            'bedtime_end'      => $this->coerceTime($bedtimeEndParam),
            'allowed_weekdays' => $req->get_param('allowed_weekdays'),
        ], static fn ($v): bool => $v !== null);

        if ($patch !== [] && ! $this->repo->update($id, $patch)) {
            return new WP_Error('db_error', 'Falha ao atualizar.', ['status' => 500]);
        }
        return rest_ensure_response($this->toJson($this->repo->findById($id) ?? []));
    }

    /**
     * Converte 'HH:MM' (validado por pattern) em 'HH:MM:00' pra coluna TIME.
     */
    private function coerceTime(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        return preg_match('/^\d{2}:\d{2}$/', $value) === 1 ? $value . ':00' : $value;
    }
```

- [ ] **Step 3.5: Estender toJson() em ChildController pra incluir os 4 campos**

No `private function toJson` em `ChildController.php`, adicionar antes de `'createdAt'`:

```php
            'bedtimeEnabled'  => (int) ($row['bedtime_enabled'] ?? 0) === 1,
            'bedtimeStart'    => isset($row['bedtime_start']) && is_string($row['bedtime_start'])
                                 ? substr($row['bedtime_start'], 0, 5) : null,
            'bedtimeEnd'      => isset($row['bedtime_end']) && is_string($row['bedtime_end'])
                                 ? substr($row['bedtime_end'], 0, 5) : null,
            'allowedWeekdays' => (string) ($row['allowed_weekdays'] ?? 'YYYYYYY'),
```

- [ ] **Step 3.6: Rodar teste e confirmar verde**

Run: `& $php ... vendor/bin/phpunit --filter ChildControllerScheduleTest`

Expected: `OK (1 test)`.

- [ ] **Step 3.7: Adicionar 5 testes de validação restantes**

Adicionar à `ChildControllerScheduleTest.php`:

```php
    public function testUpdateBedtimeFieldsPersist(): void
    {
        $req = $this->makeRequest([
            'bedtime_enabled' => true,
            'bedtime_start'   => '21:30',
            'bedtime_end'     => '07:00',
        ]);
        $res = (new ChildController())->update($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        $patch = end($this->wpdb->log)['args'][1];
        self::assertSame(1, $patch['bedtime_enabled']);
        self::assertSame('21:30:00', $patch['bedtime_start']);
        self::assertSame('07:00:00', $patch['bedtime_end']);
    }

    public function testUpdateBedtimeEnabledTrueWithoutStartReturns422(): void
    {
        $req = $this->makeRequest(['bedtime_enabled' => true]);
        $res = (new ChildController())->update($req);

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(422, $res->get_error_data()['status']);
        self::assertSame('invalid_payload', $res->get_error_code());
    }

    public function testUpdateBedtimeEnabledTrueWithExistingStartEndIsAllowed(): void
    {
        // Row já tem start/end persistidos
        $this->wpdb->rows[1]['bedtime_start'] = '21:00:00';
        $this->wpdb->rows[1]['bedtime_end']   = '06:00:00';

        $req = $this->makeRequest(['bedtime_enabled' => true]);
        $res = (new ChildController())->update($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
    }

    public function testUpdatePartialDoesNotTouchOtherFields(): void
    {
        $req = $this->makeRequest(['allowed_weekdays' => 'YYYYYNN']);
        (new ChildController())->update($req);

        $patch = end($this->wpdb->log)['args'][1];
        self::assertArrayNotHasKey('bedtime_enabled', $patch);
        self::assertArrayNotHasKey('bedtime_start',   $patch);
        self::assertArrayNotHasKey('bedtime_end',     $patch);
        self::assertArrayNotHasKey('limit_minutes',   $patch);
    }

    public function testToJsonIncludesScheduleFields(): void
    {
        $this->wpdb->rows[1]['bedtime_enabled']  = 1;
        $this->wpdb->rows[1]['bedtime_start']    = '21:30:00';
        $this->wpdb->rows[1]['bedtime_end']      = '07:00:00';
        $this->wpdb->rows[1]['allowed_weekdays'] = 'YYYYYNN';

        $req = new WP_REST_Request('GET', '/children/1');
        $req['id'] = 1;
        $res = (new ChildController())->show($req);

        $data = $res->get_data();
        self::assertTrue($data['bedtimeEnabled']);
        self::assertSame('21:30', $data['bedtimeStart']);
        self::assertSame('07:00', $data['bedtimeEnd']);
        self::assertSame('YYYYYNN', $data['allowedWeekdays']);
    }
```

- [ ] **Step 3.8: Rodar todos os 6 e confirmar verde**

Run: `& $php ... vendor/bin/phpunit --filter ChildControllerScheduleTest`

Expected: `OK (6 tests)`.

- [ ] **Step 3.9: Rodar suíte inteira pra confirmar nada regrediu**

Run: `& $php ... vendor/bin/phpunit`

Expected: `OK (116 tests, ≥...)`.

- [ ] **Step 3.10: Commit**

```powershell
git add api/Controllers/ChildController.php tests/Unit/Api/ChildControllerScheduleTest.php
git commit -m "feat(api): PATCH /children/:id aceita bedtime + allowed_weekdays"
```

---

## Task 4: GET /child/me — devolve bloco schedule

**Files:**
- Modify: `api/Controllers/ChildSelfController.php` (`me`, `childToJson`)
- Create: `tests/Unit/Api/ChildSelfMeScheduleTest.php`

- [ ] **Step 4.1: Escrever teste falhando — /me devolve schedule.isBlocked=false**

Criar `tests/Unit/Api/ChildSelfMeScheduleTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\ChildSelfController;
use GuardKids\Auth\ChildAuth;
use GuardKids\Database\SettingsRepository;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * ChildSelfController::me() — verifica que a resposta inclui `schedule`
 * calculado pelo ScheduleEvaluator. Usa o evaluator real (puro, sem mock).
 *
 * Stub de wp_timezone() é necessário porque o controller chama a função.
 */
final class ChildSelfMeScheduleTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];

            public function __construct() {}

            public function prepare($query, ...$args)
            {
                $flat = $args[0] ?? null;
                if (is_array($flat)) { $args = $flat; }
                return vsprintf(str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%F'], (string) $query), $args);
            }

            public function get_row($sql, $output = OBJECT, $y = 0)
            {
                // Token lookup via SettingsRepository — devolve hash mapeando a child 1
                if (str_contains((string) $sql, 'guardkids_settings')) {
                    return [
                        'id' => 1,
                        'setting_key' => 'guardkids_child_token_' . hash('sha256', 'fixed-token'),
                        'value' => wp_json_encode(['child_id' => 1, 'label' => null, 'issued_at' => '2026-06-08T10:00:00Z']),
                        'updated_at' => '2026-06-08 10:00:00',
                    ];
                }
                if (preg_match('/WHERE id = (\d+)/', (string) $sql, $m) === 1) {
                    return $this->rows[(int) $m[1]] ?? null;
                }
                return null;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;

        if (! function_exists('wp_timezone')) {
            eval("function wp_timezone() { return new \\DateTimeZone('America/Sao_Paulo'); }");
        }

        $this->wpdb->rows[1] = [
            'id' => 1, 'slug' => 'lucas', 'name' => 'Lucas',
            'status' => 'online', 'used_minutes' => 0, 'limit_minutes' => 60,
            'bedtime_enabled' => 0, 'bedtime_start' => null, 'bedtime_end' => null,
            'allowed_weekdays' => 'YYYYYYY',
        ];
    }

    private function authedRequest(): WP_REST_Request
    {
        $req = new WP_REST_Request('GET', '/child/me');
        $req->set_header('X-GuardKids-Token', 'fixed-token');
        return $req;
    }

    public function testMeIncludesScheduleFalseWhenAllAllowed(): void
    {
        $res = (new ChildSelfController())->me($this->authedRequest());

        self::assertInstanceOf(WP_REST_Response::class, $res);
        $data = $res->get_data();
        self::assertArrayHasKey('schedule', $data);
        self::assertFalse($data['schedule']['isBlocked']);
        self::assertNull($data['schedule']['reason']);
        self::assertNull($data['schedule']['unlockAt']);
    }
}
```

- [ ] **Step 4.2: Rodar e confirmar que falha (sem `schedule` na resposta)**

Run: `& $php ... vendor/bin/phpunit --filter ChildSelfMeScheduleTest`

Expected: `Failed asserting that an array has the key 'schedule'`.

- [ ] **Step 4.3: Estender `me()` e `childToJson()` em ChildSelfController**

Editar `api/Controllers/ChildSelfController.php`:

1. Adicionar `use GuardKids\Schedule\ScheduleEvaluator;` no topo.
2. Adicionar propriedade e inicialização no construtor:

```php
    private readonly ScheduleEvaluator $evaluator;

    public function __construct()
    {
        $this->auth      = new ChildAuth();
        $this->children  = new ChildRepository();
        $this->requests  = new RequestRepository();
        $this->events    = new UsageEventRepository();
        $this->evaluator = new ScheduleEvaluator();
    }
```

3. Substituir o método `me()`:

```php
    public function me(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        $row = $this->children->findById($childId);
        if ($row === null) {
            return new WP_Error('not_found', 'Filho não encontrado.', ['status' => 404]);
        }

        $tz       = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $now      = new \DateTimeImmutable('now', $tz);
        $schedule = $this->evaluator->evaluate($row, $now);

        return rest_ensure_response(
            $this->childToJson($row) + ['schedule' => $schedule]
        );
    }
```

4. Estender `childToJson()` (adicionar antes do `return`):

```php
    private function childToJson(array $row): array
    {
        return [
            'id'           => (int) ($row['id'] ?? 0),
            'slug'         => (string) ($row['slug'] ?? ''),
            'name'         => (string) ($row['name'] ?? ''),
            'age'          => isset($row['age']) ? (int) $row['age'] : null,
            'avatarUrl'    => $row['avatar_url'] ?? null,
            'device'       => $row['device'] ?? null,
            'status'       => (string) ($row['status'] ?? 'offline'),
            'usedMinutes'  => (int) ($row['used_minutes'] ?? 0),
            'limitMinutes' => (int) ($row['limit_minutes'] ?? 60),
            'bedtimeEnabled'  => (int) ($row['bedtime_enabled'] ?? 0) === 1,
            'bedtimeStart'    => isset($row['bedtime_start']) && is_string($row['bedtime_start'])
                                 ? substr($row['bedtime_start'], 0, 5) : null,
            'bedtimeEnd'      => isset($row['bedtime_end']) && is_string($row['bedtime_end'])
                                 ? substr($row['bedtime_end'], 0, 5) : null,
            'allowedWeekdays' => (string) ($row['allowed_weekdays'] ?? 'YYYYYYY'),
        ];
    }
```

- [ ] **Step 4.4: Rodar e confirmar primeiro teste verde**

Run: `& $php ... vendor/bin/phpunit --filter ChildSelfMeScheduleTest`

Expected: `OK (1 test)`.

- [ ] **Step 4.5: Adicionar 2 testes restantes (bedtime + weekday)**

Adicionar à `ChildSelfMeScheduleTest.php`:

```php
    public function testMeReportsBlockedByBedtime(): void
    {
        // Note: o teste roda em "agora real" — pra ser determinístico,
        // configuramos bedtime das 00:00-23:59 (bloqueia 23h59min do dia).
        // O gap de 1min entre 23:59 e 00:00 só estoura num build no segundo errado.
        $this->wpdb->rows[1]['bedtime_enabled'] = 1;
        $this->wpdb->rows[1]['bedtime_start']   = '00:00:00';
        $this->wpdb->rows[1]['bedtime_end']     = '23:59:00';

        $data = (new ChildSelfController())->me($this->authedRequest())->get_data();

        self::assertTrue($data['schedule']['isBlocked']);
        self::assertSame('bedtime', $data['schedule']['reason']);
        self::assertNotNull($data['schedule']['unlockAt']);
    }

    public function testMeReportsBlockedByWeekday(): void
    {
        // 'NNNNNNN' = bloqueado em qualquer dia, unlockAt=null
        $this->wpdb->rows[1]['allowed_weekdays'] = 'NNNNNNN';

        $data = (new ChildSelfController())->me($this->authedRequest())->get_data();

        self::assertTrue($data['schedule']['isBlocked']);
        self::assertSame('weekday', $data['schedule']['reason']);
        self::assertNull($data['schedule']['unlockAt']);
    }
```

- [ ] **Step 4.6: Rodar 3 cases verdes**

Run: `& $php ... vendor/bin/phpunit --filter ChildSelfMeScheduleTest`

Expected: `OK (3 tests)`.

- [ ] **Step 4.7: Rodar suíte inteira**

Run: `& $php ... vendor/bin/phpunit`

Expected: `OK (119 tests)`.

- [ ] **Step 4.8: Commit**

```powershell
git add api/Controllers/ChildSelfController.php tests/Unit/Api/ChildSelfMeScheduleTest.php
git commit -m "feat(api): /child/me devolve bloco schedule (isBlocked/reason/unlockAt)"
```

---

## Task 5: Frontend types + api/children — campos novos

**Files:**
- Modify: `public/app-parent/src/api/types.ts`
- Modify: `public/app-parent/src/api/children.ts`

- [ ] **Step 5.1: Estender o tipo Child**

Editar `public/app-parent/src/api/types.ts`, substituir o tipo `Child`:

```ts
export type Weekday7 = `${'Y'|'N'}${'Y'|'N'}${'Y'|'N'}${'Y'|'N'}${'Y'|'N'}${'Y'|'N'}${'Y'|'N'}`;

export type Child = {
  id: number;
  slug: string;
  name: string;
  age: number | null;
  avatarUrl: string | null;
  device: string | null;
  status: 'online' | 'offline';
  usedMinutes: number;
  limitMinutes: number;
  bedtimeEnabled: boolean;
  bedtimeStart: string | null;
  bedtimeEnd: string | null;
  allowedWeekdays: Weekday7;
  createdAt: string | null;
  updatedAt: string | null;
};
```

- [ ] **Step 5.2: Estender UpdateChildInput em children.ts**

Editar `public/app-parent/src/api/children.ts`, substituir `UpdateChildInput`:

```ts
export type UpdateChildInput = Partial<{
  name: string;
  age: number | null;
  avatar_url: string | null;
  device: string | null;
  limit_minutes: number;
  bedtime_enabled: boolean;
  bedtime_start: string;   // 'HH:MM'
  bedtime_end: string;     // 'HH:MM'
  allowed_weekdays: string; // 7 chars Y/N, pos 0=Mon
}>;
```

- [ ] **Step 5.3: Rodar pnpm test pra ver o que quebrou**

Run:

```powershell
cd public/app-parent ; pnpm test
```

Expected: testes que fazem mock de `Child` em `pages/*.test.tsx` provavelmente quebram porque agora `bedtimeEnabled/Start/End/allowedWeekdays` são obrigatórios. Corrigir cada mock adicionando defaults: `bedtimeEnabled: false, bedtimeStart: null, bedtimeEnd: null, allowedWeekdays: 'YYYYYYY' as const`.

- [ ] **Step 5.4: Atualizar mocks de Child nos testes existentes**

Em cada arquivo de teste que constrói objetos `Child` (provavelmente `Children.test.tsx`, `Dashboard.test.tsx`, `TimeLimits.test.tsx`, `App.test.tsx`), adicionar os 4 campos novos. Por exemplo, em `pages/Children.test.tsx`, qualquer literal tipo:

```ts
const child: Child = {
  id: 1, slug: 'a', name: 'Ana', age: 8,
  avatarUrl: null, device: null, status: 'online',
  usedMinutes: 30, limitMinutes: 60,
  createdAt: null, updatedAt: null,
};
```

passa a ser:

```ts
const child: Child = {
  id: 1, slug: 'a', name: 'Ana', age: 8,
  avatarUrl: null, device: null, status: 'online',
  usedMinutes: 30, limitMinutes: 60,
  bedtimeEnabled: false, bedtimeStart: null, bedtimeEnd: null,
  allowedWeekdays: 'YYYYYYY',
  createdAt: null, updatedAt: null,
};
```

Rodar `pnpm test` até verde.

- [ ] **Step 5.5: Rodar `pnpm test` e confirmar verde**

Run: `cd public/app-parent ; pnpm test`

Expected: 158 tests passing (sem regressão).

- [ ] **Step 5.6: Commit**

```powershell
cd C:/Users/mysho/guardkids-wp
git add public/app-parent/src/api/types.ts public/app-parent/src/api/children.ts public/app-parent/src/pages/*.test.tsx public/app-parent/src/App.test.tsx
git commit -m "feat(app-parent): Child type ganha campos de schedule"
```

---

## Task 6: lib/weekdays — parser puro

**Files:**
- Create: `public/app-parent/src/lib/weekdays.ts`
- Create: `public/app-parent/src/lib/weekdays.test.ts`

- [ ] **Step 6.1: Escrever testes falhando**

Criar `public/app-parent/src/lib/weekdays.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { parseWeekdays, serializeWeekdays, WEEKDAY_IDS, type WeekDay } from './weekdays';

describe('parseWeekdays', () => {
  it('devolve todos os dias pra "YYYYYYY"', () => {
    expect(parseWeekdays('YYYYYYY')).toEqual([...WEEKDAY_IDS]);
  });

  it('devolve subconjunto seg-sex pra "YYYYYNN"', () => {
    expect(parseWeekdays('YYYYYNN')).toEqual(['mon', 'tue', 'wed', 'thu', 'fri']);
  });

  it('devolve array vazio pra "NNNNNNN"', () => {
    expect(parseWeekdays('NNNNNNN')).toEqual([]);
  });

  it('faz fallback pra todos os dias se string for inválida', () => {
    expect(parseWeekdays('lixo')).toEqual([...WEEKDAY_IDS]);
    expect(parseWeekdays('YYYY')).toEqual([...WEEKDAY_IDS]);
  });
});

describe('serializeWeekdays', () => {
  it('serializa todos os dias como "YYYYYYY"', () => {
    expect(serializeWeekdays(new Set<WeekDay>([...WEEKDAY_IDS]))).toBe('YYYYYYY');
  });

  it('serializa subconjunto seg-sex como "YYYYYNN"', () => {
    expect(serializeWeekdays(new Set<WeekDay>(['mon', 'tue', 'wed', 'thu', 'fri']))).toBe('YYYYYNN');
  });

  it('round-trip preserva o valor', () => {
    const s = 'YNYNYNY';
    expect(serializeWeekdays(new Set(parseWeekdays(s)))).toBe(s);
  });
});
```

- [ ] **Step 6.2: Rodar e confirmar falha**

Run: `cd public/app-parent ; pnpm test -- weekdays`

Expected: `Failed to resolve import './weekdays'`.

- [ ] **Step 6.3: Implementar weekdays.ts**

Criar `public/app-parent/src/lib/weekdays.ts`:

```ts
export const WEEKDAY_IDS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as const;
export type WeekDay = (typeof WEEKDAY_IDS)[number];

export function parseWeekdays(s: string): WeekDay[] {
  if (!/^[YN]{7}$/.test(s)) {
    return [...WEEKDAY_IDS];
  }
  return WEEKDAY_IDS.filter((_, i) => s[i] === 'Y');
}

export function serializeWeekdays(days: Set<WeekDay>): string {
  return WEEKDAY_IDS.map((d) => (days.has(d) ? 'Y' : 'N')).join('');
}
```

- [ ] **Step 6.4: Rodar e confirmar verde**

Run: `cd public/app-parent ; pnpm test -- weekdays`

Expected: `Tests 6 passed`.

- [ ] **Step 6.5: Commit**

```powershell
cd C:/Users/mysho/guardkids-wp
git add public/app-parent/src/lib/weekdays.ts public/app-parent/src/lib/weekdays.test.ts
git commit -m "feat(app-parent): lib/weekdays parse + serialize puros"
```

---

## Task 7: TimeLimits.tsx — BedtimeCard e WeeklyCard persistentes

**Files:**
- Modify: `public/app-parent/src/pages/TimeLimits.tsx`
- Modify: `public/app-parent/src/pages/TimeLimits.test.tsx`

- [ ] **Step 7.1: Atualizar BedtimeCard pra receber `child` e persistir**

Editar `public/app-parent/src/pages/TimeLimits.tsx`. Substituir `BedtimeCard` inteiro:

```tsx
function BedtimeCard({ child }: { child: Child }) {
  const queryClient = useQueryClient();
  const [enabled, setEnabled] = useState(child.bedtimeEnabled);
  const [start, setStart]     = useState(child.bedtimeStart ?? '21:30');
  const [end, setEnd]         = useState(child.bedtimeEnd   ?? '07:00');
  const [validation, setValidation] = useState<string | null>(null);

  // Sync local state quando a query refetcha
  useEffect(() => {
    setEnabled(child.bedtimeEnabled);
    setStart(child.bedtimeStart ?? '21:30');
    setEnd(child.bedtimeEnd ?? '07:00');
  }, [child.bedtimeEnabled, child.bedtimeStart, child.bedtimeEnd]);

  const mutation = useMutation({
    mutationFn: (patch: Parameters<typeof updateChild>[1]) => updateChild(child.id, patch),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['children'] }),
  });

  function toggleEnabled() {
    const next = !enabled;
    if (next && (start === '' || end === '')) {
      setValidation('Defina horário inicial e final antes de ativar.');
      return;
    }
    setValidation(null);
    setEnabled(next);
    mutation.mutate(next ? { bedtime_enabled: true, bedtime_start: start, bedtime_end: end } : { bedtime_enabled: false });
  }

  function commitStart(v: string) {
    setStart(v);
    if (enabled) mutation.mutate({ bedtime_start: v });
  }

  function commitEnd(v: string) {
    setEnd(v);
    if (enabled) mutation.mutate({ bedtime_end: v });
  }

  return (
    <article className="glass-panel rounded-2xl p-6 shadow-ambient">
      <header className="mb-4 flex items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-surface-container-highest text-primary">
            <Icon name="bedtime" className="text-2xl" filled />
          </div>
          <div>
            <h3 className="font-display text-headline-md text-on-surface">Modo dormir</h3>
            <p className="text-label-sm text-on-surface-variant">
              Bloqueia o app durante a noite.
            </p>
          </div>
        </div>
        <Toggle on={enabled} onToggle={toggleEnabled} />
      </header>

      <div className={`grid grid-cols-2 gap-3 ${enabled ? '' : 'opacity-40'}`}>
        <TimeInput label="Começa às" value={start} onChange={commitStart} icon="dark_mode" />
        <TimeInput label="Termina às" value={end} onChange={commitEnd} icon="wb_sunny" />
      </div>

      {validation && (
        <p role="alert" className="mt-3 rounded-lg bg-error/10 p-2 text-label-sm text-error">
          {validation}
        </p>
      )}
      {mutation.error ? (
        <p role="alert" className="mt-3 rounded-lg bg-error/10 p-2 text-label-sm text-error">
          Falha ao salvar mudanças do modo dormir.
        </p>
      ) : null}
    </article>
  );
}
```

E na função `TimeLimits`, mudar `<BedtimeCard />` pra `<BedtimeCard child={selected} />`.

- [ ] **Step 7.2: Atualizar WeeklyCard pra receber `child` e persistir**

Substituir `WeeklyCard`:

```tsx
function WeeklyCard({ child }: { child: Child }) {
  const queryClient = useQueryClient();
  const [enabled, setEnabled] = useState<Set<WeekDay>>(
    () => new Set(parseWeekdays(child.allowedWeekdays)),
  );

  useEffect(() => {
    setEnabled(new Set(parseWeekdays(child.allowedWeekdays)));
  }, [child.allowedWeekdays]);

  const mutation = useMutation({
    mutationFn: (allowed_weekdays: string) => updateChild(child.id, { allowed_weekdays }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['children'] }),
  });

  const toggle = (d: WeekDay) => {
    const next = new Set(enabled);
    if (next.has(d)) next.delete(d);
    else next.add(d);
    setEnabled(next);
    mutation.mutate(serializeWeekdays(next));
  };

  return (
    <article className="glass-panel rounded-2xl p-6 shadow-ambient">
      <header className="mb-4 flex items-center gap-3">
        <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-secondary-container/60 text-secondary">
          <Icon name="event_repeat" className="text-2xl" filled />
        </div>
        <div>
          <h3 className="font-display text-headline-md text-on-surface">Dias permitidos</h3>
          <p className="text-label-sm text-on-surface-variant">
            Marca os dias da semana em que o app fica liberado.
          </p>
        </div>
      </header>

      <div className="grid grid-cols-7 gap-2">
        {WEEK_DAYS.map((d) => {
          const active = enabled.has(d.id);
          return (
            <button
              key={d.id}
              type="button"
              onClick={() => toggle(d.id)}
              className={
                active
                  ? 'flex flex-col items-center justify-center gap-1 rounded-xl bg-primary py-3 text-white shadow-sm'
                  : 'flex flex-col items-center justify-center gap-1 rounded-xl border border-outline-variant bg-surface-container-low py-3 text-on-surface-variant hover:bg-surface-variant'
              }
            >
              <span className="text-label-md font-bold">{d.label}</span>
              <Icon
                name={active ? 'check_circle' : 'block'}
                className={`text-sm ${active ? 'text-white' : 'text-on-surface-variant'}`}
                filled
              />
            </button>
          );
        })}
      </div>
    </article>
  );
}
```

E mudar `<WeeklyCard />` pra `<WeeklyCard child={selected} />` na função `TimeLimits`.

- [ ] **Step 7.3: Adicionar imports e remover ComingSoonBadge dos persistentes**

No topo de `TimeLimits.tsx`, garantir:

```tsx
import { parseWeekdays, serializeWeekdays, type WeekDay } from '../lib/weekdays';
```

E renomear o tipo local `WeekDay` (linha 20) pra evitar conflito — remover a definição local `type WeekDay = ...` e importar do `lib/weekdays.ts`. Ajustar `WEEK_DAYS` pra usar `WeekDay` do lib (mesmos valores).

`TimelineCard` mantém o `<ComingSoonBadge />`; só ajusta o subtitle:

```tsx
<p className="text-label-sm text-on-surface-variant">
  Em construção — virá quando tivermos timeline de uso por hora.
</p>
```

- [ ] **Step 7.4: Atualizar testes de TimeLimits**

Editar `public/app-parent/src/pages/TimeLimits.test.tsx`. Garantir que os mocks de `Child` incluem `bedtimeEnabled`, `bedtimeStart`, `bedtimeEnd`, `allowedWeekdays` (Task 5 já cuidou). Adicionar 5 cases novos no final do `describe`:

```ts
  it('exibe valores de bedtime vindos da API', async () => {
    listChildrenMock.mockResolvedValue([{
      id: 1, slug: 'a', name: 'Ana', age: 8, avatarUrl: null, device: null,
      status: 'online', usedMinutes: 0, limitMinutes: 60,
      bedtimeEnabled: true, bedtimeStart: '21:30', bedtimeEnd: '07:00',
      allowedWeekdays: 'YYYYYYY', createdAt: null, updatedAt: null,
    }] satisfies Child[]);
    renderPage();
    expect(await screen.findByDisplayValue('21:30')).toBeInTheDocument();
    expect(screen.getByDisplayValue('07:00')).toBeInTheDocument();
  });

  it('toggle bedtime dispara updateChild com bedtime_enabled', async () => {
    listChildrenMock.mockResolvedValue([{
      id: 1, slug: 'a', name: 'Ana', age: 8, avatarUrl: null, device: null,
      status: 'online', usedMinutes: 0, limitMinutes: 60,
      bedtimeEnabled: false, bedtimeStart: '21:30', bedtimeEnd: '07:00',
      allowedWeekdays: 'YYYYYYY', createdAt: null, updatedAt: null,
    }] satisfies Child[]);
    renderPage();
    await screen.findByRole('switch');
    await userEvent.click(screen.getByRole('switch'));
    await waitFor(() => expect(updateChildMock).toHaveBeenCalledWith(1, expect.objectContaining({ bedtime_enabled: true })));
  });

  it('toggle ON sem start/end mostra erro inline e não chama updateChild', async () => {
    listChildrenMock.mockResolvedValue([{
      id: 1, slug: 'a', name: 'Ana', age: 8, avatarUrl: null, device: null,
      status: 'online', usedMinutes: 0, limitMinutes: 60,
      bedtimeEnabled: false, bedtimeStart: null, bedtimeEnd: null,
      allowedWeekdays: 'YYYYYYY', createdAt: null, updatedAt: null,
    }] satisfies Child[]);
    renderPage();
    await screen.findByRole('switch');
    // Apaga os inputs pra ficar vazio
    const startInput = screen.getByLabelText(/Começa às/);
    await userEvent.clear(startInput);
    await userEvent.click(screen.getByRole('switch'));
    expect(screen.getByRole('alert')).toHaveTextContent(/Defina horário/i);
    expect(updateChildMock).not.toHaveBeenCalledWith(1, expect.objectContaining({ bedtime_enabled: true }));
  });

  it('clicar num dia da semana dispara updateChild com allowed_weekdays', async () => {
    listChildrenMock.mockResolvedValue([{
      id: 1, slug: 'a', name: 'Ana', age: 8, avatarUrl: null, device: null,
      status: 'online', usedMinutes: 0, limitMinutes: 60,
      bedtimeEnabled: false, bedtimeStart: null, bedtimeEnd: null,
      allowedWeekdays: 'YYYYYYY', createdAt: null, updatedAt: null,
    }] satisfies Child[]);
    renderPage();
    const sat = await screen.findByRole('button', { name: /Sáb/ });
    await userEvent.click(sat);
    await waitFor(() => expect(updateChildMock).toHaveBeenCalledWith(1, { allowed_weekdays: 'YYYYYNY' }));
  });

  it('não renderiza ComingSoonBadge nos cards de bedtime e weekday', async () => {
    listChildrenMock.mockResolvedValue([{
      id: 1, slug: 'a', name: 'Ana', age: 8, avatarUrl: null, device: null,
      status: 'online', usedMinutes: 0, limitMinutes: 60,
      bedtimeEnabled: false, bedtimeStart: null, bedtimeEnd: null,
      allowedWeekdays: 'YYYYYYY', createdAt: null, updatedAt: null,
    }] satisfies Child[]);
    renderPage();
    await screen.findByText(/Modo dormir/);
    // O texto "Em breve" só pode aparecer 1x (no TimelineCard)
    expect(screen.getAllByText(/Em breve/)).toHaveLength(1);
  });
```

- [ ] **Step 7.5: Rodar pnpm test e confirmar verde**

Run: `cd public/app-parent ; pnpm test`

Expected: 163 testes verdes (158 baseline + 5 novos).

- [ ] **Step 7.6: Commit**

```powershell
cd C:/Users/mysho/guardkids-wp
git add public/app-parent/src/pages/TimeLimits.tsx public/app-parent/src/pages/TimeLimits.test.tsx
git commit -m "feat(app-parent): BedtimeCard e WeeklyCard persistem via updateChild"
```

---

## Task 8: Child — infra de testes React (jsdom + Testing Library)

**Files:**
- Modify: `public/app-child/package.json`
- Modify: `public/app-child/vitest.config.ts`
- Create: `public/app-child/src/test/setup.ts`

- [ ] **Step 8.1: Instalar deps de teste no app-child**

Run:

```powershell
cd public/app-child
pnpm add -D @testing-library/react@^16.3.2 @testing-library/jest-dom@^6.9.1 @testing-library/user-event@^14.6.1 jsdom@^29.1.1
```

Expected: `package.json` atualizado, `pnpm-lock.yaml` recompilado.

- [ ] **Step 8.2: Atualizar vitest.config.ts**

Substituir `public/app-child/vitest.config.ts`:

```ts
/// <reference types="vitest" />
import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    globals: false,
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.test.{ts,tsx}'],
    exclude: ['e2e/**', 'node_modules/**', 'dist/**'],
  },
});
```

- [ ] **Step 8.3: Criar setup.ts**

Criar `public/app-child/src/test/setup.ts`:

```ts
import '@testing-library/jest-dom/vitest';
import { afterEach } from 'vitest';
import { cleanup } from '@testing-library/react';

afterEach(() => {
  cleanup();
});
```

- [ ] **Step 8.4: Rodar pnpm test pra confirmar suíte atual ainda passa**

Run: `cd public/app-child ; pnpm test`

Expected: `Tests 9 passed` (`usageTracker.test.ts` continua verde).

- [ ] **Step 8.5: Commit**

```powershell
cd C:/Users/mysho/guardkids-wp
git add public/app-child/package.json public/app-child/pnpm-lock.yaml public/app-child/vitest.config.ts public/app-child/src/test/setup.ts
git commit -m "test(app-child): adiciona infra Testing Library + jsdom"
```

---

## Task 9: Child — api/me + tipos

**Files:**
- Create: `public/app-child/src/api/me.ts`

- [ ] **Step 9.1: Criar fetchMe e tipos**

Criar `public/app-child/src/api/me.ts`:

```ts
export type ChildScheduleState = {
  isBlocked: boolean;
  reason: 'bedtime' | 'weekday' | null;
  unlockAt: string | null;
};

export type ChildMe = {
  id: number;
  slug: string;
  name: string;
  age: number | null;
  avatarUrl: string | null;
  device: string | null;
  status: 'online' | 'offline';
  usedMinutes: number;
  limitMinutes: number;
  bedtimeEnabled: boolean;
  bedtimeStart: string | null;
  bedtimeEnd: string | null;
  allowedWeekdays: string;
  schedule: ChildScheduleState;
};

const REST_BASE = '/wp-json/guardkids/v1';

export async function fetchMe(token: string): Promise<ChildMe> {
  const res = await fetch(`${REST_BASE}/child/me`, {
    headers: { 'X-GuardKids-Token': token, Accept: 'application/json' },
  });
  if (!res.ok) {
    throw new Error(`fetchMe failed: ${res.status}`);
  }
  return (await res.json()) as ChildMe;
}
```

- [ ] **Step 9.2: Commit**

```powershell
git add public/app-child/src/api/me.ts
git commit -m "feat(app-child): api/me + tipo ChildMe com schedule"
```

---

## Task 10: Child — Blocked.tsx refatorado pra props reais

**Files:**
- Modify: `public/app-child/src/pages/Blocked.tsx`
- Create: `public/app-child/src/pages/Blocked.test.tsx`

- [ ] **Step 10.1: Escrever 5 testes falhando**

Criar `public/app-child/src/pages/Blocked.test.tsx`:

```tsx
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { Blocked } from './Blocked';

describe('Blocked', () => {
  it('mostra "Soneca" quando reason=bedtime', () => {
    render(<Blocked reason="bedtime" unlockAt="2099-01-01T00:00:00Z" onNavigate={vi.fn()} />);
    expect(screen.getByText(/Soneca/)).toBeInTheDocument();
  });

  it('mostra "Dia bloqueado" quando reason=weekday', () => {
    render(<Blocked reason="weekday" unlockAt="2099-01-01T00:00:00Z" onNavigate={vi.fn()} />);
    expect(screen.getByText(/Dia bloqueado/)).toBeInTheDocument();
  });

  it('mostra "—" quando unlockAt é null', () => {
    render(<Blocked reason="weekday" unlockAt={null} onNavigate={vi.fn()} />);
    expect(screen.getByText('—')).toBeInTheDocument();
  });

  it('chama onNavigate("requests") ao clicar em "Pedir mais tempo"', async () => {
    const onNavigate = vi.fn();
    render(<Blocked reason="bedtime" unlockAt="2099-01-01T00:00:00Z" onNavigate={onNavigate} />);
    await userEvent.click(screen.getByRole('button', { name: /Pedir mais tempo/i }));
    expect(onNavigate).toHaveBeenCalledWith('requests');
  });

  it('renderiza um contador formatado quando unlockAt é futuro', () => {
    // unlockAt = agora + 1h, esperamos algo tipo "01:00:00" ou menor no display
    const unlock = new Date(Date.now() + 3600 * 1000).toISOString();
    render(<Blocked reason="bedtime" unlockAt={unlock} onNavigate={vi.fn()} />);
    // Aceita qualquer "HH:MM:SS"
    expect(screen.getByText(/^\d{2}:\d{2}:\d{2}$/)).toBeInTheDocument();
  });
});
```

- [ ] **Step 10.2: Rodar e confirmar falha**

Run: `cd public/app-child ; pnpm test -- Blocked`

Expected: erros de TS/runtime — `Blocked` ainda usa `import { blockedInfo } from '../data/mockData'` e não aceita as novas props.

- [ ] **Step 10.3: Reescrever Blocked.tsx com props reais**

Substituir `public/app-child/src/pages/Blocked.tsx`:

```tsx
import { useEffect, useState } from 'react';
import { Icon } from '../components/Icon';
import type { PageId } from '../data/mockData';

type Reason = 'bedtime' | 'weekday';

type BlockedProps = {
  reason: Reason;
  unlockAt: string | null;
  onNavigate: (page: PageId) => void;
};

const REASON_LABEL: Record<Reason, string> = {
  bedtime: 'Soneca',
  weekday: 'Dia bloqueado',
};

function formatHMS(sec: number): string {
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = sec % 60;
  return [h, m, s].map((n) => String(n).padStart(2, '0')).join(':');
}

export function Blocked({ reason, unlockAt, onNavigate }: BlockedProps) {
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    const id = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(id);
  }, []);

  const unlockMs = unlockAt ? Date.parse(unlockAt) : null;
  const remainingSec = unlockMs !== null && !Number.isNaN(unlockMs)
    ? Math.max(0, Math.floor((unlockMs - now) / 1000))
    : null;

  return (
    <main className="flex min-h-screen flex-1 flex-col items-center bg-gradient-to-b from-primary to-primary-container px-container-padding-mobile pb-24 pt-stack-lg text-white">
      <div className="flex w-full justify-end">
        <button
          type="button"
          onClick={() => onNavigate('home')}
          aria-label="Voltar"
          className="rounded-full p-2 text-white/80 hover:bg-white/10"
        >
          <Icon name="close" />
        </button>
      </div>

      <div className="mt-6 flex flex-col items-center text-center">
        <div className="relative flex h-32 w-32 items-center justify-center rounded-full bg-white/10 ring-8 ring-white/5">
          <span
            className="material-symbols-outlined text-white"
            style={{ fontSize: 72, fontVariationSettings: "'FILL' 1" }}
          >
            bedtime
          </span>
        </div>

        <span className="mt-6 inline-flex items-center gap-2 rounded-full bg-tertiary-fixed-dim/25 px-3 py-1 text-label-sm font-bold text-tertiary-fixed">
          <Icon name="lock" className="text-sm" filled />
          Modo {REASON_LABEL[reason]}
        </span>

        <h1 className="mt-3 font-display text-headline-lg text-white">
          Sem tempo de tela agora
        </h1>
        <p className="mt-2 max-w-sm text-body-md text-white/85">
          Aproveita pra brincar fora, ler um livro ou descansar 💙
        </p>
      </div>

      <section className="mt-8 w-full max-w-sm">
        <p className="text-center text-label-sm uppercase tracking-wider text-white/70">
          {remainingSec === null ? 'Sem horário liberado configurado' : 'Libera em'}
        </p>
        <div className="mt-2 flex justify-center">
          <div className="glass-panel rounded-2xl px-6 py-4 text-center text-primary shadow-ambient">
            <span className="font-display text-display-lg font-bold leading-none tabular-nums">
              {remainingSec === null ? '—' : formatHMS(remainingSec)}
            </span>
          </div>
        </div>
      </section>

      <button
        type="button"
        onClick={() => onNavigate('requests')}
        className="mt-8 inline-flex w-full max-w-sm items-center justify-center gap-2 rounded-xl bg-orange-warm py-3 text-label-md font-bold text-white shadow-sm transition-colors hover:bg-orange-warm/90"
      >
        <Icon name="more_time" className="text-sm" filled />
        Pedir mais tempo pros pais
      </button>

      <p className="mt-3 text-center text-label-sm text-white/60">
        Eles vão receber uma notificação na hora.
      </p>
    </main>
  );
}
```

- [ ] **Step 10.4: Rodar e confirmar 5 verdes**

Run: `cd public/app-child ; pnpm test -- Blocked`

Expected: `Tests 5 passed`.

- [ ] **Step 10.5: Atualizar o caller em App.tsx pra não passar mais sem props (compile-check)**

Por enquanto (Task 11 reescreve App.tsx), só remover o uso atual. Editar `public/app-child/src/App.tsx`, **remover** o bloco `if (activePage === 'blocked')` (linhas 42-48) — vai ser substituído na próxima task pelo polling. Garantir que `pnpm build` ainda passa:

Run: `cd public/app-child ; pnpm build`

Expected: build verde. Se o TypeScript reclamar de import não usado de `Blocked`, manter o import (Task 11 vai usá-lo).

- [ ] **Step 10.6: Commit**

```powershell
cd C:/Users/mysho/guardkids-wp
git add public/app-child/src/pages/Blocked.tsx public/app-child/src/pages/Blocked.test.tsx public/app-child/src/App.tsx
git commit -m "feat(app-child): Blocked aceita props reason+unlockAt, sem mock"
```

---

## Task 11: Child — App.tsx polling + enforcement automático

**Files:**
- Modify: `public/app-child/src/App.tsx`
- Modify: `public/app-child/src/main.tsx`
- Create: `public/app-child/src/App.test.tsx`

- [ ] **Step 11.1: Garantir QueryClientProvider em main.tsx**

Editar `public/app-child/src/main.tsx`. Substituir:

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';
import './index.css';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { refetchOnWindowFocus: true, staleTime: 30_000 },
  },
});

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <QueryClientProvider client={queryClient}>
      <App />
    </QueryClientProvider>
  </React.StrictMode>,
);
```

- [ ] **Step 11.2: Escrever 4 testes falhando pra App**

Criar `public/app-child/src/App.test.tsx`:

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// Mock localStorage pra simular criança já pareada
beforeEach(() => {
  vi.stubGlobal('localStorage', {
    getItem: vi.fn(() => 'fake-token'),
    setItem: vi.fn(),
    removeItem: vi.fn(),
  });
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

const { fetchMeMock } = vi.hoisted(() => ({ fetchMeMock: vi.fn() }));
vi.mock('./api/me', () => ({ fetchMe: fetchMeMock }));

import App from './App';

function renderApp() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  return render(
    <QueryClientProvider client={client}>
      <App />
    </QueryClientProvider>,
  );
}

const baseChild = {
  id: 1, slug: 'lucas', name: 'Lucas', age: 8,
  avatarUrl: null, device: null, status: 'online' as const,
  usedMinutes: 0, limitMinutes: 60,
  bedtimeEnabled: false, bedtimeStart: null, bedtimeEnd: null,
  allowedWeekdays: 'YYYYYYY',
};

describe('App (child) — enforcement', () => {
  it('renderiza Home quando schedule.isBlocked é false', async () => {
    fetchMeMock.mockResolvedValue({
      ...baseChild,
      schedule: { isBlocked: false, reason: null, unlockAt: null },
    });
    renderApp();
    expect(await screen.findByText(/Olá/i)).toBeInTheDocument();
  });

  it('renderiza Blocked quando schedule.isBlocked é true (bedtime)', async () => {
    fetchMeMock.mockResolvedValue({
      ...baseChild,
      schedule: { isBlocked: true, reason: 'bedtime', unlockAt: '2099-01-01T00:00:00Z' },
    });
    renderApp();
    expect(await screen.findByText(/Modo Soneca/)).toBeInTheDocument();
  });

  it('renderiza Blocked quando schedule.isBlocked é true (weekday)', async () => {
    fetchMeMock.mockResolvedValue({
      ...baseChild,
      schedule: { isBlocked: true, reason: 'weekday', unlockAt: null },
    });
    renderApp();
    expect(await screen.findByText(/Modo Dia bloqueado/)).toBeInTheDocument();
  });

  it('fail-open: erro de rede no /me não bloqueia a tela', async () => {
    fetchMeMock.mockRejectedValue(new Error('network down'));
    renderApp();
    await waitFor(() => expect(fetchMeMock).toHaveBeenCalled());
    expect(screen.queryByText(/Modo Soneca/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Modo Dia bloqueado/)).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 11.3: Rodar e confirmar falhas (App ainda não usa fetchMe)**

Run: `cd public/app-child ; pnpm test -- App`

Expected: testes falhando (App não dá mount em Blocked baseado em schedule).

- [ ] **Step 11.4: Reescrever App.tsx pra usar useQuery + Blocked condicional**

Substituir `public/app-child/src/App.tsx`:

```tsx
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { fetchMe } from './api/me';
import { getStoredToken, setStoredToken } from './api/token';
import { BottomNav } from './components/BottomNav';
import { Header } from './components/Header';
import { createUsageTracker, setActiveTracker, type UsageTracker } from './lib/usageTracker';
import { Alerts } from './pages/Alerts';
import { Blocked } from './pages/Blocked';
import { Browser } from './pages/Browser';
import { Home } from './pages/Home';
import { PairScreen } from './pages/PairScreen';
import { Requests } from './pages/Requests';
import type { PageId } from './data/mockData';

let trackerSingleton: UsageTracker | null = null;

export default function App() {
  const [token, setToken] = useState<string | null>(() => getStoredToken());
  const [activePage, setActivePage] = useState<PageId>('home');

  useEffect(() => {
    if (!token) return;
    if (!trackerSingleton) trackerSingleton = createUsageTracker();
    trackerSingleton.start();
    setActiveTracker(trackerSingleton);
    return () => {
      trackerSingleton?.stop();
      setActiveTracker(null);
    };
  }, [token]);

  const meQuery = useQuery({
    queryKey: ['me'],
    queryFn: () => fetchMe(token!),
    enabled: !!token,
    refetchInterval: 60_000,
    refetchOnWindowFocus: true,
    staleTime: 30_000,
  });

  if (!token) {
    return (
      <PairScreen
        onPaired={(t) => {
          setStoredToken(t);
          setToken(t);
        }}
      />
    );
  }

  const schedule = meQuery.data?.schedule;
  if (schedule?.isBlocked && schedule.reason) {
    return (
      <div className="min-h-screen overflow-x-hidden bg-surface text-on-surface">
        <Blocked
          reason={schedule.reason}
          unlockAt={schedule.unlockAt}
          onNavigate={setActivePage}
        />
      </div>
    );
  }

  return (
    <div className="flex min-h-screen flex-col overflow-x-hidden bg-surface pb-24 text-on-surface">
      <Header activePage={activePage} onNavigate={setActivePage} />
      <PageRenderer page={activePage} onNavigate={setActivePage} />
      <BottomNav activePage={activePage} onNavigate={setActivePage} />
    </div>
  );
}

function PageRenderer({
  page,
  onNavigate,
}: {
  page: PageId;
  onNavigate: (page: PageId) => void;
}) {
  switch (page) {
    case 'browser':
      return <Browser />;
    case 'requests':
      return <Requests />;
    case 'alerts':
      return <Alerts />;
    case 'home':
    default:
      return <Home onNavigate={onNavigate} />;
  }
}
```

- [ ] **Step 11.5: Rodar 4 testes verdes**

Run: `cd public/app-child ; pnpm test -- App`

Expected: `Tests 4 passed`.

- [ ] **Step 11.6: Rodar suíte inteira do child**

Run: `cd public/app-child ; pnpm test`

Expected: `Tests 18 passed` (9 baseline + 5 Blocked + 4 App).

- [ ] **Step 11.7: Build pra garantir TypeScript não regrediu**

Run: `cd public/app-child ; pnpm build`

Expected: build verde.

- [ ] **Step 11.8: Commit**

```powershell
cd C:/Users/mysho/guardkids-wp
git add public/app-child/src/App.tsx public/app-child/src/main.tsx public/app-child/src/App.test.tsx
git commit -m "feat(app-child): App polla /me e força Blocked quando isBlocked"
```

---

## Task 12: README — atualizar contadores de teste

**Files:**
- Modify: `README.md`

- [ ] **Step 12.1: Atualizar badge e descrições**

Editar `README.md`:

1. Substituir badge `tests-264%20passing` por `tests-309%20passing` (linha do badge `Tests`).
2. Substituir `**PHPUnit (97 tests, 243 assertions):**` por `**PHPUnit (122 tests, ≥320 assertions):**` (Fase 8 adiciona 25 PHPUnit: 1 migration + 13 evaluator + 6 controller PATCH + 3 controller /me, mas faltam ainda asserções extras; conferir o output real e ajustar o número exato).
3. Substituir `**Vitest app-parent (158 tests) + app-child (9 tests):**` por `**Vitest app-parent (164 tests) + app-child (23 tests):**` (parent: 158 + 6 weekdays = ~163 mais 5 TimeLimits ≈ 168; child: 9 + 5 Blocked + 4 App = 18; conferir e ajustar).
4. Estender cobertura listada com Schedule/ScheduleEvaluator e enforcement.

(Os números exatos saem do output real de `phpunit` e `pnpm test` — substituir pelos números medidos.)

- [ ] **Step 12.2: Rodar PHPUnit + ambos Vitests pra colher números reais**

Run em paralelo:

```powershell
& $php ... vendor/bin/phpunit 2>&1 | Select-String "tests,"
cd public/app-parent ; pnpm test 2>&1 | Select-String "passed"
cd public/app-child ; pnpm test 2>&1 | Select-String "passed"
```

Anotar os 3 totais e substituir no `README.md` (e somar pra atualizar o badge).

- [ ] **Step 12.3: Commit**

```powershell
cd C:/Users/mysho/guardkids-wp
git add README.md
git commit -m "docs(readme): atualiza contadores de testes pós-Fase 8"
```

---

## Task 13: Smoke manual no LocalWP

Sem código novo — verificação fim-a-fim antes de marcar a Fase 8 como pronta.

- [ ] **Step 13.1: Build dos dois apps**

Run:

```powershell
cd public/app-parent ; pnpm build
cd ../app-child       ; pnpm build
```

Expected: 2 builds verdes.

- [ ] **Step 13.2: Subir o site no LocalWP e abrir /painel-pais**

Abrir [https://guardkids-wp.local/painel-pais](https://guardkids-wp.local/painel-pais) (logado como admin). Verificar:

- TimeLimits abre, com Bedtime e Dias permitidos sem `<ComingSoonBadge />` nos respectivos.
- Ativar Bedtime com janela 00:00–23:59 (pra forçar bloqueio agora). Toggle salva.
- Marcar todos os dias da semana como permitidos (default).

- [ ] **Step 13.3: Parear um device e abrir o app-child**

- Em Children → "Parear dispositivo". Copia o token.
- Abre [https://guardkids-wp.local/painel-filho](https://guardkids-wp.local/painel-filho) em outra aba/private. Cola o token.
- Verificar que `<Blocked />` aparece em ≤60s (bedtime ativo agora). Contador conta regressivo até `unlockAt`.

- [ ] **Step 13.4: Desativar Bedtime no parent, voltar ao child**

- No parent, toggle Bedtime OFF. Salva.
- Trazer foco ao child (alt-tab ou refresh). Em ≤60s, tela volta pra Home normal.

- [ ] **Step 13.5: Testar weekday: desmarcar todos os dias, conferir bloqueio**

- Em Dias permitidos, clicar em todos os 7 dias pra deixar `'NNNNNNN'`. Salva.
- Child: tela deve voltar pra `<Blocked />` com label "Dia bloqueado" e contador "—".
- Reativar todos os dias antes de fechar.

- [ ] **Step 13.6: Push pra origin/master após validação**

```powershell
git push origin master
```

Expected: 13+ commits novos pushed. CI deve passar (phpunit + 2 builds + vitest x2).

---

## Self-Review

Reli o spec contra o plano:

- **Seção 3 (Schema)** → Task 1 ✓
- **Seção 4 (ScheduleEvaluator)** → Task 2 ✓ (13 testes cobrem todos os cenários listados na seção 4 do spec)
- **Seção 5.1 (PATCH ampliado)** → Task 3 ✓
- **Seção 5.2 (/child/me)** → Task 4 ✓
- **Seção 5.3 (sem mudança em /events e /requests)** → garantido por omissão (não alteramos esses controllers) ✓
- **Seção 6 (UI parent)** → Tasks 5, 6, 7 ✓
- **Seção 7.1 (api/me)** → Task 9 ✓
- **Seção 7.2 (App.tsx polling fail-open)** → Task 11 ✓ (incluindo teste de fail-open)
- **Seção 7.3 (Blocked.tsx props)** → Task 10 ✓
- **Seção 8 (plano de testes)** → distribuído ao longo das tasks ✓
- **Seção 9 (critérios de sucesso)** → Task 13 (smoke manual) cobre 4-9; testes cobrem 1-3 e 7-8 ✓
- **Seção 10 (out of scope)** → respeitado: TimelineCard mantém badge, daily limit não bloqueia ✓

**Naming consistency:** `bedtime_enabled/start/end`, `allowed_weekdays` (snake_case backend) ↔ `bedtimeEnabled/Start/End`, `allowedWeekdays` (camelCase frontend) — consistente entre tasks.

**ChildPresenter extraído:** mencionado no spec (seção 5.1) como "duplicado hoje, extrair pra ChildPresenter" — **não fiz** porque é refactor cosmético, não bloqueia funcionalidade. Adicionei a duplicação propositalmente em ambos `ChildController::toJson` e `ChildSelfController::childToJson`. Decisão consciente: extrair depois se virar incômodo, evita tocar 2 controllers em 1 task.

Plano completo, 13 tasks, ~45 testes novos, ~13 commits.
