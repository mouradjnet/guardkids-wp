# Gamificação 3a (economia/progressão) — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fundação da gamificação: cada conteúdo aberto na Biblioteca rende XP/GuardCoins (anti-farm por dia), sobe nível (1-100), mantém streak, e os 2 painéis mostram o progresso.

**Architecture:** Migração 018 (tabelas `progression` + `progression_awards`). `LevelCurve` puro, `ProgressionRepository`/`AwardRepository`, serviço `Progression` (hookado no `childHistory` — aditivo). `GamificationController` (endpoints token/admin). Frontend: `ProgressCard` na Home (filho) + aba `GamificationDashboard` (pais).

**Tech Stack:** PHP 8.2 (`$wpdb`), PHPUnit 9.6. React 19 + TS + Vitest 2 + TanStack Query 5.

**Spec:** `docs/superpowers/specs/2026-07-02-gamificacao-sprint3a-design.md`

**Ambiente:** branch `feat/gamificacao-sprint3a`. PHPUnit (OPENSSL_CONF só p/ testes com `ChildAuth::issueToken`):
```bash
PHP="/c/Users/mysho/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe"
EXT="$("$PHP" -r 'echo dirname(PHP_BINARY);')/ext"
export OPENSSL_CONF="$HOME/AppData/Local/Temp/claude/C--Users-mysho/4284f3b2-f47a-4fc8-8281-cdd4e7efe450/scratchpad/openssl.cnf"
RUN(){ "$PHP" -d extension_dir="$EXT" -d extension=openssl -d extension=mbstring -d extension=sodium \
  vendor/bin/phpunit -c phpunit.xml.dist --testsuite unit "$@"; }
```

---

## File Structure

**Backend novo:** `database/migrations/018_progression.php`; `includes/Progression/{LevelCurve,Progression}.php`; `database/{ProgressionRepository,AwardRepository}.php`; `api/Controllers/GamificationController.php`.
**Backend modificado:** `guardkids.php` (DB v18), `uninstall.php`, `api/RestApi.php`, `api/Controllers/ContentController.php` (hook).
**app-child:** `src/api/gamification.ts`, `src/components/ProgressCard.tsx`, `src/pages/Home.tsx` (add card).
**app-parent:** `src/api/gamification.ts`, `src/data/mockData.ts` (PageId+nav), `src/App.tsx`, `src/pages/GamificationDashboard.tsx`.

---

## Task 1: Migração 018 + DB v18 + uninstall

**Files:** Create `database/migrations/018_progression.php`; Modify `guardkids.php`, `uninstall.php`.

- [ ] **Step 1: Criar a migração**

`database/migrations/018_progression.php`:
```php
<?php

declare(strict_types=1);

/**
 * Migration 018 — economia/progressão (gamificação 3a).
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $p = $wpdb->prefix . 'guardkids_';

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}progression (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        child_id BIGINT UNSIGNED NOT NULL,
        xp INT NOT NULL DEFAULT 0,
        coins INT NOT NULL DEFAULT 0,
        streak_days INT NOT NULL DEFAULT 0,
        last_activity_date DATE NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY child_unq (child_id)
    ) {$charsetCollate};");

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}progression_awards (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        child_id BIGINT UNSIGNED NOT NULL,
        content_id BIGINT UNSIGNED NOT NULL,
        award_date DATE NOT NULL,
        xp INT NOT NULL DEFAULT 0,
        coins INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY once_per_day (child_id, content_id, award_date),
        KEY child (child_id)
    ) {$charsetCollate};");
};
```

- [ ] **Step 2: Bump DB version** — `guardkids.php`: `define('GUARDKIDS_DB_VERSION', 17);` → `18`.

- [ ] **Step 3: uninstall** — em `uninstall.php`, adicionar ao array `$tables`:
```php
    $wpdb->prefix . 'guardkids_progression',
    $wpdb->prefix . 'guardkids_progression_awards',
```

- [ ] **Step 4: Rodar MigrationRunnerTest** — `RUN --filter MigrationRunnerTest` → PASS (Windows pode falso-falhar por glob; CI valida).

- [ ] **Step 5: Commit**
```bash
git add database/migrations/018_progression.php guardkids.php uninstall.php
git commit -m "feat(db): migração 018 — progression + awards + DB v18"
```

---

## Task 2: LevelCurve (puro)

**Files:** Create `includes/Progression/LevelCurve.php`; Test `tests/Unit/Progression/LevelCurveTest.php`.

- [ ] **Step 1: Teste que falha**

`tests/Unit/Progression/LevelCurveTest.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Progression;

use GuardKids\Progression\LevelCurve;
use PHPUnit\Framework\TestCase;

final class LevelCurveTest extends TestCase
{
    public function testLevelForXpKeyPoints(): void
    {
        self::assertSame(1, LevelCurve::levelForXp(0));
        self::assertSame(1, LevelCurve::levelForXp(99));
        self::assertSame(2, LevelCurve::levelForXp(100));
        self::assertSame(3, LevelCurve::levelForXp(300));
        self::assertSame(10, LevelCurve::levelForXp(4500));
        self::assertSame(100, LevelCurve::levelForXp(495000));
        self::assertSame(100, LevelCurve::levelForXp(999999999));
    }

    public function testProgressInLevel(): void
    {
        $p = LevelCurve::progressInLevel(150);
        self::assertSame(2, $p['level']);
        self::assertSame(50, $p['xpIntoLevel']);   // 150 - 100
        self::assertSame(200, $p['xpForNextLevel']); // 100 * 2

        $max = LevelCurve::progressInLevel(495000);
        self::assertSame(100, $max['level']);
        self::assertSame(0, $max['xpForNextLevel']);
    }
}
```

