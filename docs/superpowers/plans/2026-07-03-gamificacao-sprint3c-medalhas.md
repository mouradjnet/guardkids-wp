# Sprint 3c — Medalhas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Adicionar medalhas permanentes automáticas ao guardkids-wp: 6 conquistas de marco (conteúdo/missões/sequência/nível/categoria) desbloqueadas de uma vez, com bônus único de XP/coins creditado na carteira da gamificação.

**Architecture:** Peças puras (`MedalCatalog` + `MedalEvaluator`) definem e avaliam as medalhas a partir de sinais acumulados existentes. Um `MedalController` calcula no read e credita o desbloqueio de forma preguiçosa e idempotente, gravando num ledger anti-duplo (`medal_unlocks`, migração 020, UNIQUE `child_id+medal_key` sem data — desbloqueio é permanente). O response reflete permanência: uma medalha já no ledger aparece como `unlocked` mesmo que o sinal caia depois. Espelha o padrão da 3b (já em prod).

**Tech Stack:** PHP 8.2 (WordPress, `$wpdb`, PSR-4 self-contained), PHPUnit 9.6, React/TS/Vite/Tailwind + TanStack Query, Vitest.

**Spec:** `docs/superpowers/specs/2026-07-03-gamificacao-sprint3c-medalhas-design.md`

---

## Convenções de comando (usadas nos passos)

**Rodar testes PHP** (PHP 8.2 do LocalWP; o `php` do PATH é 8.1 e NÃO serve). Defina uma vez no shell:

```bash
export PHP82="/c/Users/mysho/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe"
export PHPEXT="C:\\Users\\mysho\\AppData\\Roaming\\Local\\lightning-services\\php-8.2.29+0\\bin\\win64\\ext"
printf '[req]\ndefault_bits=2048\ndistinguished_name=req_dn\n[req_dn]\n' > /tmp/openssl.cnf
export OPENSSL_CONF="$(cygpath -w /tmp/openssl.cnf)"
phpunit() { "$PHP82" -n -d extension_dir="$PHPEXT" -d extension=mbstring -d extension=openssl -d extension=sodium -d memory_limit=512M vendor/bin/phpunit -c phpunit.xml.dist --no-coverage "$@"; }
```
Depois: `phpunit --filter MedalCatalogTest` etc.

**Front:** `cd public/app-child && npx vitest run <arquivo>` (idem `app-parent`). cwd = raiz do repo `C:/Users/mysho/guardkids-wp`.

**Baselines atuais (master com 3b já mergeado):** PHP unit **476**, vitest app-child **103**, app-parent **302**.

---

## File Structure

**Criar:**
- `includes/Medals/MedalCatalog.php` — as 6 medalhas como constantes (puro).
- `includes/Medals/MedalEvaluator.php` — avalia sinais → estado das medalhas (puro).
- `database/MedalUnlockRepository.php` — ledger de desbloqueio + leitura dos sinais acumulados.
- `database/migrations/020_medal_unlocks.php` — tabela `medal_unlocks`.
- `api/Controllers/MedalController.php` — endpoint `/child/medals` (crédito preguiçoso idempotente + permanência).
- `tests/Unit/Medals/MedalCatalogTest.php`
- `tests/Unit/Medals/MedalEvaluatorTest.php`
- `tests/Unit/Database/MedalUnlockRepositoryTest.php`
- `tests/Unit/Api/MedalControllerTest.php`
- `public/app-child/src/components/MedalsCard.tsx`
- `public/app-child/src/components/MedalsCard.test.tsx`

**Modificar:**
- `guardkids.php:22` — `GUARDKIDS_DB_VERSION` 19 → 20.
- `uninstall.php` — adicionar `medal_unlocks` ao drop.
- `api/RestApi.php` — registrar `/child/medals`.
- `api/Controllers/GamificationController.php` — `medalsUnlocked` novo no payload.
- `tests/Unit/Api/GamificationControllerTest.php` — cobrir `medalsUnlocked`.
- `public/app-child/src/api/gamification.ts` — `type Medal` + `getMedals()`.
- `public/app-child/src/pages/Home.tsx` — renderizar `<MedalsCard />`.
- `public/app-parent/src/api/gamification.ts` — `medalsUnlocked` no tipo `ChildProgression`.
- `public/app-parent/src/pages/GamificationDashboard.tsx` — métrica "Medalhas".
- `public/app-parent/src/pages/GamificationDashboard.test.tsx` — assert da métrica.

---

### Task 1: MedalCatalog (definição pura das 6 medalhas)

**Files:**
- Create: `includes/Medals/MedalCatalog.php`
- Test: `tests/Unit/Medals/MedalCatalogTest.php`

- [ ] **Step 1: Escrever o teste que falha**

Create `tests/Unit/Medals/MedalCatalogTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Medals;

use GuardKids\Medals\MedalCatalog;
use PHPUnit\Framework\TestCase;

final class MedalCatalogTest extends TestCase
{
    public function testHasSixCuratedMedals(): void
    {
        $keys = array_column(MedalCatalog::all(), 'key');
        self::assertSame(
            ['explorer_10', 'devourer_50', 'achiever_10', 'faithful_7', 'veteran_10', 'curious_master_5'],
            $keys,
        );
    }

    public function testEachMedalHasSignalTargetAndRewards(): void
    {
        $validSignals = ['level', 'streakDays', 'totalContentOpened', 'totalMissionsCompleted', 'distinctCategoriesAllTime'];
        foreach (MedalCatalog::all() as $m) {
            self::assertArrayHasKey('title', $m);
            self::assertArrayHasKey('description', $m);
            self::assertArrayHasKey('icon', $m);
            self::assertContains($m['signal'], $validSignals);
            self::assertGreaterThan(0, $m['target']);
            self::assertGreaterThan(0, $m['xpReward']);
            self::assertGreaterThan(0, $m['coinsReward']);
        }
    }

    public function testSignalsAndTargetsMatchCatalog(): void
    {
        $bySignal = [];
        $byTarget = [];
        foreach (MedalCatalog::all() as $m) {
            $bySignal[$m['key']] = $m['signal'];
            $byTarget[$m['key']] = $m['target'];
        }
        self::assertSame('totalContentOpened', $bySignal['explorer_10']);
        self::assertSame(10, $byTarget['explorer_10']);
        self::assertSame('totalContentOpened', $bySignal['devourer_50']);
        self::assertSame(50, $byTarget['devourer_50']);
        self::assertSame('totalMissionsCompleted', $bySignal['achiever_10']);
        self::assertSame('streakDays', $bySignal['faithful_7']);
        self::assertSame('level', $bySignal['veteran_10']);
        self::assertSame('distinctCategoriesAllTime', $bySignal['curious_master_5']);
        self::assertSame(5, $byTarget['curious_master_5']);
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `phpunit --filter MedalCatalogTest`
Expected: FAIL com "Class MedalCatalog not found".

- [ ] **Step 3: Implementar**

Create `includes/Medals/MedalCatalog.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Medals;

