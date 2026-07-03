# Sprint 3b — Missões Diárias — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Adicionar missões diárias automáticas ao guardkids-wp: 3 metas do dia que a criança persegue, com bônus de conclusão único creditado na carteira da gamificação (3a).

**Architecture:** Peças puras (`MissionCatalog` + `MissionEvaluator`) definem e avaliam as missões a partir de sinais já existentes (conteúdos abertos hoje, categorias hoje, streak). Um `MissionController` calcula no read e credita a conclusão de forma preguiçosa e idempotente, gravando num ledger anti-duplo (`mission_completions`, migração 019). Aditivo: só a linha do `missionsCompleted` no `GamificationController` toca código existente; nada no caminho quente do `childHistory`.

**Tech Stack:** PHP 8.2 (WordPress, `$wpdb`, PSR-4 self-contained), PHPUnit 9.6, React/TS/Vite/Tailwind + TanStack Query, Vitest.

**Spec:** `docs/superpowers/specs/2026-07-03-gamificacao-sprint3b-missoes-design.md`

---

## Convenções de comando (usadas nos passos)

**Rodar testes PHP** (PHP 8.2 do LocalWP + extensões + openssl.cnf p/ Ed25519/sodium). Defina uma vez no shell antes dos passos PHP:

```bash
export PHP82="/c/Users/mysho/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe"
export PHPEXT="C:\\Users\\mysho\\AppData\\Roaming\\Local\\lightning-services\\php-8.2.29+0\\bin\\win64\\ext"
printf '[req]\ndefault_bits=2048\ndistinguished_name=req_dn\n[req_dn]\n' > /tmp/openssl.cnf
export OPENSSL_CONF="$(cygpath -w /tmp/openssl.cnf)"
# helper: roda uma classe de teste
phpunit() { "$PHP82" -n -d extension_dir="$PHPEXT" -d extension=mbstring -d extension=openssl -d extension=sodium -d memory_limit=512M vendor/bin/phpunit -c phpunit.xml.dist --no-coverage "$@"; }
```

Depois: `phpunit --filter MissionCatalogTest` etc.

**Rodar testes do front:** `cd public/app-child && npx vitest run <arquivo>` (idem `app-parent`).

Todos os comandos assumem cwd = raiz do repo `C:/Users/mysho/guardkids-wp`.

---

## File Structure

**Criar:**
- `includes/Missions/MissionCatalog.php` — as 3 missões como constantes (puro).
- `includes/Missions/MissionEvaluator.php` — avalia sinais → estado das missões (puro).
- `database/MissionCompletionRepository.php` — ledger de conclusão + leitura dos sinais.
- `database/migrations/019_daily_missions.php` — tabela `mission_completions`.
- `api/Controllers/MissionController.php` — endpoint `/child/missions` (crédito preguiçoso idempotente).
- `tests/Unit/Missions/MissionCatalogTest.php`
- `tests/Unit/Missions/MissionEvaluatorTest.php`
- `tests/Unit/Database/MissionCompletionRepositoryTest.php`
- `tests/Unit/Api/MissionControllerTest.php`
- `public/app-child/src/components/MissionsCard.tsx`
- `public/app-child/src/components/MissionsCard.test.tsx`

**Modificar:**
- `guardkids.php:22` — `GUARDKIDS_DB_VERSION` 18 → 19.
- `uninstall.php` — adicionar `mission_completions` ao drop.
- `api/RestApi.php` — registrar `/child/missions`.
- `api/Controllers/GamificationController.php` — `missionsCompleted` real.
- `tests/Unit/Api/GamificationControllerTest.php` — cobrir `missionsCompleted` != 0.
- `public/app-child/src/api/gamification.ts` — `type Mission` + `getMissions()`.
- `public/app-child/src/pages/Home.tsx` — renderizar `<MissionsCard />`.

**Sem mudança** (já pronto): `public/app-parent/` — `GamificationDashboard.tsx` já exibe "Missões concluídas" e `api/gamification.ts` já tem `missionsCompleted: number`. Só depende do backend devolver o número real (Task 6).

---

### Task 1: MissionCatalog (definição pura das 3 missões)

**Files:**
- Create: `includes/Missions/MissionCatalog.php`
- Test: `tests/Unit/Missions/MissionCatalogTest.php`

- [ ] **Step 1: Escrever o teste que falha**

Create `tests/Unit/Missions/MissionCatalogTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Missions;

use GuardKids\Missions\MissionCatalog;
use PHPUnit\Framework\TestCase;

final class MissionCatalogTest extends TestCase
{
    public function testHasThreeCuratedMissions(): void
    {
        $keys = array_column(MissionCatalog::all(), 'key');
        self::assertSame(['explore_3', 'categories_2', 'streak_today'], $keys);
    }

    public function testEachMissionHasTargetAndRewards(): void
    {
        foreach (MissionCatalog::all() as $m) {
            self::assertArrayHasKey('title', $m);
            self::assertArrayHasKey('description', $m);
            self::assertArrayHasKey('icon', $m);
            self::assertGreaterThan(0, $m['target']);
            self::assertGreaterThan(0, $m['xpReward']);
            self::assertGreaterThan(0, $m['coinsReward']);
        }
    }

    public function testTargetsMatchCatalog(): void
    {
        $byKey = array_column(MissionCatalog::all(), 'target', 'key');
        self::assertSame(3, $byKey['explore_3']);
        self::assertSame(2, $byKey['categories_2']);
        self::assertSame(1, $byKey['streak_today']);
    }
}
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `phpunit --filter MissionCatalogTest`
Expected: FAIL com "Class MissionCatalog not found".

- [ ] **Step 3: Implementar o mínimo**

Create `includes/Missions/MissionCatalog.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Missions;