- [ ] **Step 2: Rodar e falhar** — `RUN --filter LevelCurveTest` → FAIL.

- [ ] **Step 3: Implementar**

`includes/Progression/LevelCurve.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Progression;

/**
 * Curva de nível 1..100 (pura). Subir de L→L+1 custa 100*L XP; total pra
 * atingir o nível L é 50*L*(L-1).
 */
final class LevelCurve
{
    private const MAX = 100;

    public static function totalToReach(int $level): int
    {
        return 50 * $level * ($level - 1);
    }

    public static function levelForXp(int $xp): int
    {
        if ($xp <= 0) {
            return 1;
        }
        for ($l = self::MAX; $l >= 1; $l--) {
            if ($xp >= self::totalToReach($l)) {
                return $l;
            }
        }
        return 1;
    }

    /**
     * @return array{level:int, xpIntoLevel:int, xpForNextLevel:int}
     */
    public static function progressInLevel(int $xp): array
    {
        $level = self::levelForXp($xp);
        return [
            'level'          => $level,
            'xpIntoLevel'    => $xp - self::totalToReach($level),
            'xpForNextLevel' => $level >= self::MAX ? 0 : 100 * $level,
        ];
    }
}
```

- [ ] **Step 4: Rodar e passar** — `RUN --filter LevelCurveTest` → PASS (2 testes).

- [ ] **Step 5: Commit**
```bash
git add includes/Progression/LevelCurve.php tests/Unit/Progression/LevelCurveTest.php
git commit -m "feat(progression): LevelCurve pura (1-100)"
```

---

## Task 3: ProgressionRepository + AwardRepository

**Files:** Create `database/ProgressionRepository.php`, `database/AwardRepository.php`; Test `tests/Unit/Database/ProgressionRepositoriesTest.php`.

- [ ] **Step 1: Teste que falha**

`tests/Unit/Database/ProgressionRepositoriesTest.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\AwardRepository;
use GuardKids\Database\ProgressionRepository;
use PHPUnit\Framework\TestCase;

final class ProgressionRepositoriesTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<string, array<int, array<string, mixed>>> */
            public array $t = [];

            public function __construct()
            {
            }

            public function prepare($query, ...$args)
            {
                $flat = $args[0] ?? null;
                if (is_array($flat)) {
                    $args = $flat;
                }
                return vsprintf(str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%F'], (string) $query), $args);
            }

            private function nameOf(string $sql): string
            {
                preg_match('/guardkids_(progression\w*)/', $sql, $m);
                return $m[1] ?? '';
            }

            public function insert($table, $data, $format = null)
            {
                $n = $this->nameOf((string) $table);
                $this->t[$n] ??= [];
                $id = count($this->t[$n]) + 1;
                $this->insert_id = $id;
                $this->t[$n][$id] = array_merge(['id' => $id], $data);
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                $n = $this->nameOf((string) $table);
                $id = (int) ($where['id'] ?? 0);
                if (isset($this->t[$n][$id])) {
                    $this->t[$n][$id] = array_merge($this->t[$n][$id], $data);
                }
                return 1;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $rows = array_values($this->t[$this->nameOf((string) $sql)] ?? []);
                if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['child_id'] ?? 0) === (int) $m[1]));
                }
                if (preg_match('/content_id = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['content_id'] ?? 0) === (int) $m[1]));
                }
                if (preg_match("/award_date = '([^']+)'/", (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (string) ($r['award_date'] ?? '') === $m[1]));
                }
                return $rows;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testEnsureCreatesZeroedThenApplyAdds(): void
    {
        $repo = new ProgressionRepository();
        $row = $repo->ensure(1);
        self::assertSame(0, (int) $row['xp']);
        $repo->apply(1, 10, 15, 3, '2026-07-02');
        $after = $repo->findByChild(1);
        self::assertSame(10, (int) $after['xp']);
        self::assertSame(15, (int) $after['coins']);
        self::assertSame(3, (int) $after['streak_days']);
        self::assertSame('2026-07-02', $after['last_activity_date']);
    }

    public function testAwardExistsForAndRecord(): void
    {
        $repo = new AwardRepository();
        self::assertFalse($repo->existsFor(1, 10, '2026-07-02'));
        $repo->record(1, 10, '2026-07-02', 10, 5);
        self::assertTrue($repo->existsFor(1, 10, '2026-07-02'));
        self::assertFalse($repo->existsFor(1, 10, '2026-07-03'));
        self::assertFalse($repo->existsFor(1, 11, '2026-07-02'));
    }
}
```

- [ ] **Step 2: Rodar e falhar** — `RUN --filter ProgressionRepositoriesTest` → FAIL.

- [ ] **Step 3: Implementar ProgressionRepository**