/**
 * Catálogo das medalhas permanentes (puro, sem $wpdb). Cada medalha carrega
 * qual `signal` (eixo acumulado) ela lê; ajuste alvos/bônus só aqui.
 */
final class MedalCatalog
{
    /**
     * @return array<int, array{key:string, title:string, description:string, icon:string, signal:string, target:int, xpReward:int, coinsReward:int}>
     */
    public static function all(): array
    {
        return [
            [
                'key'         => 'explorer_10',
                'title'       => 'Explorador',
                'description' => 'Abriu 10 conteúdos',
                'icon'        => 'explore',
                'signal'      => 'totalContentOpened',
                'target'      => 10,
                'xpReward'    => 30,
                'coinsReward' => 20,
            ],
            [
                'key'         => 'devourer_50',
                'title'       => 'Devorador',
                'description' => 'Abriu 50 conteúdos',
                'icon'        => 'auto_stories',
                'signal'      => 'totalContentOpened',
                'target'      => 50,
                'xpReward'    => 60,
                'coinsReward' => 40,
            ],
            [
                'key'         => 'achiever_10',
                'title'       => 'Cumpridor',
                'description' => 'Completou 10 missões',
                'icon'        => 'task_alt',
                'signal'      => 'totalMissionsCompleted',
                'target'      => 10,
                'xpReward'    => 40,
                'coinsReward' => 25,
            ],
            [
                'key'         => 'faithful_7',
                'title'       => 'Fiel',
                'description' => '7 dias de sequência',
                'icon'        => 'local_fire_department',
                'signal'      => 'streakDays',
                'target'      => 7,
                'xpReward'    => 40,
                'coinsReward' => 25,
            ],
            [
                'key'         => 'veteran_10',
                'title'       => 'Veterano',
                'description' => 'Alcançou o nível 10',
                'icon'        => 'military_tech',
                'signal'      => 'level',
                'target'      => 10,
                'xpReward'    => 50,
                'coinsReward' => 30,
            ],
            [
                'key'         => 'curious_master_5',
                'title'       => 'Curioso Master',
                'description' => 'Explorou 5 categorias',
                'icon'        => 'category',
                'signal'      => 'distinctCategoriesAllTime',
                'target'      => 5,
                'xpReward'    => 40,
                'coinsReward' => 25,
            ],
        ];
    }
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Run: `phpunit --filter MedalCatalogTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/Medals/MedalCatalog.php tests/Unit/Medals/MedalCatalogTest.php
git commit -m "feat(medals): MedalCatalog com as 6 medalhas curadas"
```

---

### Task 2: MedalEvaluator (avaliação pura dos sinais)

**Files:**
- Create: `includes/Medals/MedalEvaluator.php`
- Test: `tests/Unit/Medals/MedalEvaluatorTest.php`

- [ ] **Step 1: Escrever o teste que falha**

Create `tests/Unit/Medals/MedalEvaluatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Medals;

use GuardKids\Medals\MedalEvaluator;
use PHPUnit\Framework\TestCase;

final class MedalEvaluatorTest extends TestCase
{
    /** @return array<string, int> */
    private function signals(int $level = 0, int $streak = 0, int $opened = 0, int $missions = 0, int $categories = 0): array
    {
        return [
            'level'                     => $level,
            'streakDays'                => $streak,
            'totalContentOpened'        => $opened,
            'totalMissionsCompleted'    => $missions,
            'distinctCategoriesAllTime' => $categories,
        ];
    }

    /** @param array<string,int> $signals */
    private function medal(array $signals, string $key): array
    {
        foreach (MedalEvaluator::evaluate($signals) as $m) {
            if ($m['key'] === $key) {
                return $m;
            }
        }
        self::fail("medal {$key} not found");
    }

    public function testAllLockedWhenNothingDone(): void
    {
        foreach (MedalEvaluator::evaluate($this->signals()) as $m) {
            self::assertSame(0, $m['progress']);
            self::assertFalse($m['unlocked']);
        }
    }

    public function testContentAxisTwoThresholds(): void
    {
        // 10 opens: explorer_10 unlocked (10/10), devourer_50 not (10/50)
        $s = $this->signals(opened: 10);
        self::assertTrue($this->medal($s, 'explorer_10')['unlocked']);
        self::assertSame(10, $this->medal($s, 'explorer_10')['progress']);
        self::assertFalse($this->medal($s, 'devourer_50')['unlocked']);
        self::assertSame(10, $this->medal($s, 'devourer_50')['progress']);
    }

    public function testEachSignalMapsToItsMedal(): void
    {
        self::assertTrue($this->medal($this->signals(missions: 10), 'achiever_10')['unlocked']);
        self::assertTrue($this->medal($this->signals(streak: 7), 'faithful_7')['unlocked']);
        self::assertTrue($this->medal($this->signals(level: 10), 'veteran_10')['unlocked']);
        self::assertTrue($this->medal($this->signals(categories: 5), 'curious_master_5')['unlocked']);
    }

    public function testProgressClampedToTarget(): void
    {
        $s = $this->signals(opened: 999, level: 99, streak: 99, missions: 99, categories: 99);
        self::assertSame(10, $this->medal($s, 'explorer_10')['progress']);
        self::assertSame(50, $this->medal($s, 'devourer_50')['progress']);
        self::assertSame(5, $this->medal($s, 'curious_master_5')['progress']);
    }

    public function testPartialProgressNotUnlocked(): void
    {
        $s = $this->signals(missions: 9);
        self::assertSame(9, $this->medal($s, 'achiever_10')['progress']);
        self::assertFalse($this->medal($s, 'achiever_10')['unlocked']);
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `phpunit --filter MedalEvaluatorTest`
Expected: FAIL com "Class MedalEvaluator not found".

- [ ] **Step 3: Implementar**

Create `includes/Medals/MedalEvaluator.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Medals;

/**
 * Avaliação pura: recebe os sinais acumulados e devolve o estado de cada
 * medalha (progress clampado ao alvo + unlocked). Mapeia pelo campo `signal`
 * do catálogo. Não toca no banco.
 */
final class MedalEvaluator
{
    /**
     * @param array{level:int, streakDays:int, totalContentOpened:int, totalMissionsCompleted:int, distinctCategoriesAllTime:int} $signals
     * @return array<int, array{key:string, title:string, description:string, icon:string, target:int, progress:int, unlocked:bool}>
     */
    public static function evaluate(array $signals): array
    {
        $out = [];
        foreach (MedalCatalog::all() as $m) {
            $raw      = (int) ($signals[$m['signal']] ?? 0);
            $progress = min($raw, $m['target']);
            $out[] = [
                'key'         => $m['key'],
                'title'       => $m['title'],
                'description' => $m['description'],
                'icon'        => $m['icon'],
                'target'      => $m['target'],
                'progress'    => $progress,
                'unlocked'    => $progress >= $m['target'],
            ];
        }
        return $out;
    }
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Run: `phpunit --filter MedalEvaluatorTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/Medals/MedalEvaluator.php tests/Unit/Medals/MedalEvaluatorTest.php
git commit -m "feat(medals): MedalEvaluator puro (sinais -> estado das medalhas)"
```

---

### Task 3: Migração 020 + bump DB version + uninstall

**Files:**
- Create: `database/migrations/020_medal_unlocks.php`
- Modify: `guardkids.php` (linha `define('GUARDKIDS_DB_VERSION', 19);`)
- Modify: `uninstall.php` (array `$tables`, após `guardkids_mission_completions`)

> Migrações são auto-descobertas por `glob('database/migrations/*.php')` + `ksort`. Sem teste unitário de migração — a criação da tabela é validada pelo bootstrap de Integration no CI. Verificado por lint + suíte unit verde.

- [ ] **Step 1: Criar a migração**

Create `database/migrations/020_medal_unlocks.php`:

```php
<?php

declare(strict_types=1);

/**
 * Migration 020 — medalhas permanentes (gamificação 3c).
 *
 * `medal_unlocks` = ledger de desbloqueio. UNIQUE por (filho, medalha) SEM
 * data — medalha desbloqueia uma vez pra sempre.
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $table = $wpdb->prefix . 'guardkids_medal_unlocks';

    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS {$table} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id      BIGINT UNSIGNED NOT NULL,
            medal_key     VARCHAR(40) NOT NULL,
            unlocked_date DATE NOT NULL,
            xp            INT NOT NULL DEFAULT 0,
            coins         INT NOT NULL DEFAULT 0,
            created_at    DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY once_per_medal (child_id, medal_key),
            KEY child (child_id)
        ) {$charsetCollate};"
    );
};
```

- [ ] **Step 2: Bump da versão do schema**

In `guardkids.php`, change:
```php
define('GUARDKIDS_DB_VERSION', 19);
```
to:
```php
define('GUARDKIDS_DB_VERSION', 20);
```
(NÃO mexa em `GUARDKIDS_VERSION`.)

- [ ] **Step 3: Adicionar o drop no uninstall**

In `uninstall.php`, in the `$tables` array, add after the `guardkids_mission_completions` line:

```php
    $wpdb->prefix . 'guardkids_medal_unlocks',
```

- [ ] **Step 4: Lint dos arquivos PHP alterados**

Run: `"$PHP82" -l database/migrations/020_medal_unlocks.php && "$PHP82" -l uninstall.php && "$PHP82" -l guardkids.php`
Expected: `No syntax errors detected` em cada.

- [ ] **Step 5: Rodar a suíte unit inteira**

Run: `phpunit`
Expected: PASS, sem novas falhas (baseline 476 + Tasks 1,2 já commitadas).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/020_medal_unlocks.php guardkids.php uninstall.php
git commit -m "feat(medals): migração 020 medal_unlocks + bump DB v20 + uninstall"
```

---

### Task 4: MedalUnlockRepository (ledger + leitura de sinais)

**Files:**
- Create: `database/MedalUnlockRepository.php`
- Test: `tests/Unit/Database/MedalUnlockRepositoryTest.php`

- [ ] **Step 1: Escrever o teste que falha**

Create `tests/Unit/Database/MedalUnlockRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\MedalUnlockRepository;
use PHPUnit\Framework\TestCase;

final class MedalUnlockRepositoryTest extends TestCase
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
                preg_match_all('/guardkids_([a-z_]+)/', $sql, $m);
                return end($m[1]) ?: '';
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