/**
 * Catálogo das missões diárias (puro, sem $wpdb). As definições vivem no
 * código — não há tabela de definição. Ajuste alvos/bônus só aqui.
 */
final class MissionCatalog
{
    /**
     * @return array<int, array{key:string, title:string, description:string, icon:string, target:int, xpReward:int, coinsReward:int}>
     */
    public static function all(): array
    {
        return [
            [
                'key'         => 'explore_3',
                'title'       => 'Explorador do dia',
                'description' => 'Abra 3 conteúdos hoje',
                'icon'        => 'explore',
                'target'      => 3,
                'xpReward'    => 15,
                'coinsReward' => 10,
            ],
            [
                'key'         => 'categories_2',
                'title'       => 'Curioso',
                'description' => 'Explore 2 categorias diferentes hoje',
                'icon'        => 'category',
                'target'      => 2,
                'xpReward'    => 15,
                'coinsReward' => 10,
            ],
            [
                'key'         => 'streak_today',
                'title'       => 'Presença',
                'description' => 'Volte e mantenha sua sequência hoje',
                'icon'        => 'local_fire_department',
                'target'      => 1,
                'xpReward'    => 10,
                'coinsReward' => 5,
            ],
        ];
    }
}
```

- [ ] **Step 4: Rodar o teste e confirmar que passa**

Run: `phpunit --filter MissionCatalogTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/Missions/MissionCatalog.php tests/Unit/Missions/MissionCatalogTest.php
git commit -m "feat(missions): MissionCatalog com as 3 missões diárias curadas"
```

---

### Task 2: MissionEvaluator (avaliação pura dos sinais)

**Files:**
- Create: `includes/Missions/MissionEvaluator.php`
- Test: `tests/Unit/Missions/MissionEvaluatorTest.php`

- [ ] **Step 1: Escrever o teste que falha**

Create `tests/Unit/Missions/MissionEvaluatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Missions;

use GuardKids\Missions\MissionEvaluator;
use PHPUnit\Framework\TestCase;

final class MissionEvaluatorTest extends TestCase
{
    /** @param array{contentOpenedToday:int,categoriesToday:int,streakActiveToday:bool} $signals */
    private function progressOf(array $signals, string $key): array
    {
        foreach (MissionEvaluator::evaluate($signals) as $m) {
            if ($m['key'] === $key) {
                return $m;
            }
        }
        self::fail("mission {$key} not found");
    }

    public function testAllZeroWhenNothingDone(): void
    {
        $signals = ['contentOpenedToday' => 0, 'categoriesToday' => 0, 'streakActiveToday' => false];
        foreach (MissionEvaluator::evaluate($signals) as $m) {
            self::assertSame(0, $m['progress']);
            self::assertFalse($m['completed']);
        }
    }

    public function testPartialProgressNotCompleted(): void
    {
        $signals = ['contentOpenedToday' => 2, 'categoriesToday' => 1, 'streakActiveToday' => false];
        self::assertSame(2, $this->progressOf($signals, 'explore_3')['progress']);
        self::assertFalse($this->progressOf($signals, 'explore_3')['completed']);
    }

    public function testExactTargetCompletes(): void
    {
        $signals = ['contentOpenedToday' => 3, 'categoriesToday' => 2, 'streakActiveToday' => true];
        foreach (MissionEvaluator::evaluate($signals) as $m) {
            self::assertTrue($m['completed'], $m['key']);
        }
    }

    public function testProgressClampedToTarget(): void
    {
        $signals = ['contentOpenedToday' => 10, 'categoriesToday' => 9, 'streakActiveToday' => true];
        self::assertSame(3, $this->progressOf($signals, 'explore_3')['progress']);
        self::assertSame(2, $this->progressOf($signals, 'categories_2')['progress']);
        self::assertSame(1, $this->progressOf($signals, 'streak_today')['progress']);
    }

    public function testStreakBooleanMapsToProgress(): void
    {
        $on  = ['contentOpenedToday' => 0, 'categoriesToday' => 0, 'streakActiveToday' => true];
        $off = ['contentOpenedToday' => 0, 'categoriesToday' => 0, 'streakActiveToday' => false];
        self::assertTrue($this->progressOf($on, 'streak_today')['completed']);
        self::assertFalse($this->progressOf($off, 'streak_today')['completed']);
    }
}
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `phpunit --filter MissionEvaluatorTest`
Expected: FAIL com "Class MissionEvaluator not found".

- [ ] **Step 3: Implementar o mínimo**