`database/ProgressionRepository.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class ProgressionRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'progression';
    }

    /** @return array<string, mixed>|null */
    public function findByChild(int $childId): ?array
    {
        $rows = $this->findWhere(['child_id' => $childId]);
        return $rows[0] ?? null;
    }

    /** Garante a carteira (cria zerada se não existir). @return array<string, mixed> */
    public function ensure(int $childId): array
    {
        $row = $this->findByChild($childId);
        if ($row !== null) {
            return $row;
        }
        $this->insert([
            'child_id'           => $childId,
            'xp'                 => 0,
            'coins'              => 0,
            'streak_days'        => 0,
            'last_activity_date' => null,
        ]);
        return $this->findByChild($childId) ?? [
            'id' => 0, 'child_id' => $childId, 'xp' => 0, 'coins' => 0, 'streak_days' => 0, 'last_activity_date' => null,
        ];
    }

    public function apply(int $childId, int $xpDelta, int $coinsDelta, int $streakDays, string $lastActivityDate): void
    {
        $row = $this->ensure($childId);
        $this->update((int) $row['id'], [
            'xp'                 => (int) $row['xp'] + $xpDelta,
            'coins'              => (int) $row['coins'] + $coinsDelta,
            'streak_days'        => $streakDays,
            'last_activity_date' => $lastActivityDate,
        ]);
    }
}
```
(`insert`/`update` da base servem — a tabela tem `created_at`/`updated_at`.)

- [ ] **Step 4: Implementar AwardRepository**

`database/AwardRepository.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class AwardRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'progression_awards';
    }

    public function existsFor(int $childId, int $contentId, string $date): bool
    {
        return $this->findWhere([
            'child_id'   => $childId,
            'content_id' => $contentId,
            'award_date' => $date,
        ]) !== [];
    }

    public function record(int $childId, int $contentId, string $date, int $xp, int $coins): int
    {
        $ok = $this->db->insert($this->table(), [
            'child_id'   => $childId,
            'content_id' => $contentId,
            'award_date' => $date,
            'xp'         => $xp,
            'coins'      => $coins,
            'created_at' => current_time('mysql', true),
        ]);
        return $ok === false ? 0 : (int) $this->db->insert_id;
    }
}
```

- [ ] **Step 5: Rodar e passar** — `RUN --filter ProgressionRepositoriesTest` → PASS (2 testes).

- [ ] **Step 6: Commit**
```bash
git add database/ProgressionRepository.php database/AwardRepository.php tests/Unit/Database/ProgressionRepositoriesTest.php
git commit -m "feat(progression): ProgressionRepository (ensure/apply) + AwardRepository (dedup)"
```

---

## Task 4: Progression (engine)

**Files:** Create `includes/Progression/Progression.php`; Test `tests/Unit/Progression/ProgressionTest.php`.

- [ ] **Step 1: Teste que falha**

`tests/Unit/Progression/ProgressionTest.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Progression;

use GuardKids\Progression\Progression;
use PHPUnit\Framework\TestCase;

final class ProgressionTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<string, array<int, array<string, mixed>>> */
            public array $t = [];

            public function __construct()
            {
            }

            public function prepare($query, ...$args)
            {
                $flat = $args[0] ?? null;
                if (is_array($flat)) {
                    $args = $flat;
                }
                return vsprintf(str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%F'], (string) $query), $args);
            }

            private function nameOf(string $sql): string
            {
                preg_match('/guardkids_(progression\w*)/', $sql, $m);
                return $m[1] ?? '';
            }

            public function insert($table, $data, $format = null)
            {
                $n = $this->nameOf((string) $table);
                $this->t[$n] ??= [];
                $id = count($this->t[$n]) + 1;
                $this->insert_id = $id;
                $this->t[$n][$id] = array_merge(['id' => $id], $data);
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                $n = $this->nameOf((string) $table);
                $id = (int) ($where['id'] ?? 0);
                if (isset($this->t[$n][$id])) {
                    $this->t[$n][$id] = array_merge($this->t[$n][$id], $data);
                }
                return 1;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $rows = array_values($this->t[$this->nameOf((string) $sql)] ?? []);
                foreach (['child_id', 'content_id'] as $col) {
                    if (preg_match("/{$col} = (\d+)/", (string) $sql, $m) === 1) {
                        $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r[$col] ?? 0) === (int) $m[1]));
                    }
                }
                if (preg_match("/award_date = '([^']+)'/", (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (string) ($r['award_date'] ?? '') === $m[1]));
                }
                return $rows;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    private function wallet(int $childId): array
    {
        foreach ($this->wpdb->t['progression'] ?? [] as $r) {
            if ((int) $r['child_id'] === $childId) {
                return $r;
            }
        }
        return [];
    }

    public function testFirstOpenCreditsWithDailyBonus(): void
    {
        (new Progression())->awardForOpen(1, 10, new \DateTimeImmutable('2026-07-02 10:00:00'));
        $w = $this->wallet(1);
        self::assertSame(10, (int) $w['xp']);
        self::assertSame(10, (int) $w['coins']); // 5 base + 5 bônus do dia
        self::assertSame(1, (int) $w['streak_days']);
    }

    public function testSameContentSameDayIsNoOp(): void
    {
        $p = new Progression();
        $now = new \DateTimeImmutable('2026-07-02 10:00:00');
        $p->awardForOpen(1, 10, $now);
        $p->awardForOpen(1, 10, $now);
        self::assertSame(10, (int) $this->wallet(1)['xp']);
    }

    public function testDifferentContentSameDayCreditsWithoutSecondBonus(): void
    {
        $p = new Progression();
        $now = new \DateTimeImmutable('2026-07-02 10:00:00');
        $p->awardForOpen(1, 10, $now);
        $p->awardForOpen(1, 11, $now);
        $w = $this->wallet(1);
        self::assertSame(20, (int) $w['xp']);        // 10 + 10
        self::assertSame(15, (int) $w['coins']);     // (5+5) + 5, sem 2º bônus
    }

    public function testStreakIncrementsOnConsecutiveDayAndResetsOnGap(): void
    {
        $p = new Progression();
        $p->awardForOpen(1, 10, new \DateTimeImmutable('2026-07-02 10:00:00'));
        $p->awardForOpen(1, 11, new \DateTimeImmutable('2026-07-03 10:00:00'));
        self::assertSame(2, (int) $this->wallet(1)['streak_days']);
        $p->awardForOpen(1, 12, new \DateTimeImmutable('2026-07-06 10:00:00'));
        self::assertSame(1, (int) $this->wallet(1)['streak_days']);
    }
}
```