            public function get_results($sql, $output = OBJECT)
            {
                $rows = array_values($this->t['medal_unlocks'] ?? []);
                if (preg_match('/child_id = (\d+)/', (string) $sql, $mm) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['child_id'] ?? 0) === (int) $mm[1]));
                }
                if (preg_match("/medal_key = '([^']+)'/", (string) $sql, $mm) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (string) ($r['medal_key'] ?? '') === $mm[1]));
                }
                return $rows;
            }

            public function get_var($sql, $x = 0, $y = 0)
            {
                $sql = (string) $sql;
                if (str_contains($sql, 'COUNT(*)') && str_contains($sql, 'medal_unlocks')) {
                    preg_match('/child_id = (\d+)/', $sql, $m);
                    $cid = (int) ($m[1] ?? 0);
                    return (string) count(array_filter(
                        $this->t['medal_unlocks'] ?? [],
                        static fn ($r) => (int) ($r['child_id'] ?? 0) === $cid,
                    ));
                }
                if (str_contains($sql, 'COUNT(*)') && str_contains($sql, 'progression_awards')) {
                    preg_match('/child_id = (\d+)/', $sql, $m);
                    $cid = (int) ($m[1] ?? 0);
                    return (string) count(array_filter(
                        $this->t['progression_awards'] ?? [],
                        static fn ($r) => (int) ($r['child_id'] ?? 0) === $cid,
                    ));
                }
                if (str_contains($sql, 'COUNT(*)') && str_contains($sql, 'mission_completions')) {
                    preg_match('/child_id = (\d+)/', $sql, $m);
                    $cid = (int) ($m[1] ?? 0);
                    return (string) count(array_filter(
                        $this->t['mission_completions'] ?? [],
                        static fn ($r) => (int) ($r['child_id'] ?? 0) === $cid,
                    ));
                }
                if (str_contains($sql, 'COUNT(DISTINCT')) {
                    preg_match('/a.child_id = (\d+)/', $sql, $m);
                    $cid = (int) ($m[1] ?? 0);
                    $contentIds = array_map(
                        static fn ($r) => (int) $r['content_id'],
                        array_filter(
                            $this->t['progression_awards'] ?? [],
                            static fn ($r) => (int) ($r['child_id'] ?? 0) === $cid,
                        ),
                    );
                    $cats = [];
                    foreach ($this->t['content_items'] ?? [] as $item) {
                        if (in_array((int) $item['id'], $contentIds, true) && $item['category_id'] !== null) {
                            $cats[(int) $item['category_id']] = true;
                        }
                    }
                    return (string) count($cats);
                }
                return null;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testRecordThenExistsFor(): void
    {
        $repo = new MedalUnlockRepository();
        self::assertFalse($repo->existsFor(1, 'explorer_10'));
        $repo->record(1, 'explorer_10', '2026-07-03', 30, 20);
        self::assertTrue($repo->existsFor(1, 'explorer_10'));
        self::assertFalse($repo->existsFor(1, 'devourer_50'));
    }

    public function testCountUnlocked(): void
    {
        $repo = new MedalUnlockRepository();
        $repo->record(1, 'explorer_10', '2026-07-03', 30, 20);
        $repo->record(1, 'faithful_7', '2026-07-03', 40, 25);
        $repo->record(2, 'explorer_10', '2026-07-03', 30, 20);
        self::assertSame(2, $repo->countUnlocked(1));
        self::assertSame(1, $repo->countUnlocked(2));
    }

    public function testSignalsForComputesAllTime(): void
    {
        $this->wpdb->t['progression_awards'] = [
            1 => ['id' => 1, 'child_id' => 1, 'content_id' => 10, 'award_date' => '2026-07-01'],
            2 => ['id' => 2, 'child_id' => 1, 'content_id' => 11, 'award_date' => '2026-07-03'],
        ];
        $this->wpdb->t['content_items'] = [
            1 => ['id' => 10, 'category_id' => 5],
            2 => ['id' => 11, 'category_id' => 7],
        ];
        $this->wpdb->t['mission_completions'] = [
            1 => ['id' => 1, 'child_id' => 1, 'mission_key' => 'explore_3', 'completion_date' => '2026-07-01'],
        ];

        $signals = (new MedalUnlockRepository())->signalsFor(1);
        self::assertSame(2, $signals['totalContentOpened']);
        self::assertSame(1, $signals['totalMissionsCompleted']);
        self::assertSame(2, $signals['distinctCategoriesAllTime']);
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `phpunit --filter MedalUnlockRepositoryTest`
Expected: FAIL com "Class ...MedalUnlockRepository not found".

- [ ] **Step 3: Implementar o repositório**

Create `database/MedalUnlockRepository.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Database;

/**
 * Ledger de desbloqueio de medalhas (permanente, UNIQUE por filho/medalha) e
 * leitura dos sinais acumulados que alimentam o MedalEvaluator. Só tem
 * created_at → insert próprio, sem o updated_at do base.
 */
final class MedalUnlockRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'medal_unlocks';
    }

    public function existsFor(int $childId, string $key): bool
    {
        return $this->findWhere([
            'child_id'  => $childId,
            'medal_key' => $key,
        ]) !== [];
    }

    public function record(int $childId, string $key, string $date, int $xp, int $coins): int
    {
        $ok = $this->db->insert($this->table(), [
            'child_id'      => $childId,
            'medal_key'     => $key,
            'unlocked_date' => $date,
            'xp'            => $xp,
            'coins'         => $coins,
            'created_at'    => current_time('mysql', true),
        ]);
        return $ok === false ? 0 : (int) $this->db->insert_id;
    }

    public function countUnlocked(int $childId): int
    {
        $sql = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE child_id = %d',
            $childId,
        );
        return (int) $this->db->get_var($sql);
    }

    /**
     * Sinais acumulados (all-time) derivados dos dados existentes.
     *
     * @return array{totalContentOpened:int, totalMissionsCompleted:int, distinctCategoriesAllTime:int}
     */
    public function signalsFor(int $childId): array
    {
        $awards   = $this->db->prefix . 'guardkids_progression_awards';
        $items    = $this->db->prefix . 'guardkids_content_items';
        $missions = $this->db->prefix . 'guardkids_mission_completions';

        $opened = (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$awards} WHERE child_id = %d",
            $childId,
        ));

        $missionsDone = (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$missions} WHERE child_id = %d",
            $childId,
        ));

        $categories = (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(DISTINCT c.category_id) FROM {$awards} a "
            . "JOIN {$items} c ON a.content_id = c.id "
            . "WHERE a.child_id = %d AND c.category_id IS NOT NULL",
            $childId,
        ));

        return [
            'totalContentOpened'        => $opened,
            'totalMissionsCompleted'    => $missionsDone,
            'distinctCategoriesAllTime' => $categories,
        ];
    }
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Run: `phpunit --filter MedalUnlockRepositoryTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add database/MedalUnlockRepository.php tests/Unit/Database/MedalUnlockRepositoryTest.php
git commit -m "feat(medals): MedalUnlockRepository (ledger + sinais acumulados)"
```

---

### Task 5: MedalController + rota `/child/medals`

**Files:**
- Create: `api/Controllers/MedalController.php`
- Modify: `api/RestApi.php` (import + `registerGamificationRoutes`)
- Test: `tests/Unit/Api/MedalControllerTest.php`

- [ ] **Step 1: Escrever o teste que falha**

Create `tests/Unit/Api/MedalControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\MedalController;
use GuardKids\Auth\ChildAuth;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

final class MedalControllerTest extends TestCase
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
            // sinais acumulados seedados p/ o controller
            public int $sigOpened = 0;
            public int $sigMissions = 0;
            public int $sigCategories = 0;

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
                $sql = (string) $sql;
                if (preg_match("/setting_key = '([^']+)'/", $sql, $m) === 1) {
                    if (str_contains($sql, 'SELECT id')) {
                        return isset($this->settings[$m[1]]) ? '1' : null;
                    }
                    return $this->settings[$m[1]] ?? null;
                }
                if (str_contains($sql, 'COUNT(DISTINCT')) {
                    return (string) $this->sigCategories;
                }
                if (str_contains($sql, 'COUNT(*)') && str_contains($sql, 'progression_awards')) {
                    return (string) $this->sigOpened;
                }
                if (str_contains($sql, 'COUNT(*)') && str_contains($sql, 'mission_completions')) {
                    return (string) $this->sigMissions;
                }
                return null;
            }

            private function nameOf(string $sql): string
            {
                preg_match_all('/guardkids_([a-z_]+)/', $sql, $m);
                return end($m[1]) ?: '';
            }

            public function insert($table, $data, $format = null)
            {
                if (str_contains((string) $table, 'guardkids_settings')) {
                    $this->settings[(string) $data['setting_key']] = (string) $data['value'];
                    return 1;
                }
                $n = $this->nameOf((string) $table);
                $this->t[$n] ??= [];
                $id = count($this->t[$n]) + 1;
                $this->insert_id = $id;
                $this->t[$n][$id] = array_merge(['id' => $id], $data);
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                if (str_contains((string) $table, 'guardkids_settings')) {
                    return 1;
                }
                $n = $this->nameOf((string) $table);
                $id = (int) ($where['id'] ?? 0);
                if (isset($this->t[$n][$id])) {
                    $this->t[$n][$id] = array_merge($this->t[$n][$id], $data);
                }
                return 1;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $sql = (string) $sql;
                $n = $this->nameOf($sql);
                $rows = array_values($this->t[$n] ?? []);
                if (preg_match('/child_id = (\d+)/', $sql, $mm) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['child_id'] ?? 0) === (int) $mm[1]));
                }
                if (preg_match("/medal_key = '([^']+)'/", $sql, $mm) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (string) ($r['medal_key'] ?? '') === $mm[1]));
                }
                return $rows;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->token = (new ChildAuth())->issueToken(1, 'tablet')['token'];
    }

    private function tokenReq(): WP_REST_Request
    {
        $req = new WP_REST_Request('GET', '/child/medals');
        $req->set_header('X-GuardKids-Token', $this->token);
        return $req;
    }

    /** @param array<int,array<string,mixed>> $data */
    private function medal(array $data, string $key): array
    {
        return array_values(array_filter($data, static fn ($m) => $m['key'] === $key))[0];
    }

    public function testReturns401WithoutToken(): void
    {
        $res = (new MedalController())->childMedals(new WP_REST_Request('GET', '/child/medals'));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(401, $res->get_error_data()['status']);
    }

    public function testReturnsSixMedalsWithProgress(): void
    {
        $this->wpdb->sigOpened = 5; // explorer_10 progress 5, não desbloqueia
        $data = (new MedalController())->childMedals($this->tokenReq())->get_data();
        self::assertCount(6, $data);
        self::assertSame(5, $this->medal($data, 'explorer_10')['progress']);
        self::assertFalse($this->medal($data, 'explorer_10')['unlocked']);
        self::assertArrayNotHasKey('medal_unlocks', $this->wpdb->t);
    }

    public function testUnlocksMedalOnceAndIsIdempotent(): void
    {
        // faithful_7: streak_days = 7 na carteira
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 1, 'xp' => 0, 'coins' => 0, 'streak_days' => 7, 'last_activity_date' => '2026-07-03'],
        ];

        $ctrl = new MedalController();
        $first = $ctrl->childMedals($this->tokenReq())->get_data();
        $faithful = $this->medal($first, 'faithful_7');
        self::assertTrue($faithful['unlocked']);
        self::assertTrue($faithful['justUnlocked']);

        // 1 linha no ledger, bônus creditado 1x (40 XP / 25 coins)
        self::assertCount(1, $this->wpdb->t['medal_unlocks'] ?? []);
        self::assertSame(40, (int) array_values($this->wpdb->t['progression'])[0]['xp']);
        self::assertSame(25, (int) array_values($this->wpdb->t['progression'])[0]['coins']);

        // segunda chamada não recredita
        $second = $ctrl->childMedals($this->tokenReq())->get_data();
        self::assertFalse($this->medal($second, 'faithful_7')['justUnlocked']);
        self::assertCount(1, $this->wpdb->t['medal_unlocks'] ?? []);
        self::assertSame(40, (int) array_values($this->wpdb->t['progression'])[0]['xp']);
    }

    public function testPermanenceWhenSignalDrops(): void
    {
        // medalha já no ledger, mas o sinal caiu (streak 1)
        $this->wpdb->t['medal_unlocks'] = [
            1 => ['id' => 1, 'child_id' => 1, 'medal_key' => 'faithful_7', 'unlocked_date' => '2026-07-01', 'xp' => 40, 'coins' => 25],
        ];
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 1, 'xp' => 40, 'coins' => 25, 'streak_days' => 1, 'last_activity_date' => '2026-07-03'],
        ];

        $data = (new MedalController())->childMedals($this->tokenReq())->get_data();
        $faithful = $this->medal($data, 'faithful_7');
        self::assertTrue($faithful['unlocked']);        // permanência: fica desbloqueada
        self::assertFalse($faithful['justUnlocked']);   // não recredita
        self::assertCount(1, $this->wpdb->t['medal_unlocks'] ?? []);
        self::assertSame(40, (int) array_values($this->wpdb->t['progression'])[0]['xp']);
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `phpunit --filter MedalControllerTest`
Expected: FAIL com "Class ...MedalController not found".