Create `includes/Missions/MissionEvaluator.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Missions;

/**
 * Avaliação pura: recebe os sinais já computados e devolve o estado de cada
 * missão (progress clampado ao alvo + completed). Não toca no banco.
 */
final class MissionEvaluator
{
    /**
     * @param array{contentOpenedToday:int, categoriesToday:int, streakActiveToday:bool} $signals
     * @return array<int, array{key:string, title:string, description:string, icon:string, target:int, progress:int, completed:bool, xpReward:int, coinsReward:int}>
     */
    public static function evaluate(array $signals): array
    {
        $out = [];
        foreach (MissionCatalog::all() as $m) {
            $raw = match ($m['key']) {
                'explore_3'    => $signals['contentOpenedToday'],
                'categories_2' => $signals['categoriesToday'],
                'streak_today' => $signals['streakActiveToday'] ? 1 : 0,
                default        => 0,
            };
            $progress = min((int) $raw, $m['target']);
            $out[] = [
                'key'         => $m['key'],
                'title'       => $m['title'],
                'description' => $m['description'],
                'icon'        => $m['icon'],
                'target'      => $m['target'],
                'progress'    => $progress,
                'completed'   => $progress >= $m['target'],
                'xpReward'    => $m['xpReward'],
                'coinsReward' => $m['coinsReward'],
            ];
        }
        return $out;
    }
}
```

- [ ] **Step 4: Rodar o teste e confirmar que passa**

Run: `phpunit --filter MissionEvaluatorTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/Missions/MissionEvaluator.php tests/Unit/Missions/MissionEvaluatorTest.php
git commit -m "feat(missions): MissionEvaluator puro (sinais -> estado das missões)"
```

---

### Task 3: Migração 019 + bump DB version + uninstall

**Files:**
- Create: `database/migrations/019_daily_missions.php`
- Modify: `guardkids.php:22`
- Modify: `uninstall.php:35` (após a linha de `progression_awards`)

> Migrações são auto-descobertas por `glob('database/migrations/*.php')` + `ksort`. Não há teste unitário de migração — a criação da tabela é validada pelo bootstrap de Integration no CI (roda todas as migrações contra MySQL real). Este task é verificado por lint + suíte unit verde.

- [ ] **Step 1: Criar a migração**

Create `database/migrations/019_daily_missions.php`:

```php
<?php

declare(strict_types=1);

/**
 * Migration 019 — missões diárias (gamificação 3b).
 *
 * `mission_completions` = ledger anti-duplo de conclusão. Uma linha por
 * (filho, missão, dia) via UNIQUE — impede creditar o bônus duas vezes.
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $table = $wpdb->prefix . 'guardkids_mission_completions';

    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS {$table} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id        BIGINT UNSIGNED NOT NULL,
            mission_key     VARCHAR(40) NOT NULL,
            completion_date DATE NOT NULL,
            xp              INT NOT NULL DEFAULT 0,
            coins           INT NOT NULL DEFAULT 0,
            created_at      DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY once_per_day (child_id, mission_key, completion_date),
            KEY child (child_id)
        ) {$charsetCollate};"
    );
};
```

- [ ] **Step 2: Bump da versão do schema**

In `guardkids.php`, line 22, change:

```php
define('GUARDKIDS_DB_VERSION', 18);
```

to:

```php
define('GUARDKIDS_DB_VERSION', 19);
```

- [ ] **Step 3: Adicionar o drop no uninstall**

In `uninstall.php`, in the `$tables` array, add after the `guardkids_progression_awards` line:

```php
    $wpdb->prefix . 'guardkids_mission_completions',
```

- [ ] **Step 4: Lint dos arquivos PHP alterados**

Run: `"$PHP82" -l database/migrations/019_daily_missions.php && "$PHP82" -l uninstall.php && "$PHP82" -l guardkids.php`
Expected: `No syntax errors detected` em cada um.

- [ ] **Step 5: Rodar a suíte unit inteira (garantir nenhuma regressão)**

Run: `phpunit`
Expected: PASS (todos verdes; sem novas falhas vs. baseline).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/019_daily_missions.php guardkids.php uninstall.php
git commit -m "feat(missions): migração 019 mission_completions + bump DB v19 + uninstall"
```

---

### Task 4: MissionCompletionRepository (ledger + leitura de sinais)

**Files:**
- Create: `database/MissionCompletionRepository.php`
- Test: `tests/Unit/Database/MissionCompletionRepositoryTest.php`

- [ ] **Step 1: Escrever o teste que falha**

Create `tests/Unit/Database/MissionCompletionRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\MissionCompletionRepository;
use PHPUnit\Framework\TestCase;