- [ ] **Step 2: Rodar e falhar** — `RUN --filter ProgressionTest` → FAIL.

- [ ] **Step 3: Implementar**

`includes/Progression/Progression.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Progression;

use DateTimeImmutable;
use GuardKids\Database\AwardRepository;
use GuardKids\Database\ProgressionRepository;

/**
 * Engine de ganho: cada conteúdo distinto aberto no dia rende XP/coins
 * (anti-farm via ledger). Streak por dias consecutivos com atividade.
 */
final class Progression
{
    private const XP_PER_OPEN = 10;
    private const COINS_PER_OPEN = 5;
    private const DAILY_BONUS_COINS = 5;

    private readonly ProgressionRepository $wallet;
    private readonly AwardRepository $awards;

    public function __construct(?ProgressionRepository $wallet = null, ?AwardRepository $awards = null)
    {
        $this->wallet = $wallet ?? new ProgressionRepository();
        $this->awards = $awards ?? new AwardRepository();
    }

    public function awardForOpen(int $childId, int $contentId, DateTimeImmutable $now): void
    {
        $date = $now->format('Y-m-d');
        if ($this->awards->existsFor($childId, $contentId, $date)) {
            return;
        }

        $wallet = $this->wallet->ensure($childId);
        $last = $wallet['last_activity_date'] ?? null;
        $yesterday = $now->modify('-1 day')->format('Y-m-d');

        if ($last === $date) {
            $streak = (int) $wallet['streak_days'];
            $bonus = 0;
        } elseif ($last === $yesterday) {
            $streak = (int) $wallet['streak_days'] + 1;
            $bonus = self::DAILY_BONUS_COINS;
        } else {
            $streak = 1;
            $bonus = self::DAILY_BONUS_COINS;
        }

        $this->awards->record($childId, $contentId, $date, self::XP_PER_OPEN, self::COINS_PER_OPEN);
        $this->wallet->apply($childId, self::XP_PER_OPEN, self::COINS_PER_OPEN + $bonus, $streak, $date);
    }
}
```

- [ ] **Step 4: Rodar e passar** — `RUN --filter ProgressionTest` → PASS (4 testes).

- [ ] **Step 5: Commit**
```bash
git add includes/Progression/Progression.php tests/Unit/Progression/ProgressionTest.php
git commit -m "feat(progression): engine awardForOpen (anti-farm + streak)"
```

---

## Task 5: GamificationController + rotas + hook no childHistory

**Files:** Create `api/Controllers/GamificationController.php`; Modify `api/RestApi.php`, `api/Controllers/ContentController.php`; Test `tests/Unit/Api/GamificationControllerTest.php`.

- [ ] **Step 1: Teste que falha**