- [ ] **Step 3: Implementar o controller**

Create `api/Controllers/MedalController.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Auth\ChildAuth;
use GuardKids\Database\MedalUnlockRepository;
use GuardKids\Database\ProgressionRepository;
use GuardKids\Medals\MedalEvaluator;
use GuardKids\Progression\LevelCurve;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Endpoint das medalhas (fatia 3c). Calcula o estado no read e credita o
 * desbloqueio de forma preguiçosa e idempotente (ledger permanente). O
 * response reflete permanência: medalha já no ledger aparece unlocked mesmo
 * que o sinal caia depois. Crédito envolto em try/catch (nunca quebra a
 * resposta), igual ao MissionController.
 */
final class MedalController
{
    private readonly MedalUnlockRepository $unlocks;
    private readonly ProgressionRepository $wallet;
    private readonly ChildAuth $auth;

    public function __construct()
    {
        $this->unlocks = new MedalUnlockRepository();
        $this->wallet  = new ProgressionRepository();
        $this->auth    = new ChildAuth();
    }

    public function childMedals(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }

        $tz   = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $date = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');

        $row    = $this->wallet->findByChild($childId);
        $xp     = $row !== null ? (int) $row['xp'] : 0;
        $streak = $row !== null ? (int) $row['streak_days'] : 0;

        $counts  = $this->unlocks->signalsFor($childId);
        $signals = [
            'level'                     => LevelCurve::levelForXp($xp),
            'streakDays'                => $streak,
            'totalContentOpened'        => $counts['totalContentOpened'],
            'totalMissionsCompleted'    => $counts['totalMissionsCompleted'],
            'distinctCategoriesAllTime' => $counts['distinctCategoriesAllTime'],
        ];

        $medals   = MedalEvaluator::evaluate($signals);
        $catalog  = [];
        foreach (\GuardKids\Medals\MedalCatalog::all() as $c) {
            $catalog[$c['key']] = $c;
        }

        $out = [];
        foreach ($medals as $m) {
            $already       = $this->unlocks->existsFor($childId, $m['key']);
            $justUnlocked  = false;
            if ($m['unlocked'] && ! $already) {
                try {
                    $this->unlocks->record($childId, $m['key'], $date, $catalog[$m['key']]['xpReward'], $catalog[$m['key']]['coinsReward']);
                    $this->creditBonus($childId, $catalog[$m['key']]['xpReward'], $catalog[$m['key']]['coinsReward'], $date);
                    $justUnlocked = true;
                    $already      = true;
                } catch (\Throwable $e) {
                    error_log('[GuardKids] medal credit falhou: ' . $e->getMessage());
                }
            }
            $out[] = [
                'key'          => $m['key'],
                'title'        => $m['title'],
                'description'  => $m['description'],
                'icon'         => $m['icon'],
                'target'       => $m['target'],
                'progress'     => $m['progress'],
                'unlocked'     => $already || $m['unlocked'],
                'justUnlocked' => $justUnlocked,
                'xpReward'     => $catalog[$m['key']]['xpReward'],
                'coinsReward'  => $catalog[$m['key']]['coinsReward'],
            ];
        }

        return rest_ensure_response($out);
    }

    /**
     * Credita o bônus na carteira sem alterar streak/última atividade.
     */
    private function creditBonus(int $childId, int $xp, int $coins, string $date): void
    {
        $row    = $this->wallet->ensure($childId);
        $streak = (int) ($row['streak_days'] ?? 0);
        $last   = (string) ($row['last_activity_date'] ?? '');
        if ($last === '') {
            $last = $date;
        }
        $this->wallet->apply($childId, $xp, $coins, $streak, $last);
    }
}
```