final class MissionCompletionRepositoryTest extends TestCase
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
                // pega a última tabela guardkids_ citada (cobre JOINs)
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
                $rows = array_values($this->t['mission_completions'] ?? []);
                foreach (['child_id'] as $col) {
                    if (preg_match("/{$col} = (\d+)/", (string) $sql, $mm) === 1) {
                        $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r[$col] ?? 0) === (int) $mm[1]));
                    }
                }
                if (preg_match("/mission_key = '([^']+)'/", (string) $sql, $mm) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (string) ($r['mission_key'] ?? '') === $mm[1]));
                }
                if (preg_match("/completion_date = '([^']+)'/", (string) $sql, $mm) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (string) ($r['completion_date'] ?? '') === $mm[1]));
                }
                return $rows;
            }

            public function get_var($sql, $x = 0, $y = 0)
            {
                $sql = (string) $sql;
                // COUNT(*) de mission_completions por filho
                if (str_contains($sql, 'COUNT(*)') && str_contains($sql, 'mission_completions')) {
                    preg_match('/child_id = (\d+)/', $sql, $m);
                    $cid = (int) ($m[1] ?? 0);
                    return (string) count(array_filter(
                        $this->t['mission_completions'] ?? [],
                        static fn ($r) => (int) ($r['child_id'] ?? 0) === $cid,
                    ));
                }
                // COUNT(*) de conteúdos abertos hoje
                if (str_contains($sql, 'COUNT(*)') && str_contains($sql, 'progression_awards')) {
                    preg_match('/child_id = (\d+)/', $sql, $mc);
                    preg_match("/award_date = '([^']+)'/", $sql, $md);
                    $cid = (int) ($mc[1] ?? 0);
                    $date = $md[1] ?? '';
                    return (string) count(array_filter(
                        $this->t['progression_awards'] ?? [],
                        static fn ($r) => (int) ($r['child_id'] ?? 0) === $cid && (string) ($r['award_date'] ?? '') === $date,
                    ));
                }
                // COUNT(DISTINCT category) via JOIN awards x content_items
                if (str_contains($sql, 'COUNT(DISTINCT')) {
                    preg_match('/a.child_id = (\d+)/', $sql, $mc);
                    preg_match("/a.award_date = '([^']+)'/", $sql, $md);
                    $cid = (int) ($mc[1] ?? 0);
                    $date = $md[1] ?? '';
                    $contentIds = array_map(
                        static fn ($r) => (int) $r['content_id'],
                        array_filter(
                            $this->t['progression_awards'] ?? [],
                            static fn ($r) => (int) ($r['child_id'] ?? 0) === $cid && (string) ($r['award_date'] ?? '') === $date,
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
                // last_activity_date da carteira
                if (str_contains($sql, 'last_activity_date')) {
                    preg_match('/child_id = (\d+)/', $sql, $m);
                    $cid = (int) ($m[1] ?? 0);
                    foreach ($this->t['progression'] ?? [] as $r) {
                        if ((int) ($r['child_id'] ?? 0) === $cid) {
                            return $r['last_activity_date'] ?? null;
                        }
                    }
                    return null;
                }
                return null;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testRecordThenExistsFor(): void
    {
        $repo = new MissionCompletionRepository();
        self::assertFalse($repo->existsFor(1, 'explore_3', '2026-07-03'));
        $repo->record(1, 'explore_3', '2026-07-03', 15, 10);
        self::assertTrue($repo->existsFor(1, 'explore_3', '2026-07-03'));
        self::assertFalse($repo->existsFor(1, 'explore_3', '2026-07-04'));
    }

    public function testCountCompleted(): void
    {
        $repo = new MissionCompletionRepository();
        $repo->record(1, 'explore_3', '2026-07-03', 15, 10);
        $repo->record(1, 'streak_today', '2026-07-03', 10, 5);
        $repo->record(2, 'explore_3', '2026-07-03', 15, 10);
        self::assertSame(2, $repo->countCompleted(1));
        self::assertSame(1, $repo->countCompleted(2));
    }

    public function testSignalsForComputesFromExistingData(): void
    {
        $this->wpdb->t['progression_awards'] = [
            1 => ['id' => 1, 'child_id' => 1, 'content_id' => 10, 'award_date' => '2026-07-03'],
            2 => ['id' => 2, 'child_id' => 1, 'content_id' => 11, 'award_date' => '2026-07-03'],
        ];
        $this->wpdb->t['content_items'] = [
            1 => ['id' => 10, 'category_id' => 5],
            2 => ['id' => 11, 'category_id' => 7],
        ];
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 1, 'last_activity_date' => '2026-07-03'],
        ];

        $signals = (new MissionCompletionRepository())->signalsFor(1, '2026-07-03');
        self::assertSame(2, $signals['contentOpenedToday']);
        self::assertSame(2, $signals['categoriesToday']);
        self::assertTrue($signals['streakActiveToday']);
    }

    public function testStreakInactiveWhenLastDateIsNotToday(): void
    {
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 1, 'last_activity_date' => '2026-07-02'],
        ];
        $signals = (new MissionCompletionRepository())->signalsFor(1, '2026-07-03');
        self::assertFalse($signals['streakActiveToday']);
        self::assertSame(0, $signals['contentOpenedToday']);
    }
}
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `phpunit --filter MissionCompletionRepositoryTest`
Expected: FAIL com "Class ...MissionCompletionRepository not found".

- [ ] **Step 3: Implementar o repositório**