`tests/Unit/Api/GamificationControllerTest.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\GamificationController;
use GuardKids\Auth\ChildAuth;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class GamificationControllerTest extends TestCase
{
    private \wpdb $wpdb;
    private string $token = '';

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<string, string> */
            public array $settings = [];
            /** @var array<string, array<int, array<string, mixed>>> */
            public array $t = [];

            public function __construct()
            {
            }

            public function prepare($query, ...$args)
            {
                $flat = $args[0] ?? null;
                if (is_array($flat)) {
                    $args = $flat;
                }
                return vsprintf(str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%F'], (string) $query), $args);
            }

            public function get_var($sql, $x = 0, $y = 0)
            {
                if (preg_match("/setting_key = '([^']+)'/", (string) $sql, $m) === 1) {
                    if (str_contains((string) $sql, 'SELECT id')) {
                        return isset($this->settings[$m[1]]) ? '1' : null;
                    }
                    return $this->settings[$m[1]] ?? null;
                }
                return null;
            }

            public function get_results($sql, $output = OBJECT)
            {
                preg_match('/guardkids_(progression\w*)/', (string) $sql, $tn);
                $rows = array_values($this->t[$tn[1] ?? ''] ?? []);
                if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['child_id'] ?? 0) === (int) $m[1]));
                }
                return $rows;
            }

            public function insert($table, $data, $format = null)
            {
                if (str_contains((string) $table, 'guardkids_settings')) {
                    $this->settings[$data['setting_key']] = (string) $data['value'];
                    return 1;
                }
                preg_match('/guardkids_(progression\w*)/', (string) $table, $tn);
                $n = $tn[1] ?? '';
                $this->t[$n] ??= [];
                $id = count($this->t[$n]) + 1;
                $this->insert_id = $id;
                $this->t[$n][$id] = array_merge(['id' => $id], $data);
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                preg_match('/guardkids_(progression\w*)/', (string) $table, $tn);
                $n = $tn[1] ?? '';
                $id = (int) ($where['id'] ?? 0);
                if (isset($this->t[$n][$id])) {
                    $this->t[$n][$id] = array_merge($this->t[$n][$id], $data);
                }
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
        $issued = (new ChildAuth())->issueToken(1, 'tablet');
        $this->token = $issued['token'];
    }

    private function tokenReq(string $method, string $route): WP_REST_Request
    {
        $req = new WP_REST_Request($method, $route);
        $req->set_header('X-GuardKids-Token', $this->token);
        return $req;
    }

    public function testChildProgressionZeroWithoutWallet(): void
    {
        $res = (new GamificationController())->childProgression($this->tokenReq('GET', '/child/progression'));
        $data = $res->get_data();
        self::assertSame(0, $data['xp']);
        self::assertSame(1, $data['level']);
        self::assertSame(0, $data['streakDays']);
    }

    public function testChildProgression401WithoutToken(): void
    {
        $res = (new GamificationController())->childProgression(new WP_REST_Request('GET', '/child/progression'));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(401, $res->get_error_data()['status']);
    }

    public function testParentProgressionReflectsWallet(): void
    {
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 5, 'xp' => 150, 'coins' => 20, 'streak_days' => 3, 'last_activity_date' => '2026-07-02'],
        ];
        $req = new WP_REST_Request('GET', '/progression');
        $req->set_param('child_id', 5);
        $data = (new GamificationController())->progression($req)->get_data();
        self::assertSame(150, $data['xp']);
        self::assertSame(2, $data['level']);
        self::assertSame(3, $data['streakDays']);
        self::assertSame(0, $data['missionsCompleted']);
    }
}
```

- [ ] **Step 2: Rodar e falhar** — `RUN --filter GamificationControllerTest` → FAIL.

- [ ] **Step 3: Implementar o controller**

`api/Controllers/GamificationController.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Auth\ChildAuth;
use GuardKids\Database\ProgressionRepository;
use GuardKids\Progression\LevelCurve;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class GamificationController
{
    private readonly ProgressionRepository $progression;
    private readonly ChildAuth $auth;

    public function __construct()
    {
        $this->progression = new ProgressionRepository();
        $this->auth        = new ChildAuth();
    }

    public function childProgression(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        return rest_ensure_response($this->walletJson($childId));
    }

    public function progression(WP_REST_Request $req): WP_REST_Response
    {
        $childId = (int) $req->get_param('child_id');
        $w = $this->walletJson($childId);
        return rest_ensure_response([
            'xp'                => $w['xp'],
            'coins'             => $w['coins'],
            'level'             => $w['level'],
            'streakDays'        => $w['streakDays'],
            'missionsCompleted' => 0,
        ]);
    }

    /**
     * @return array{xp:int,coins:int,level:int,xpIntoLevel:int,xpForNextLevel:int,streakDays:int}
     */
    private function walletJson(int $childId): array
    {
        $row = $this->progression->findByChild($childId);
        $xp = $row !== null ? (int) $row['xp'] : 0;
        $coins = $row !== null ? (int) $row['coins'] : 0;
        $streak = $row !== null ? (int) $row['streak_days'] : 0;
        $p = LevelCurve::progressInLevel($xp);
        return [
            'xp'             => $xp,
            'coins'          => $coins,
            'level'          => $p['level'],
            'xpIntoLevel'    => $p['xpIntoLevel'],
            'xpForNextLevel' => $p['xpForNextLevel'],
            'streakDays'     => $streak,
        ];
    }
}
```

- [ ] **Step 4: Registrar rotas** — em `api/RestApi.php`, adicionar `$this->registerGamificationRoutes();` ao final de `registerRoutes()`, o import `use GuardKids\Api\Controllers\GamificationController;`, e o método:
```php
    private function registerGamificationRoutes(): void
    {
        $controller = new GamificationController();

        register_rest_route(self::NAMESPACE, '/child/progression', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'childProgression'],
            'permission_callback' => (new ChildAuth())->requireToken(),
        ]);

        register_rest_route(self::NAMESPACE, '/progression', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'progression'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);
    }
```

- [ ] **Step 5: Hook aditivo no childHistory**

Em `api/Controllers/ContentController.php`, adicionar o import `use GuardKids\Progression\Progression;`. No método `childHistory`, após `$this->history->record(...)` e antes do `return`, adicionar:
```php
        if ($action === 'open') {
            try {
                $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
                (new Progression())->awardForOpen($childId, $contentId, new \DateTimeImmutable('now', $tz));
            } catch (\Throwable $e) {
                error_log('[GuardKids] award falhou: ' . $e->getMessage());
            }
        }
```

- [ ] **Step 6: Teste do hook**