- [ ] **Step 4: Registrar a rota**

In `api/RestApi.php`, add the import after `use GuardKids\Api\Controllers\MissionController;`:

```php
use GuardKids\Api\Controllers\MedalController;
```

Then in `registerGamificationRoutes()`, after the `/child/missions` block and before the closing `}`, add:

```php
        $medals = new MedalController();
        register_rest_route(self::NAMESPACE, '/child/medals', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$medals, 'childMedals'],
            'permission_callback' => (new ChildAuth())->requireToken(),
        ]);
```

- [ ] **Step 5: Rodar e confirmar que passa**

Run: `phpunit --filter MedalControllerTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add api/Controllers/MedalController.php api/RestApi.php tests/Unit/Api/MedalControllerTest.php
git commit -m "feat(medals): MedalController + rota /child/medals (desbloqueio idempotente + permanência)"
```

---

### Task 6: `medalsUnlocked` novo no GamificationController

**Files:**
- Modify: `api/Controllers/GamificationController.php`
- Modify: `tests/Unit/Api/GamificationControllerTest.php`

- [ ] **Step 1: Atualizar o teste (novo caso)**

In `tests/Unit/Api/GamificationControllerTest.php`, in the anonymous `$wpdb` class inside `setUp`, extend `get_var` to also count `medal_unlocks`. The current `get_var` (após a Task 6 da 3b) já trata `settings` e `mission_completions`. Adicione o ramo de `medal_unlocks` — o método fica assim:

```php
            public function get_var($sql, $x = 0, $y = 0)
            {
                if (preg_match("/setting_key = '([^']+)'/", (string) $sql, $m) === 1) {
                    if (str_contains((string) $sql, 'SELECT id')) {
                        return isset($this->settings[$m[1]]) ? '1' : null;
                    }
                    return $this->settings[$m[1]] ?? null;
                }
                if (str_contains((string) $sql, 'COUNT(*)') && str_contains((string) $sql, 'mission_completions')) {
                    preg_match('/child_id = (\d+)/', (string) $sql, $mc);
                    $cid = (int) ($mc[1] ?? 0);
                    return (string) count(array_filter(
                        $this->t['mission_completions'] ?? [],
                        static fn ($r) => (int) ($r['child_id'] ?? 0) === $cid,
                    ));
                }
                if (str_contains((string) $sql, 'COUNT(*)') && str_contains((string) $sql, 'medal_unlocks')) {
                    preg_match('/child_id = (\d+)/', (string) $sql, $mc);
                    $cid = (int) ($mc[1] ?? 0);
                    return (string) count(array_filter(
                        $this->t['medal_unlocks'] ?? [],
                        static fn ($r) => (int) ($r['child_id'] ?? 0) === $cid,
                    ));
                }
                return null;
            }
```

Then add a new test method:

```php
    public function testParentProgressionCountsUnlockedMedals(): void
    {
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 5, 'xp' => 150, 'coins' => 20, 'streak_days' => 3, 'last_activity_date' => '2026-07-02'],
        ];
        $this->wpdb->t['medal_unlocks'] = [
            1 => ['id' => 1, 'child_id' => 5, 'medal_key' => 'explorer_10', 'unlocked_date' => '2026-07-02'],
            2 => ['id' => 2, 'child_id' => 5, 'medal_key' => 'faithful_7', 'unlocked_date' => '2026-07-02'],
            3 => ['id' => 3, 'child_id' => 9, 'medal_key' => 'explorer_10', 'unlocked_date' => '2026-07-02'],
        ];
        $req = new WP_REST_Request('GET', '/progression');
        $req->set_param('child_id', 5);
        $data = (new GamificationController())->progression($req)->get_data();
        self::assertSame(2, $data['medalsUnlocked']);
    }
```

> `testParentProgressionReflectsWallet` continua válido: sem `medal_unlocks` seedado o COUNT devolve 0, e (após o Step 3) o payload terá `medalsUnlocked => 0` sem quebrar nada.

- [ ] **Step 2: Rodar e confirmar que o novo teste falha**

Run: `phpunit --filter GamificationControllerTest`
Expected: FAIL em `testParentProgressionCountsUnlockedMedals` (o campo `medalsUnlocked` ainda não existe no payload → undefined key / valor errado).

- [ ] **Step 3: Ligar o contador no controller**

In `api/Controllers/GamificationController.php`:

Add the import after `use GuardKids\Database\MissionCompletionRepository;`:

```php
use GuardKids\Database\MedalUnlockRepository;
```

Add the property after `private readonly MissionCompletionRepository $missions;`:

```php
    private readonly MedalUnlockRepository $medals;
```

In the constructor, after `$this->missions = new MissionCompletionRepository();`, add:

```php
        $this->medals = new MedalUnlockRepository();
```