Create `database/MissionCompletionRepository.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Database;

/**
 * Ledger de conclusão de missões (anti-duplo, UNIQUE por filho/missão/dia) e
 * leitura dos sinais que alimentam o MissionEvaluator. Só tem created_at →
 * insert próprio, sem o updated_at do base.
 */
final class MissionCompletionRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'mission_completions';
    }

    public function existsFor(int $childId, string $key, string $date): bool
    {
        return $this->findWhere([
            'child_id'        => $childId,
            'mission_key'     => $key,
            'completion_date' => $date,
        ]) !== [];
    }

    public function record(int $childId, string $key, string $date, int $xp, int $coins): int
    {
        $ok = $this->db->insert($this->table(), [
            'child_id'        => $childId,
            'mission_key'     => $key,
            'completion_date' => $date,
            'xp'              => $xp,
            'coins'           => $coins,
            'created_at'      => current_time('mysql', true),
        ]);
        return $ok === false ? 0 : (int) $this->db->insert_id;
    }

    public function countCompleted(int $childId): int
    {
        $sql = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE child_id = %d',
            $childId,
        );
        return (int) $this->db->get_var($sql);
    }

    /**
     * Sinais do dia derivados dos dados existentes (progression_awards,
     * content_items, progression). Barato: awards já é 1 linha/conteúdo/dia.
     *
     * @return array{contentOpenedToday:int, categoriesToday:int, streakActiveToday:bool}
     */
    public function signalsFor(int $childId, string $date): array
    {
        $awards = $this->db->prefix . 'guardkids_progression_awards';
        $items  = $this->db->prefix . 'guardkids_content_items';
        $prog   = $this->db->prefix . 'guardkids_progression';

        $opened = (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$awards} WHERE child_id = %d AND award_date = %s",
            $childId,
            $date,
        ));

        $categories = (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(DISTINCT c.category_id) FROM {$awards} a "
            . "JOIN {$items} c ON a.content_id = c.id "
            . "WHERE a.child_id = %d AND a.award_date = %s AND c.category_id IS NOT NULL",
            $childId,
            $date,
        ));

        $lastDate = $this->db->get_var($this->db->prepare(
            "SELECT last_activity_date FROM {$prog} WHERE child_id = %d LIMIT 1",
            $childId,
        ));

        return [
            'contentOpenedToday' => $opened,
            'categoriesToday'    => $categories,
            'streakActiveToday'  => $lastDate === $date,
        ];
    }
}
```

- [ ] **Step 4: Rodar o teste e confirmar que passa**

Run: `phpunit --filter MissionCompletionRepositoryTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add database/MissionCompletionRepository.php tests/Unit/Database/MissionCompletionRepositoryTest.php
git commit -m "feat(missions): MissionCompletionRepository (ledger + sinais do dia)"
```

---

### Task 5: MissionController + rota `/child/missions`

**Files:**
- Create: `api/Controllers/MissionController.php`
- Modify: `api/RestApi.php` (import + `registerGamificationRoutes`)
- Test: `tests/Unit/Api/MissionControllerTest.php`

- [ ] **Step 1: Escrever o teste que falha**

Create `tests/Unit/Api/MissionControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\MissionController;
use GuardKids\Auth\ChildAuth;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

final class MissionControllerTest extends TestCase
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
            // sinais seedados p/ o controller (independem de SQL real)
            public int $sigOpened = 0;
            public int $sigCategories = 0;
            public ?string $sigLastDate = null;

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
                if (str_contains($sql, 'COUNT(*)') && str_contains($sql, 'progression_awards')) {
                    return (string) $this->sigOpened;
                }
                if (str_contains($sql, 'COUNT(DISTINCT')) {
                    return (string) $this->sigCategories;
                }
                if (str_contains($sql, 'last_activity_date') && str_contains($sql, 'SELECT last_activity_date')) {
                    return $this->sigLastDate;
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
                if (preg_match("/mission_key = '([^']+)'/", $sql, $mm) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (string) ($r['mission_key'] ?? '') === $mm[1]));
                }
                if (preg_match("/completion_date = '([^']+)'/", $sql, $mm) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (string) ($r['completion_date'] ?? '') === $mm[1]));
                }
                return $rows;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->token = (new ChildAuth())->issueToken(1, 'tablet')['token'];
    }

    private function tokenReq(): WP_REST_Request
    {
        $req = new WP_REST_Request('GET', '/child/missions');
        $req->set_header('X-GuardKids-Token', $this->token);
        return $req;
    }

    public function testReturns401WithoutToken(): void
    {
        $res = (new MissionController())->childMissions(new WP_REST_Request('GET', '/child/missions'));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(401, $res->get_error_data()['status']);
    }

    public function testReturnsThreeMissionsWithProgress(): void
    {
        $this->wpdb->sigOpened = 1;
        $data = (new MissionController())->childMissions($this->tokenReq())->get_data();
        self::assertCount(3, $data);
        $explore = array_values(array_filter($data, static fn ($m) => $m['key'] === 'explore_3'))[0];
        self::assertSame(1, $explore['progress']);
        self::assertFalse($explore['completed']);
    }

    public function testCreditsBonusOnceAndIsIdempotent(): void
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        // streak_today completa: last_activity_date == hoje
        $this->wpdb->sigLastDate = $today;
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 1, 'xp' => 0, 'coins' => 0, 'streak_days' => 1, 'last_activity_date' => $today],
        ];

        $ctrl = new MissionController();
        $first = $ctrl->childMissions($this->tokenReq())->get_data();
        $streak = array_values(array_filter($first, static fn ($m) => $m['key'] === 'streak_today'))[0];
        self::assertTrue($streak['completed']);
        self::assertTrue($streak['justCompleted']);

        // 1 linha no ledger, bônus creditado 1x (10 XP / 5 coins)
        self::assertCount(1, $this->wpdb->t['mission_completions'] ?? []);
        self::assertSame(10, (int) array_values($this->wpdb->t['progression'])[0]['xp']);
        self::assertSame(5, (int) array_values($this->wpdb->t['progression'])[0]['coins']);

        // segunda chamada no mesmo dia não credita de novo
        $second = $ctrl->childMissions($this->tokenReq())->get_data();
        $streak2 = array_values(array_filter($second, static fn ($m) => $m['key'] === 'streak_today'))[0];
        self::assertFalse($streak2['justCompleted']);
        self::assertCount(1, $this->wpdb->t['mission_completions'] ?? []);
        self::assertSame(10, (int) array_values($this->wpdb->t['progression'])[0]['xp']);
    }

    public function testIncompleteMissionDoesNotCredit(): void
    {
        $this->wpdb->sigOpened = 1; // explore_3 alvo 3 → incompleto
        (new MissionController())->childMissions($this->tokenReq());
        self::assertArrayNotHasKey('mission_completions', $this->wpdb->t);
    }
}
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `phpunit --filter MissionControllerTest`
Expected: FAIL com "Class ...MissionController not found".

- [ ] **Step 3: Implementar o controller**

Create `api/Controllers/MissionController.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Auth\ChildAuth;
use GuardKids\Database\MissionCompletionRepository;
use GuardKids\Database\ProgressionRepository;
use GuardKids\Missions\MissionEvaluator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Endpoint das missões diárias (fatia 3b). Calcula o estado no read e credita
 * a conclusão de forma preguiçosa e idempotente (ledger anti-duplo). Segue o
 * modelo do awardForOpen: o crédito é envolto em try/catch e nunca quebra a
 * resposta.
 */