Adicionar em `tests/Unit/Api/GamificationControllerTest.php` (o mesmo fake já resolve `guardkids_progression*`; falta o `content_history` e o token→childId; reusa o token da child 1). O `ContentController::childHistory` grava history (tabela `content_history`) e credita. Para o fake acima capturar `content_history`, generalizar o `insert`/`get_results` do fake pra qualquer `guardkids_*`:
```php
    public function testChildHistoryOpenCreditsProgression(): void
    {
        // fake já captura guardkids_* genérico (ver nota); token = child 1
        $req = $this->tokenReq('POST', '/child/library/history');
        $req->set_param('content_id', 10);
        $req->set_param('action', 'open');
        $req->set_param('duration_seconds', 0);
        (new \GuardKids\Api\Controllers\ContentController())->childHistory($req);
        // progression da child 1 recebeu XP
        $wallet = array_values($this->wpdb->t['progression'] ?? []);
        self::assertNotEmpty($wallet);
        self::assertSame(10, (int) $wallet[0]['xp']);
    }
```
> Ajuste no fake pra este teste: trocar os regex `guardkids_(progression\w*)` por `guardkids_([a-z_]+)` no `insert`/`update`/`get_results` (assim captura `content_history`, `content_items`, etc. também). Manter o branch de `guardkids_settings` no `insert`. E o `get_results` de `content_items`/`content_history` só precisa devolver `array_values` filtrado por child_id — o `childHistory` não lê content nesse fluxo (só grava history + credita).

- [ ] **Step 7: Rodar e passar** — `RUN --filter GamificationControllerTest` → PASS. Depois `RUN --filter ContentControllerTest` (garante que o hook não quebrou os testes da S2 — o award é try/catch) e `RUN` (inteira) → PASS.

- [ ] **Step 8: Commit**
```bash
git add api/Controllers/GamificationController.php api/RestApi.php api/Controllers/ContentController.php tests/Unit/Api/GamificationControllerTest.php
git commit -m "feat(api): GamificationController + hook de ganho no childHistory"
```

---

## Task 6: app-child — ProgressCard na Home

**Files:** Create `public/app-child/src/api/gamification.ts`, `src/components/ProgressCard.tsx`, `src/components/ProgressCard.test.tsx`; Modify `src/pages/Home.tsx`.

- [ ] **Step 1: api**

`public/app-child/src/api/gamification.ts`:
```ts
import { apiFetch } from './client';

export type Progression = {
  xp: number;
  coins: number;
  level: number;
  xpIntoLevel: number;
  xpForNextLevel: number;
  streakDays: number;
};

export function getProgression(): Promise<Progression> {
  return apiFetch<Progression>('/child/progression');
}
```

- [ ] **Step 2: Teste que falha**

`public/app-child/src/components/ProgressCard.test.tsx`:
```tsx
import { screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { ProgressCard } from './ProgressCard';

const getProgression = vi.fn();
vi.mock('../api/gamification', () => ({ getProgression: () => getProgression() }));

describe('ProgressCard', () => {
  afterEach(() => getProgression.mockReset());

  it('mostra nível, coins e streak', async () => {
    getProgression.mockResolvedValueOnce({ xp: 150, coins: 20, level: 2, xpIntoLevel: 50, xpForNextLevel: 200, streakDays: 3 });
    renderWithClient(<ProgressCard />);
    expect(await screen.findByText(/nível 2/i)).toBeInTheDocument();
    expect(screen.getByText('20')).toBeInTheDocument();
    expect(screen.getByText('3')).toBeInTheDocument();
  });
});
```

- [ ] **Step 3: Rodar e falhar** — `cd public/app-child && pnpm test ProgressCard` → FAIL.

- [ ] **Step 4: Implementar**

`public/app-child/src/components/ProgressCard.tsx`:
```tsx
import { useQuery } from '@tanstack/react-query';
import { getProgression } from '../api/gamification';
import { Icon } from './Icon';

export function ProgressCard() {
  const query = useQuery({ queryKey: ['child', 'progression'], queryFn: getProgression });
  const p = query.data;

  if (query.isLoading) {
    return <div className="glass-panel h-24 animate-pulse rounded-2xl bg-surface-container-low" />;
  }
  if (!p) return null;

  const pct = p.xpForNextLevel > 0 ? Math.min(100, Math.round((p.xpIntoLevel / p.xpForNextLevel) * 100)) : 100;

  return (
    <div className="glass-panel rounded-2xl p-4 shadow-ambient">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <div className="flex h-10 w-10 items-center justify-center rounded-full bg-primary text-white">
            <Icon name="stars" className="text-xl" filled />
          </div>
          <div>
            <div className="font-display text-label-md font-bold text-on-surface">Nível {p.level}</div>
            <div className="text-label-sm text-on-surface-variant">Minha Evolução</div>
          </div>
        </div>
        <div className="flex items-center gap-3">
          <span className="flex items-center gap-1 text-label-md font-bold text-orange-warm">
            <Icon name="paid" className="text-base" filled /> {p.coins}
          </span>
          <span className="flex items-center gap-1 text-label-md font-bold text-error">
            <Icon name="local_fire_department" className="text-base" filled /> {p.streakDays}
          </span>
        </div>
      </div>
      <div className="mt-3 h-2 w-full overflow-hidden rounded-full bg-surface-variant">
        <div className="h-full rounded-full bg-primary" style={{ width: `${pct}%` }} />
      </div>
      {p.xpForNextLevel > 0 && (
        <div className="mt-1 text-label-sm text-on-surface-variant">{p.xpIntoLevel}/{p.xpForNextLevel} XP</div>
      )}
    </div>
  );
}
```

- [ ] **Step 5: Renderizar na Home**