In `progression()`, after the `'missionsCompleted' => $this->missions->countCompleted($childId),` line, add:

```php
            'medalsUnlocked'    => $this->medals->countUnlocked($childId),
```

- [ ] **Step 4: Rodar e confirmar que passa**

Run: `phpunit --filter GamificationControllerTest`
Expected: PASS (todos os testes da classe, incluindo o novo).

- [ ] **Step 5: Commit**

```bash
git add api/Controllers/GamificationController.php tests/Unit/Api/GamificationControllerTest.php
git commit -m "feat(medals): medalsUnlocked no endpoint dos pais"
```

---

### Task 7: Front app-filho — api + MedalsCard + Home

**Files:**
- Modify: `public/app-child/src/api/gamification.ts`
- Create: `public/app-child/src/components/MedalsCard.tsx`
- Create: `public/app-child/src/components/MedalsCard.test.tsx`
- Modify: `public/app-child/src/pages/Home.tsx`

- [ ] **Step 1: Adicionar tipo + fetch na api**

In `public/app-child/src/api/gamification.ts`, append:

```ts
export type Medal = {
  key: string;
  title: string;
  description: string;
  icon: string;
  target: number;
  progress: number;
  unlocked: boolean;
  justUnlocked: boolean;
  xpReward: number;
  coinsReward: number;
};

export function getMedals(): Promise<Medal[]> {
  return apiFetch<Medal[]>('/child/medals');
}
```

- [ ] **Step 2: Escrever o teste do componente que falha**

Create `public/app-child/src/components/MedalsCard.test.tsx`:

```tsx
import { screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { MedalsCard } from './MedalsCard';

const getMedals = vi.fn();
vi.mock('../api/gamification', () => ({ getMedals: () => getMedals() }));

const medals = [
  { key: 'explorer_10', title: 'Explorador', description: 'Abriu 10 conteúdos', icon: 'explore', target: 10, progress: 10, unlocked: true, justUnlocked: false, xpReward: 30, coinsReward: 20 },
  { key: 'devourer_50', title: 'Devorador', description: 'Abriu 50 conteúdos', icon: 'auto_stories', target: 50, progress: 12, unlocked: false, justUnlocked: false, xpReward: 60, coinsReward: 40 },
  { key: 'achiever_10', title: 'Cumpridor', description: 'Completou 10 missões', icon: 'task_alt', target: 10, progress: 3, unlocked: false, justUnlocked: false, xpReward: 40, coinsReward: 25 },
  { key: 'faithful_7', title: 'Fiel', description: '7 dias de sequência', icon: 'local_fire_department', target: 7, progress: 7, unlocked: true, justUnlocked: false, xpReward: 40, coinsReward: 25 },
  { key: 'veteran_10', title: 'Veterano', description: 'Alcançou o nível 10', icon: 'military_tech', target: 10, progress: 2, unlocked: false, justUnlocked: false, xpReward: 50, coinsReward: 30 },
  { key: 'curious_master_5', title: 'Curioso Master', description: 'Explorou 5 categorias', icon: 'category', target: 5, progress: 5, unlocked: true, justUnlocked: false, xpReward: 40, coinsReward: 25 },
];

describe('MedalsCard', () => {
  afterEach(() => getMedals.mockReset());

  it('mostra o contador de desbloqueadas X/6', async () => {
    getMedals.mockResolvedValueOnce(medals);
    renderWithClient(<MedalsCard />);
    expect(await screen.findByText('3/6')).toBeInTheDocument();
  });

  it('marca desbloqueadas e mostra progresso das bloqueadas', async () => {
    getMedals.mockResolvedValueOnce(medals);
    renderWithClient(<MedalsCard />);
    expect(await screen.findByTestId('medal-unlocked-explorer_10')).toBeInTheDocument();
    expect(screen.getByTestId('medal-locked-devourer_50')).toBeInTheDocument();
    expect(screen.getByText('12/50')).toBeInTheDocument();
  });

  it('não renderiza nada quando não há medalhas', async () => {
    getMedals.mockResolvedValueOnce([]);
    renderWithClient(<MedalsCard />);
    await screen.findByTestId('medals-empty');
    expect(screen.queryByTestId('medal-tile')).toBeNull();
  });
});
```

- [ ] **Step 3: Rodar e confirmar que falha**

Run: `cd public/app-child && npx vitest run src/components/MedalsCard.test.tsx`
Expected: FAIL — não encontra `./MedalsCard`.

- [ ] **Step 4: Implementar o MedalsCard**

Create `public/app-child/src/components/MedalsCard.tsx`:

```tsx
import { useQuery } from '@tanstack/react-query';
import { getMedals } from '../api/gamification';
import { Icon } from './Icon';

export function MedalsCard() {
  const query = useQuery({ queryKey: ['child', 'medals'], queryFn: getMedals });

  if (query.isLoading) {
    return <div className="h-40 animate-pulse rounded-2xl bg-surface-container-low" />;
  }

  const medals = query.data ?? [];
  if (medals.length === 0) {
    return <div data-testid="medals-empty" className="hidden" />;
  }

  const unlockedCount = medals.filter((m) => m.unlocked).length;

  return (
    <div className="rounded-2xl bg-surface-container p-4 shadow-sm">
      <div className="mb-3 flex items-center justify-between">
        <div className="flex items-center gap-2">
          <Icon name="military_tech" className="text-xl text-primary" filled />
          <h3 className="font-display text-label-md font-bold text-on-surface">Minhas Medalhas</h3>
        </div>
        <span className="text-label-sm font-bold text-on-surface-variant">
          {unlockedCount}/{medals.length}
        </span>
      </div>
      <ul className="grid grid-cols-3 gap-3">
        {medals.map((m) => (
          <li
            key={m.key}
            data-testid="medal-tile"
            className="flex flex-col items-center gap-1 text-center"
          >
            <div
              data-testid={m.unlocked ? `medal-unlocked-${m.key}` : `medal-locked-${m.key}`}
              className={`flex h-14 w-14 items-center justify-center rounded-full ${
                m.unlocked
                  ? 'bg-primary text-white shadow-sm'
                  : 'bg-surface-variant text-on-surface-variant opacity-50'
              }`}
            >
              <Icon name={m.icon} className="text-2xl" filled />
            </div>
            <span className="text-label-sm text-on-surface">{m.title}</span>
            {m.unlocked ? (
              <span className="text-label-sm font-bold text-primary">
                {m.justUnlocked ? `+${m.xpReward} XP` : 'Conquistada'}
              </span>
            ) : (
              <span className="text-label-sm text-on-surface-variant">
                {m.progress}/{m.target}
              </span>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}
```