final class MissionController
{
    private readonly MissionCompletionRepository $completions;
    private readonly ProgressionRepository $wallet;
    private readonly ChildAuth $auth;

    public function __construct()
    {
        $this->completions = new MissionCompletionRepository();
        $this->wallet      = new ProgressionRepository();
        $this->auth        = new ChildAuth();
    }

    public function childMissions(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }

        $tz   = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $date = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');

        $signals  = $this->completions->signalsFor($childId, $date);
        $missions = MissionEvaluator::evaluate($signals);

        $out = [];
        foreach ($missions as $m) {
            $justCompleted = false;
            if ($m['completed'] && !$this->completions->existsFor($childId, $m['key'], $date)) {
                try {
                    $this->completions->record($childId, $m['key'], $date, $m['xpReward'], $m['coinsReward']);
                    $this->creditBonus($childId, $m['xpReward'], $m['coinsReward'], $date);
                    $justCompleted = true;
                } catch (\Throwable $e) {
                    error_log('[GuardKids] mission credit falhou: ' . $e->getMessage());
                }
            }
            $out[] = [
                'key'           => $m['key'],
                'title'         => $m['title'],
                'description'   => $m['description'],
                'icon'          => $m['icon'],
                'target'        => $m['target'],
                'progress'      => $m['progress'],
                'completed'     => $m['completed'],
                'justCompleted' => $justCompleted,
                'xpReward'      => $m['xpReward'],
                'coinsReward'   => $m['coinsReward'],
            ];
        }