Em `public/app-child/src/pages/Home.tsx`: importar `import { ProgressCard } from '../components/ProgressCard';` e renderizar como **primeiro** filho do `<main>` (antes de `<Welcome>`):
```tsx
      <ProgressCard />
```

- [ ] **Step 6: Rodar e passar + suíte + tsc**
```bash
pnpm test ProgressCard && pnpm test && pnpm exec tsc -b
```
Expected: PASS; suíte verde; TS limpo.

- [ ] **Step 7: Commit**
```bash
git add public/app-child/src/api/gamification.ts public/app-child/src/components/ProgressCard.tsx public/app-child/src/components/ProgressCard.test.tsx public/app-child/src/pages/Home.tsx
git commit -m "feat(app-child): ProgressCard (Minha Evolução) na Home"
```

---

## Task 7: app-parent — aba Gamificação

**Files:** Create `public/app-parent/src/api/gamification.ts`, `src/pages/GamificationDashboard.tsx`, `src/pages/GamificationDashboard.test.tsx`; Modify `src/data/mockData.ts`, `src/App.tsx`.

- [ ] **Step 1: api + nav**

`public/app-parent/src/api/gamification.ts`:
```ts
import { apiFetch } from './client';

export type ChildProgression = {
  xp: number;
  coins: number;
  level: number;
  streakDays: number;
  missionsCompleted: number;
};

export function getChildProgression(childId: number): Promise<ChildProgression> {
  return apiFetch<ChildProgression>(`/progression?child_id=${childId}`);
}
```

Em `public/app-parent/src/data/mockData.ts`: adicionar `| 'gamification'` ao union `PageId`, e em `navItems` após a linha de `content`:
```ts
  { id: 'gamification' as PageId, label: 'Gamificação', icon: 'stadia_controller' },
```

- [ ] **Step 2: Teste que falha**

`public/app-parent/src/pages/GamificationDashboard.test.tsx`:
```tsx
import { screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { GamificationDashboard } from './GamificationDashboard';

const listChildren = vi.fn();
const getChildProgression = vi.fn();
vi.mock('../api/children', () => ({ listChildren: () => listChildren() }));
vi.mock('../api/gamification', () => ({ getChildProgression: (id: number) => getChildProgression(id) }));

describe('GamificationDashboard', () => {
  afterEach(() => {
    listChildren.mockReset();
    getChildProgression.mockReset();
  });

  it('mostra um card por filho com nível e coins', async () => {
    listChildren.mockResolvedValueOnce([{ id: 5, name: 'Lucas' }]);
    getChildProgression.mockResolvedValue({ xp: 150, coins: 20, level: 2, streakDays: 3, missionsCompleted: 0 });
    renderWithClient(<GamificationDashboard />);
    expect(await screen.findByText('Lucas')).toBeInTheDocument();
    expect(await screen.findByText(/nível 2/i)).toBeInTheDocument();
  });

  it('mostra estado vazio sem filhos', async () => {
    listChildren.mockResolvedValueOnce([]);
    renderWithClient(<GamificationDashboard />);
    expect(await screen.findByText(/nenhum filho/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 3: Rodar e falhar** — `cd public/app-parent && pnpm test GamificationDashboard` → FAIL.

- [ ] **Step 4: Implementar**

`public/app-parent/src/pages/GamificationDashboard.tsx`:
```tsx
import { useQuery } from '@tanstack/react-query';
import { listChildren, type Child } from '../api/children';
import { getChildProgression } from '../api/gamification';

function ChildProgressCard({ child }: { child: Child }) {
  const query = useQuery({ queryKey: ['progression', child.id], queryFn: () => getChildProgression(child.id) });
  const p = query.data;
  const metrics = [
    { label: 'Nível', value: p ? `Nível ${p.level}` : '—' },
    { label: 'XP', value: p?.xp ?? 0 },
    { label: 'GuardCoins', value: p?.coins ?? 0 },
    { label: 'Missões concluídas', value: p?.missionsCompleted ?? 0 },
    { label: 'Dias consecutivos', value: p?.streakDays ?? 0 },
  ];
  return (
    <div className="rounded-2xl border border-outline-variant bg-surface p-4 shadow-sm">
      <h3 className="mb-3 font-display text-headline-md text-on-surface">{child.name}</h3>
      <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
        {metrics.map((m) => (
          <div key={m.label}>
            <div className="text-2xl font-bold text-primary">{m.value}</div>
            <div className="text-label-sm text-on-surface-variant">{m.label}</div>
          </div>
        ))}
      </div>
    </div>
  );
}

export function GamificationDashboard() {
  const children = useQuery({ queryKey: ['children'], queryFn: listChildren });
  const list = children.data ?? [];

  return (
    <main className="flex-1 space-y-6 p-6">
      <div>
        <h1 className="font-display text-headline-lg text-on-background">Gamificação</h1>
        <p className="text-body-md text-on-surface-variant">A evolução de cada filho no Mundo Guardião.</p>
      </div>
      {children.isLoading ? (
        <div className="h-24 animate-pulse rounded-2xl bg-surface-container-low" />
      ) : list.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-2xl border-2 border-dashed border-outline-variant p-10 text-center">
          <span className="material-symbols-outlined text-5xl text-outline">stadia_controller</span>
          <p className="text-label-lg font-semibold text-on-surface">Nenhum filho cadastrado</p>
        </div>
      ) : (
        <div className="space-y-4">
          {list.map((c) => <ChildProgressCard key={c.id} child={c} />)}
        </div>
      )}
    </main>
  );
}
```

- [ ] **Step 5: Rotear no App** — em `public/app-parent/src/App.tsx`: importar `import { GamificationDashboard } from './pages/GamificationDashboard';` e no switch do `PageRenderer`:
```tsx
    case 'gamification':
      return <GamificationDashboard />;