- [ ] **Step 5: Rodar e confirmar que passa**

Run: `cd public/app-child && npx vitest run src/components/MedalsCard.test.tsx`
Expected: PASS (3 tests).

- [ ] **Step 6: Renderizar na Home (abaixo do MissionsCard)**

In `public/app-child/src/pages/Home.tsx`:

Add the import next to the MissionsCard import:

```tsx
import { MedalsCard } from '../components/MedalsCard';
```

Then in the JSX, immediately after `<MissionsCard />`, add:

```tsx
      <MedalsCard />
```

- [ ] **Step 7: Rodar a suíte vitest do app-filho inteira + typecheck + build**

Run: `cd public/app-child && npx vitest run && npx tsc --noEmit && npx vite build`
Expected: todos PASS (baseline 103 + 3 novos = 106); tsc sem erros; build sucesso.

- [ ] **Step 8: Commit**

```bash
git add public/app-child/src/api/gamification.ts public/app-child/src/components/MedalsCard.tsx public/app-child/src/components/MedalsCard.test.tsx public/app-child/src/pages/Home.tsx
git commit -m "feat(medals): MedalsCard no app-filho (galeria de medalhas na Home)"
```

---

### Task 8: Front app-pais — tipo + métrica no GamificationDashboard

**Files:**
- Modify: `public/app-parent/src/api/gamification.ts`
- Modify: `public/app-parent/src/pages/GamificationDashboard.tsx`
- Modify: `public/app-parent/src/pages/GamificationDashboard.test.tsx`

- [ ] **Step 1: Atualizar o teste (novo assert da métrica)**

In `public/app-parent/src/pages/GamificationDashboard.test.tsx`, update the first test's mock to include `medalsUnlocked` and assert the metric renders. Replace the test `'mostra um card por filho com nível e coins'` with:

```tsx
  it('mostra um card por filho com nível, coins e medalhas', async () => {
    listChildren.mockResolvedValueOnce([{ id: 5, name: 'Lucas' }]);
    getChildProgression.mockResolvedValue({ xp: 150, coins: 20, level: 2, streakDays: 3, missionsCompleted: 0, medalsUnlocked: 4 });
    renderWithClient(<GamificationDashboard />);
    expect(await screen.findByText('Lucas')).toBeInTheDocument();
    expect(await screen.findByText(/nível 2/i)).toBeInTheDocument();
    expect(await screen.findByText('Medalhas')).toBeInTheDocument();
    expect(await screen.findByText('4')).toBeInTheDocument();
  });
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `cd public/app-parent && npx vitest run src/pages/GamificationDashboard.test.tsx`
Expected: FAIL — não encontra o texto "Medalhas" (a métrica ainda não existe).

- [ ] **Step 3: Adicionar `medalsUnlocked` ao tipo**

In `public/app-parent/src/api/gamification.ts`, add `medalsUnlocked: number;` to the `ChildProgression` type (after `missionsCompleted: number;`):

```ts
export type ChildProgression = {
  xp: number;
  coins: number;
  level: number;
  streakDays: number;
  missionsCompleted: number;
  medalsUnlocked: number;
};
```

- [ ] **Step 4: Adicionar a métrica ao dashboard**

In `public/app-parent/src/pages/GamificationDashboard.tsx`, add a metric to the `metrics` array (after the "Missões concluídas" entry):

```tsx
    { label: 'Medalhas', value: p?.medalsUnlocked ?? 0 },
```

- [ ] **Step 5: Rodar e confirmar que passa**

Run: `cd public/app-parent && npx vitest run src/pages/GamificationDashboard.test.tsx`
Expected: PASS.

- [ ] **Step 6: Rodar a suíte vitest do app-pais inteira + typecheck + build**

Run: `cd public/app-parent && npx vitest run && npx tsc --noEmit && npx vite build`
Expected: todos PASS (baseline 302, sem regressão); tsc sem erros; build sucesso.

- [ ] **Step 7: Commit**

```bash
git add public/app-parent/src/api/gamification.ts public/app-parent/src/pages/GamificationDashboard.tsx public/app-parent/src/pages/GamificationDashboard.test.tsx
git commit -m "feat(medals): métrica Medalhas no painel dos pais"
```

---

### Task 9: Verificação final da suíte completa

- [ ] **Step 1: Suíte PHP unit inteira**

Run: `phpunit`
Expected: PASS — total = baseline 476 + novos testes das Tasks 1,2,4,5,6. Zero falhas.

- [ ] **Step 2: Vitest app-filho + app-pais**

Run: `cd public/app-child && npx vitest run` e `cd public/app-parent && npx vitest run`
Expected: ambos PASS (app-child = baseline 103 + 3 = 106; app-parent = baseline 302 + 0 novos casos líquidos, mesmo total de arquivos).

- [ ] **Step 3: Confirmar árvore limpa e histórico**

Run: `git status -sb && git log --oneline -9`
Expected: working tree limpo; commits das Tasks 1–8 presentes.

> **Fora do escopo deste plano** (feito na sessão, não pelo executor): abrir PR, merge squash, release v1.31.0 (bump `GUARDKIDS_VERSION`), deploy SSH (`wp plugin install --force`; migração idempotente), e atualizar o índice de memória do projeto.

---

## Notas de verificação do plano vs. spec

- **Migração 020 + DB v20 + uninstall** → Task 3. ✅
- **`medal_unlocks` (UNIQUE `child_id+medal_key`, permanente)** → Task 3 (schema) + Task 4 (repo). ✅
- **`MedalCatalog` puro (6 medalhas, campo `signal`)** → Task 1. ✅
- **`MedalEvaluator` puro (mapeia por `signal`, clamp)** → Task 2. ✅
- **Leitura de sinais acumulados (opens/missões/categorias all-time)** → Task 4 (`signalsFor`). ✅
- **`MedalController` + `/child/medals` + crédito preguiçoso idempotente + permanência no response** → Task 5. ✅
- **`medalsUnlocked` novo no endpoint dos pais** → Task 6. ✅
- **`MedalsCard` (galeria) no app-filho + Home** → Task 7. ✅
- **app-pais tipo + métrica "Medalhas"** → Task 8. ✅
- **Testes: catalog/evaluator/repo/controller/gamification + vitest ambos apps** → Tasks 1,2,4,5,6,7,8. ✅
- **Sem cron; só `medalsUnlocked` + métrica dos pais tocam código existente; nada no `childHistory`** → respeitado. ✅