        return rest_ensure_response($out);
    }

    /**
     * Credita o bônus na carteira sem alterar streak/última atividade (o bônus
     * não conta como novo dia de atividade). A conclusão só ocorre quando já
     * houve atividade hoje, então last_activity_date já é hoje.
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

In `api/RestApi.php`, add the import after line 25 (`use GuardKids\Api\Controllers\GamificationController;`):

```php
use GuardKids\Api\Controllers\MissionController;
```

Then in `registerGamificationRoutes()` (after the `/progression` block, before the closing `}`), add:

```php
        $missions = new MissionController();
        register_rest_route(self::NAMESPACE, '/child/missions', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$missions, 'childMissions'],
            'permission_callback' => (new ChildAuth())->requireToken(),
        ]);
```

- [ ] **Step 5: Rodar o teste e confirmar que passa**

Run: `phpunit --filter MissionControllerTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add api/Controllers/MissionController.php api/RestApi.php tests/Unit/Api/MissionControllerTest.php
git commit -m "feat(missions): MissionController + rota /child/missions (crédito preguiçoso idempotente)"
```

---

### Task 6: `missionsCompleted` real no GamificationController

**Files:**
- Modify: `api/Controllers/GamificationController.php`
- Modify: `tests/Unit/Api/GamificationControllerTest.php`

- [ ] **Step 1: Atualizar o teste (novo caso: missionsCompleted reflete o ledger)**

In `tests/Unit/Api/GamificationControllerTest.php`, in the anonymous `$wpdb` class inside `setUp`, extend `get_var` to count mission_completions. Replace the existing `get_var` method body's `return null;` at the end so it becomes:

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
                return null;
            }
```

Then add a new test method to the class:

```php
    public function testParentProgressionCountsCompletedMissions(): void
    {
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 5, 'xp' => 150, 'coins' => 20, 'streak_days' => 3, 'last_activity_date' => '2026-07-02'],
        ];
        $this->wpdb->t['mission_completions'] = [
            1 => ['id' => 1, 'child_id' => 5, 'mission_key' => 'explore_3', 'completion_date' => '2026-07-02'],
            2 => ['id' => 2, 'child_id' => 5, 'mission_key' => 'streak_today', 'completion_date' => '2026-07-02'],
            3 => ['id' => 3, 'child_id' => 9, 'mission_key' => 'explore_3', 'completion_date' => '2026-07-02'],
        ];
        $req = new WP_REST_Request('GET', '/progression');
        $req->set_param('child_id', 5);
        $data = (new GamificationController())->progression($req)->get_data();
        self::assertSame(2, $data['missionsCompleted']);
    }
```

> Nota: `testParentProgressionReflectsWallet` continua válido — sem `mission_completions` seedado, o COUNT devolve 0 e o assert `missionsCompleted === 0` segue passando.

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `phpunit --filter GamificationControllerTest`
Expected: FAIL em `testParentProgressionCountsCompletedMissions` (recebe 0, esperava 2 — o controller ainda hardcoda 0).

- [ ] **Step 3: Ligar o contador no controller**

In `api/Controllers/GamificationController.php`:

Add the import after line 8 (`use GuardKids\Database\ProgressionRepository;`):

```php
use GuardKids\Database\MissionCompletionRepository;
```

Add a property after line 20 (`private readonly ProgressionRepository $progression;`):

```php
    private readonly MissionCompletionRepository $missions;
```

In the constructor, after `$this->progression = new ProgressionRepository();`, add:

```php
        $this->missions = new MissionCompletionRepository();
```

In `progression()`, replace:

```php
            'missionsCompleted' => 0,
```

with:

```php
            'missionsCompleted' => $this->missions->countCompleted($childId),
```

- [ ] **Step 4: Rodar o teste e confirmar que passa**

Run: `phpunit --filter GamificationControllerTest`
Expected: PASS (4 tests, incluindo o novo).

- [ ] **Step 5: Commit**

```bash
git add api/Controllers/GamificationController.php tests/Unit/Api/GamificationControllerTest.php
git commit -m "feat(missions): missionsCompleted real no endpoint dos pais"
```

---

### Task 7: Front app-filho — api + MissionsCard + Home

**Files:**
- Modify: `public/app-child/src/api/gamification.ts`
- Create: `public/app-child/src/components/MissionsCard.tsx`
- Create: `public/app-child/src/components/MissionsCard.test.tsx`
- Modify: `public/app-child/src/pages/Home.tsx`

- [ ] **Step 1: Adicionar tipo + fetch na api**

In `public/app-child/src/api/gamification.ts`, append:

```ts
export type Mission = {
  key: string;
  title: string;
  description: string;
  icon: string;
  target: number;
  progress: number;
  completed: boolean;
  justCompleted: boolean;
  xpReward: number;
  coinsReward: number;
};

export function getMissions(): Promise<Mission[]> {
  return apiFetch<Mission[]>('/child/missions');
}
```

- [ ] **Step 2: Escrever o teste do componente que falha**

Create `public/app-child/src/components/MissionsCard.test.tsx`:

```tsx
import { screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { MissionsCard } from './MissionsCard';

const getMissions = vi.fn();
vi.mock('../api/gamification', () => ({ getMissions: () => getMissions() }));

const missions = [
  { key: 'explore_3', title: 'Explorador do dia', description: 'Abra 3 conteúdos hoje', icon: 'explore', target: 3, progress: 1, completed: false, justCompleted: false, xpReward: 15, coinsReward: 10 },
  { key: 'categories_2', title: 'Curioso', description: 'Explore 2 categorias diferentes hoje', icon: 'category', target: 2, progress: 2, completed: true, justCompleted: false, xpReward: 15, coinsReward: 10 },
  { key: 'streak_today', title: 'Presença', description: 'Volte e mantenha sua sequência hoje', icon: 'local_fire_department', target: 1, progress: 0, completed: false, justCompleted: false, xpReward: 10, coinsReward: 5 },
];

describe('MissionsCard', () => {
  afterEach(() => getMissions.mockReset());

  it('lista as 3 missões com título', async () => {
    getMissions.mockResolvedValueOnce(missions);
    renderWithClient(<MissionsCard />);
    expect(await screen.findByText('Explorador do dia')).toBeInTheDocument();
    expect(screen.getByText('Curioso')).toBeInTheDocument();
    expect(screen.getByText('Presença')).toBeInTheDocument();
  });

  it('mostra o progresso e marca a missão concluída', async () => {
    getMissions.mockResolvedValueOnce(missions);
    renderWithClient(<MissionsCard />);
    expect(await screen.findByText('1/3')).toBeInTheDocument();
    // a missão completa expõe o marcador de concluída
    expect(screen.getByTestId('mission-completed-categories_2')).toBeInTheDocument();
  });

  it('não renderiza nada quando não há missões', async () => {
    getMissions.mockResolvedValueOnce([]);
    const { container } = renderWithClient(<MissionsCard />);
    // espera o fetch resolver
    await screen.findByTestId('missions-empty');
    expect(container.querySelector('[data-testid="mission-row"]')).toBeNull();
  });
});
```

- [ ] **Step 3: Rodar o teste e confirmar que falha**

Run: `cd public/app-child && npx vitest run src/components/MissionsCard.test.tsx`
Expected: FAIL — não encontra `./MissionsCard`.

- [ ] **Step 4: Implementar o MissionsCard**

Create `public/app-child/src/components/MissionsCard.tsx`:

```tsx
import { useQuery } from '@tanstack/react-query';
import { getMissions } from '../api/gamification';
import { Icon } from './Icon';

export function MissionsCard() {
  const query = useQuery({ queryKey: ['child', 'missions'], queryFn: getMissions });

  if (query.isLoading) {
    return <div className="h-32 animate-pulse rounded-2xl bg-surface-container-low" />;
  }

  const missions = query.data ?? [];
  if (missions.length === 0) {
    return <div data-testid="missions-empty" className="hidden" />;
  }

  return (
    <div className="rounded-2xl bg-surface-container p-4 shadow-sm">
      <div className="mb-3 flex items-center gap-2">
        <Icon name="flag" className="text-xl text-primary" filled />
        <h3 className="font-display text-label-md font-bold text-on-surface">Missões do dia</h3>
      </div>
      <ul className="space-y-3">
        {missions.map((m) => {
          const pct = m.target > 0 ? Math.min(100, Math.round((m.progress / m.target) * 100)) : 0;
          return (
            <li key={m.key} data-testid="mission-row" className="flex items-center gap-3">
              <div
                className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${
                  m.completed ? 'bg-primary text-white' : 'bg-surface-variant text-on-surface-variant'
                }`}
              >
                <Icon name={m.completed ? 'check' : m.icon} className="text-lg" filled />
              </div>
              <div className="min-w-0 flex-1">
                <div className="flex items-center justify-between gap-2">
                  <span className="truncate text-label-md text-on-surface">{m.title}</span>
                  {m.completed ? (
                    <span
                      data-testid={`mission-completed-${m.key}`}
                      className="shrink-0 text-label-sm font-bold text-primary"
                    >
                      +{m.xpReward} XP
                    </span>
                  ) : (
                    <span className="shrink-0 text-label-sm text-on-surface-variant">
                      {m.progress}/{m.target}
                    </span>
                  )}
                </div>
                <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-surface-variant">
                  <div className="h-full rounded-full bg-primary" style={{ width: `${pct}%` }} />
                </div>
              </div>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
```

- [ ] **Step 5: Rodar o teste e confirmar que passa**

Run: `cd public/app-child && npx vitest run src/components/MissionsCard.test.tsx`
Expected: PASS (3 tests).

- [ ] **Step 6: Renderizar na Home (abaixo do ProgressCard)**

In `public/app-child/src/pages/Home.tsx`:

Add the import next to the ProgressCard import (line 6):

```tsx
import { MissionsCard } from '../components/MissionsCard';
```

Then in the JSX (line 54), immediately after `<ProgressCard />`, add:

```tsx
      <MissionsCard />
```

- [ ] **Step 7: Rodar a suíte vitest do app-filho inteira + build**

Run: `cd public/app-child && npx vitest run && npx tsc --noEmit && npx vite build`
Expected: todos os testes PASS; tsc sem erros; build sucesso.

- [ ] **Step 8: Commit**

```bash
git add public/app-child/src/api/gamification.ts public/app-child/src/components/MissionsCard.tsx public/app-child/src/components/MissionsCard.test.tsx public/app-child/src/pages/Home.tsx
git commit -m "feat(missions): MissionsCard no app-filho (metas do dia na Home)"
```

---

### Task 8: Verificar app-pais (sem mudança de código)

**Files:** nenhum a modificar. `GamificationDashboard.tsx` já exibe "Missões concluídas" e o tipo `ChildProgression` já tem `missionsCompleted`. Só validamos que continua verde com o backend novo.

- [ ] **Step 1: Rodar a suíte vitest do app-pais + build**

Run: `cd public/app-parent && npx vitest run && npx tsc --noEmit && npx vite build`
Expected: todos os testes PASS; tsc sem erros; build sucesso.

> Se algum teste do GamificationDashboard falhar (não deveria — o valor já vinha do tipo), ajuste o mock de `getChildProgression` para incluir `missionsCompleted` e re-rode. Não há mudança de código de produção nesta task.

---

### Task 9: Verificação final da suíte completa

- [ ] **Step 1: Suíte PHP unit inteira**

Run: `phpunit`
Expected: PASS — total = baseline (459) + novos testes das Tasks 1,2,4,5,6. Zero falhas.

- [ ] **Step 2: Vitest app-filho + app-pais**

Run: `cd public/app-child && npx vitest run` e `cd public/app-parent && npx vitest run`
Expected: ambos PASS (app-child = baseline 100 + 3 novos; app-parent = baseline 302).

- [ ] **Step 3: Confirmar árvore limpa e histórico**

Run: `git status -sb && git log --oneline -8`
Expected: working tree limpo; commits das Tasks 1–7 presentes.

> **Fora do escopo deste plano** (feito na sessão, não pelo executor): abrir PR, merge squash, release + tag, deploy SSH (`wp plugin install --force`; migração idempotente `CREATE TABLE IF NOT EXISTS`), e atualizar o índice de memória do projeto.

---

## Notas de verificação do plano vs. spec

- **Migração 019 + DB v19 + uninstall** → Task 3. ✅
- **`mission_completions` (UNIQUE anti-duplo)** → Task 3 (schema) + Task 4 (repo). ✅
- **`MissionCatalog` puro (3 missões)** → Task 1. ✅
- **`MissionEvaluator` puro** → Task 2. ✅
- **Leitura de sinais (opens/categorias/streak)** → Task 4 (`signalsFor`). ✅
- **`MissionController` + `/child/missions` + crédito preguiçoso idempotente** → Task 5. ✅
- **`missionsCompleted` real** → Task 6. ✅
- **`MissionsCard` no app-filho + Home** → Task 7. ✅
- **app-pais mostra o número** → Task 8 (já pronto; só verificação). ✅
- **Testes: catalog/evaluator/repo/controller/gamification + vitest** → Tasks 1,2,4,5,6,7. ✅
- **Sem cron; só `missionsCompleted` toca código existente; nada no `childHistory`** → respeitado em todas as tasks. ✅