```

- [ ] **Step 6: Rodar e passar + suíte + tsc**
```bash
pnpm test GamificationDashboard && pnpm exec tsc -b && pnpm test
```
Expected: PASS; TS limpo; suíte verde (se algum teste de nav asserta contagem de itens, atualizar).

- [ ] **Step 7: Commit**
```bash
git add public/app-parent/src/api/gamification.ts public/app-parent/src/data/mockData.ts public/app-parent/src/App.tsx public/app-parent/src/pages/GamificationDashboard.tsx public/app-parent/src/pages/GamificationDashboard.test.tsx
git commit -m "feat(app-parent): aba Gamificação (progressão por filho)"
```

---

## Task 8: Verificação completa + release + deploy

- [ ] **Step 1: Suítes completas**

PHP: `RUN` → verde. Apps:
```bash
cd public/app-parent && pnpm test && pnpm exec tsc -b && pnpm build
cd ../app-child && pnpm test && pnpm exec tsc -b && pnpm build && pnpm test:e2e
```
Expected: tudo verde.

- [ ] **Step 2: PR + CI**
```bash
git push -u origin feat/gamificacao-sprint3a
gh pr create --base master --head feat/gamificacao-sprint3a \
  --title "feat: Gamificação 3a — economia/progressão" \
  --body "Fundação da gamificação: GuardCoins + XP + Níveis (1-100) + engine de ganho por conteúdo aberto (anti-farm/dia) + streak. Migração 018 (progression + awards, DB v18). Painéis Minha Evolução (filho) + Gamificação (pais). Hook aditivo no childHistory. Missões/medalhas/avatar/recompensas = fatias 3b-3e. Spec/plano em docs/superpowers/."
```
Acompanhar CI 4 jobs. **Integration** roda a migração 018.

- [ ] **Step 3: Merge squash**
```bash
gh pr merge <N> --squash --delete-branch
git checkout master && git pull --ff-only
```

- [ ] **Step 4: Bump versão + tag + release** — em `guardkids.php` bumpar `Version:` e `GUARDKIDS_VERSION` pra `1.29.0`, commit `chore(release): v1.29.0 — Gamificação 3a`, tag `v1.29.0`, push, zip:
```bash
"$PHP" -d extension_dir="$EXT" -d extension=zip scripts/build-release-zip.php
gh release create v1.29.0 --title "v1.29.0 — Gamificação (economia/progressão)" \
  --notes "<resumo>" "C:/Users/mysho/OneDrive/Documentos/guardkids-wp/guardkids-wp-1.29.0.zip"
```

- [ ] **Step 5: Deploy SSH + smoke (confirmar DB v18)**
```bash
scp -o BatchMode=yes -P 65002 "<zip>" u217136411@82.25.73.253:~/
ssh -o BatchMode=yes -p 65002 u217136411@82.25.73.253 \
  'cd ~/domains/guardiaokids.site/public_html \
   && cp -r wp-content/plugins/guardkids-wp wp-content/plugins/guardkids-wp.bak-$(date +%Y%m%d-%H%M) \
   && wp plugin install ~/guardkids-wp-1.29.0.zip --force \
   && wp plugin get guardkids-wp --field=version \
   && wp option get guardkids_db_version \
   && wp db query "SHOW TABLES LIKE '"'"'%guardkids_progression%'"'"'" \
   && rm -f ~/guardkids-wp-1.29.0.zip'
```
Expected: version `1.29.0`, `guardkids_db_version` **18**, 2 tabelas `progression*`. Smoke: home 200, `/child/progression` sem token → 401, `/progression` sem nonce → 401, painéis carregam.

---

## Self-Review (feito na escrita do plano)

- **Cobertura do spec:** §3 migração → Task 1; §4 LevelCurve → Task 2; §5 repos → Task 3; §6 engine → Task 4; §7 REST + hook → Task 5; §8 app-filho → Task 6; §9 app-pais → Task 7; §10 UX (loading/vazio) → Tasks 6-7; §11 testes → embutidos. ✅
- **Placeholders:** a nota no Task 5 Step 6 (generalizar regex do fake pra `guardkids_([a-z_]+)`) traz a ação exata. Sem TODO/TBD.
- **Consistência de tipos/assinaturas:** `LevelCurve::{levelForXp,progressInLevel{level,xpIntoLevel,xpForNextLevel},totalToReach}`; `ProgressionRepository::{findByChild,ensure,apply(childId,xpDelta,coinsDelta,streakDays,lastActivityDate)}`; `AwardRepository::{existsFor,record}`; `Progression::awardForOpen(childId,contentId,DateTimeImmutable)`; controller `childProgression`/`progression` + `walletJson`; shapes `{xp,coins,level,xpIntoLevel,xpForNextLevel,streakDays}` (child) e `{xp,coins,level,streakDays,missionsCompleted}` (parent) iguais no PHP e nos tipos TS (`Progression`/`ChildProgression`). ✅
