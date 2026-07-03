# Sprint 3d — Recompensas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fechar o ciclo econômico da gamificação: os coins acumulados (opens/missões/medalhas) viram gastáveis numa loja de recompensas definidas pelos pais, via fluxo pedir→aprovar→deduzir.

**Architecture:** Duas tabelas novas (`rewards` global + `reward_redemptions`) + dedução atômica `ProgressionRepository::spend` (UPDATE condicional). `RewardController` (catálogo CRUD dos pais + loja do filho) e `RedemptionController` (resgate do filho + fila/aprovação dos pais, deduzindo no approve) espelham o padrão do `RequestController`. App-filho ganha a página Loja; app-pais ganha a página Recompensas.

**Tech Stack:** PHP 8.2 (WordPress, `$wpdb`, PSR-4 self-contained), PHPUnit 9.6, React/TS/Vite/Tailwind + TanStack Query, Vitest.

**Spec:** `docs/superpowers/specs/2026-07-03-gamificacao-sprint3d-recompensas-design.md`

---

## Convenções de comando

**PHP** (o `php` do PATH é 8.1 e NÃO serve):
```bash
export PHP82="/c/Users/mysho/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe"
export PHPEXT="C:\\Users\\mysho\\AppData\\Roaming\\Local\\lightning-services\\php-8.2.29+0\\bin\\win64\\ext"
printf '[req]\ndefault_bits=2048\ndistinguished_name=req_dn\n[req_dn]\n' > /tmp/openssl.cnf
export OPENSSL_CONF="$(cygpath -w /tmp/openssl.cnf)"
phpunit() { "$PHP82" -n -d extension_dir="$PHPEXT" -d extension=mbstring -d extension=openssl -d extension=sodium -d memory_limit=512M vendor/bin/phpunit -c phpunit.xml.dist --no-coverage "$@"; }
```
**Front:** `cd public/app-child && npx vitest run <arquivo>` (idem `app-parent`). cwd = raiz do repo.

**Baselines (master pós-3c):** PHP unit **492**, vitest app-child **106**, app-parent **302**.

---

## File Structure

**Criar:** `database/migrations/021_rewards.php`, `database/RewardRepository.php`, `database/RewardRedemptionRepository.php`, `api/Controllers/RewardController.php`, `api/Controllers/RedemptionController.php`, `public/app-child/src/api/rewards.ts`, `public/app-child/src/pages/Loja.tsx`, `public/app-parent/src/api/rewards.ts`, `public/app-parent/src/pages/Recompensas.tsx` + os testes de cada.

**Modificar:** `guardkids.php` (DB v21), `uninstall.php`, `database/ProgressionRepository.php` (+`spend`), `api/RestApi.php` (+`registerRewardsRoutes`), `public/app-child/src/pages/Home.tsx` (card Loja), `public/app-parent/src/App.tsx` + `SideNav.tsx` (nav 'rewards').

---

### Task 1: Migração 021 + DB v21 + uninstall

**Files:** Create `database/migrations/021_rewards.php`; Modify `guardkids.php`, `uninstall.php`.

- [ ] **Step 1: Criar a migração.** Create `database/migrations/021_rewards.php`:

```php
<?php

declare(strict_types=1);

/**
 * Migration 021 — recompensas (gamificação 3d).
 *
 * `rewards` = catálogo global editável pelos pais. `reward_redemptions` =
 * pedidos de resgate (espelha `requests`), com snapshot do custo.
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $rewards     = $wpdb->prefix . 'guardkids_rewards';
    $redemptions = $wpdb->prefix . 'guardkids_reward_redemptions';

    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS {$rewards} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title      VARCHAR(120) NOT NULL,
            cost_coins INT UNSIGNED NOT NULL,
            icon       VARCHAR(40) NULL,
            active     TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY active (active)
        ) {$charsetCollate};"
    );

    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS {$redemptions} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id   BIGINT UNSIGNED NOT NULL,
            reward_id  BIGINT UNSIGNED NOT NULL,
            cost_coins INT UNSIGNED NOT NULL,
            status     VARCHAR(16) NOT NULL DEFAULT 'pending',
            decided_at DATETIME NULL,
            decided_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY child_id (child_id),
            KEY status (status)
        ) {$charsetCollate};"
    );
};
```

- [ ] **Step 2: Bump DB version.** Em `guardkids.php`, troque `define('GUARDKIDS_DB_VERSION', 20);` por `define('GUARDKIDS_DB_VERSION', 21);` (NÃO mexa em `GUARDKIDS_VERSION`).

- [ ] **Step 3: Drop no uninstall.** Em `uninstall.php`, no array `$tables`, adicione após a linha de `guardkids_medal_unlocks`:
```php
    $wpdb->prefix . 'guardkids_rewards',
    $wpdb->prefix . 'guardkids_reward_redemptions',
```

- [ ] **Step 4: Lint.** `"$PHP82" -l database/migrations/021_rewards.php && "$PHP82" -l uninstall.php && "$PHP82" -l guardkids.php` → `No syntax errors detected` em cada.

- [ ] **Step 5: Suíte unit.** `phpunit` → PASS, sem novas falhas (baseline 492).

- [ ] **Step 6: Commit.**
```bash
git add database/migrations/021_rewards.php guardkids.php uninstall.php
git commit -m "feat(rewards): migração 021 rewards + reward_redemptions + DB v21 + uninstall"
```

---

### Task 2: ProgressionRepository::spend (dedução atômica)

**Files:** Modify `database/ProgressionRepository.php`; Test `tests/Unit/Database/ProgressionSpendTest.php`.

- [ ] **Step 1: Escrever o teste que falha.** Create `tests/Unit/Database/ProgressionSpendTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\ProgressionRepository;
use PHPUnit\Framework\TestCase;

final class ProgressionSpendTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];

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

            // Emula o UPDATE condicional atômico: só deduz se coins >= X.
            public function query($sql)
            {
                $sql = (string) $sql;
                if (! str_contains($sql, 'UPDATE') || ! str_contains($sql, 'guardkids_progression')) {
                    return 0;
                }
                preg_match('/coins = coins - (\d+)/', $sql, $mc);
                preg_match('/child_id = (\d+)/', $sql, $mChild);
                preg_match('/coins >= (\d+)/', $sql, $mMin);
                $amount = (int) ($mc[1] ?? 0);
                $childId = (int) ($mChild[1] ?? 0);
                $min = (int) ($mMin[1] ?? 0);
                foreach ($this->rows as &$r) {
                    if ((int) $r['child_id'] === $childId && (int) $r['coins'] >= $min) {
                        $r['coins'] = (int) $r['coins'] - $amount;
                        return 1;
                    }
                }
                return 0;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    private function seed(int $childId, int $coins): void
    {
        $this->wpdb->rows[] = ['child_id' => $childId, 'coins' => $coins];
    }

    private function coinsOf(int $childId): int
    {
        foreach ($this->wpdb->rows as $r) {
            if ((int) $r['child_id'] === $childId) {
                return (int) $r['coins'];
            }
        }
        return -1;
    }

    public function testSpendDeductsWhenEnough(): void
    {
        $this->seed(1, 100);
        self::assertTrue((new ProgressionRepository())->spend(1, 30));
        self::assertSame(70, $this->coinsOf(1));
    }

    public function testSpendExactBalanceReachesZero(): void
    {
        $this->seed(1, 50);
        self::assertTrue((new ProgressionRepository())->spend(1, 50));
        self::assertSame(0, $this->coinsOf(1));
    }

    public function testSpendFailsWhenInsufficient(): void
    {
        $this->seed(1, 20);
        self::assertFalse((new ProgressionRepository())->spend(1, 30));
        self::assertSame(20, $this->coinsOf(1)); // inalterado
    }

    public function testSpendFailsWithoutWallet(): void
    {
        self::assertFalse((new ProgressionRepository())->spend(99, 10));
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha.** `phpunit --filter ProgressionSpendTest` → FAIL ("Call to undefined method ...spend()").

- [ ] **Step 3: Implementar.** Em `database/ProgressionRepository.php`, adicione o método (dentro da classe, após `apply`):

```php
    /**
     * Deduz coins de forma atômica: só desconta se o saldo cobrir. Um único
     * UPDATE ... WHERE coins >= X é atômico sob o lock de linha do MySQL —
     * sem read-modify-write, impossível ficar negativo.
     */
    public function spend(int $childId, int $coins): bool
    {
        $sql = $this->db->prepare(
            'UPDATE ' . $this->table() . ' SET coins = coins - %d, updated_at = %s '
            . 'WHERE child_id = %d AND coins >= %d',
            $coins,
            current_time('mysql', true),
            $childId,
            $coins,
        );
        return $this->db->query($sql) === 1;
    }
```

- [ ] **Step 4: Rodar e confirmar que passa.** `phpunit --filter ProgressionSpendTest` → PASS (4 tests).

- [ ] **Step 5: Commit.**
```bash
git add database/ProgressionRepository.php tests/Unit/Database/ProgressionSpendTest.php
git commit -m "feat(rewards): ProgressionRepository::spend (dedução atômica de coins)"
```

---

### Task 3: RewardRepository

**Files:** Create `database/RewardRepository.php`; Test `tests/Unit/Database/RewardRepositoryTest.php`.

- [ ] **Step 1: Escrever o teste que falha.** Create `tests/Unit/Database/RewardRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\RewardRepository;
use PHPUnit\Framework\TestCase;

final class RewardRepositoryTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];

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

            public function insert($table, $data, $format = null)
            {
                $id = count($this->rows) + 1;
                $this->insert_id = $id;
                $this->rows[$id] = array_merge(['id' => $id], $data);
                return 1;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $rows = array_values($this->rows);
                if (preg_match('/active = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['active'] ?? 0) === (int) $m[1]));
                }
                return $rows;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testFindActiveReturnsOnlyActive(): void
    {
        $repo = new RewardRepository();
        $repo->insert(['title' => 'Sorvete', 'cost_coins' => 100, 'icon' => 'icecream', 'active' => 1]);
        $repo->insert(['title' => 'Antigo', 'cost_coins' => 50, 'icon' => null, 'active' => 0]);
        $active = $repo->findActive();
        self::assertCount(1, $active);
        self::assertSame('Sorvete', $active[0]['title']);
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha.** `phpunit --filter RewardRepositoryTest` → FAIL ("Class ...RewardRepository not found").

- [ ] **Step 3: Implementar.** Create `database/RewardRepository.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Database;

/**
 * Catálogo global de recompensas (editável pelos pais). CRUD reusa a base;
 * findActive alimenta a loja do filho.
 */
final class RewardRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'rewards';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findActive(): array
    {
        return $this->findWhere(['active' => 1], 'id', 'ASC');
    }
}
```

- [ ] **Step 4: Rodar e confirmar que passa.** `phpunit --filter RewardRepositoryTest` → PASS (1 test).

- [ ] **Step 5: Commit.**
```bash
git add database/RewardRepository.php tests/Unit/Database/RewardRepositoryTest.php
git commit -m "feat(rewards): RewardRepository (catálogo + findActive)"
```

---

### Task 4: RewardRedemptionRepository

**Files:** Create `database/RewardRedemptionRepository.php`; Test `tests/Unit/Database/RewardRedemptionRepositoryTest.php`.

- [ ] **Step 1: Escrever o teste que falha.** Create `tests/Unit/Database/RewardRedemptionRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\RewardRedemptionRepository;
use PHPUnit\Framework\TestCase;

final class RewardRedemptionRepositoryTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];

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

            public function insert($table, $data, $format = null)
            {
                $id = count($this->rows) + 1;
                $this->insert_id = $id;
                $this->rows[$id] = array_merge(['id' => $id], $data);
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                $id = (int) ($where['id'] ?? 0);
                if (isset($this->rows[$id])) {
                    $this->rows[$id] = array_merge($this->rows[$id], $data);
                    return 1;
                }
                return 0;
            }

            public function get_row($sql, $output = OBJECT, $y = 0)
            {
                if (preg_match('/id = (\d+)/', (string) $sql, $m) === 1) {
                    return $this->rows[(int) $m[1]] ?? null;
                }
                return null;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $rows = array_values($this->rows);
                foreach (['child_id', 'reward_id'] as $col) {
                    if (preg_match("/{$col} = (\d+)/", (string) $sql, $m) === 1) {
                        $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r[$col] ?? 0) === (int) $m[1]));
                    }
                }
                if (preg_match("/status = '([^']+)'/", (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (string) ($r['status'] ?? '') === $m[1]));
                }
                return $rows;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testCreateAndFindByChild(): void
    {
        $repo = new RewardRedemptionRepository();
        $repo->create(1, 7, 100);
        $rows = $repo->findByChild(1);
        self::assertCount(1, $rows);
        self::assertSame(100, (int) $rows[0]['cost_coins']);
        self::assertSame('pending', $rows[0]['status']);
    }

    public function testHasPendingFor(): void
    {
        $repo = new RewardRedemptionRepository();
        self::assertFalse($repo->hasPendingFor(1, 7));
        $repo->create(1, 7, 100);
        self::assertTrue($repo->hasPendingFor(1, 7));
        self::assertFalse($repo->hasPendingFor(1, 8));
    }

    public function testDecideSetsStatus(): void
    {
        $repo = new RewardRedemptionRepository();
        $id = $repo->create(1, 7, 100);
        self::assertTrue($repo->decide($id, 'approved', 42));
        $row = $repo->findById($id);
        self::assertSame('approved', $row['status']);
        self::assertSame(42, (int) $row['decided_by']);
    }

    public function testFindByStatus(): void
    {
        $repo = new RewardRedemptionRepository();
        $repo->create(1, 7, 100);
        $id2 = $repo->create(2, 8, 50);
        $repo->decide($id2, 'denied', 42);
        self::assertCount(1, $repo->findByStatus('pending'));
        self::assertCount(1, $repo->findByStatus('denied'));
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha.** `phpunit --filter RewardRedemptionRepositoryTest` → FAIL ("Class ...RewardRedemptionRepository not found").

- [ ] **Step 3: Implementar.** Create `database/RewardRedemptionRepository.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Database;

/**
 * Pedidos de resgate de recompensa (espelha RequestRepository). O
 * enriquecimento com título da recompensa / nome do filho é feito no
 * controller (evita JOIN, mantém o repo simples e testável).
 */
final class RewardRedemptionRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'reward_redemptions';
    }

    public function create(int $childId, int $rewardId, int $cost): int
    {
        return $this->insert([
            'child_id'   => $childId,
            'reward_id'  => $rewardId,
            'cost_coins' => $cost,
            'status'     => 'pending',
        ]);
    }

    public function hasPendingFor(int $childId, int $rewardId): bool
    {
        return $this->findWhere([
            'child_id'  => $childId,
            'reward_id' => $rewardId,
            'status'    => 'pending',
        ]) !== [];
    }

    public function decide(int $id, string $status, int $userId): bool
    {
        return $this->update($id, [
            'status'     => $status,
            'decided_at' => current_time('mysql', true),
            'decided_by' => $userId,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByChild(int $childId): array
    {
        return $this->findWhere(['child_id' => $childId], 'created_at', 'DESC');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByStatus(string $status): array
    {
        return $this->findWhere(['status' => $status], 'created_at', 'DESC');
    }
}
```

- [ ] **Step 4: Rodar e confirmar que passa.** `phpunit --filter RewardRedemptionRepositoryTest` → PASS (4 tests).

- [ ] **Step 5: Commit.**
```bash
git add database/RewardRedemptionRepository.php tests/Unit/Database/RewardRedemptionRepositoryTest.php
git commit -m "feat(rewards): RewardRedemptionRepository (pedidos de resgate)"
```

---

### Task 5: RewardController (catálogo + loja) + rotas

**Files:** Create `api/Controllers/RewardController.php`; Modify `api/RestApi.php`; Test `tests/Unit/Api/RewardControllerTest.php`.

- [ ] **Step 1: Escrever o teste que falha.** Create `tests/Unit/Api/RewardControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\RewardController;
use GuardKids\Auth\ChildAuth;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

final class RewardControllerTest extends TestCase
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

            public function get_row($sql, $output = OBJECT, $y = 0)
            {
                $n = $this->nameOf((string) $sql);
                if (preg_match('/id = (\d+)/', (string) $sql, $m) === 1) {
                    return $this->t[$n][(int) $m[1]] ?? null;
                }
                return null;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $n = $this->nameOf((string) $sql);
                $rows = array_values($this->t[$n] ?? []);
                if (preg_match('/active = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['active'] ?? 0) === (int) $m[1]));
                }
                if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['child_id'] ?? 0) === (int) $m[1]));
                }
                return $rows;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->token = (new ChildAuth())->issueToken(1, 'tablet')['token'];
    }

    private function tokenReq(string $route): WP_REST_Request
    {
        $req = new WP_REST_Request('GET', $route);
        $req->set_header('X-GuardKids-Token', $this->token);
        return $req;
    }

    public function testCreateValidatesTitleAndCost(): void
    {
        $ctrl = new RewardController();
        $bad = new WP_REST_Request('POST', '/rewards');
        $bad->set_param('title', '');
        $bad->set_param('costCoins', 100);
        $res = $ctrl->create($bad);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(422, $res->get_error_data()['status']);
    }

    public function testCreatePersists(): void
    {
        $ctrl = new RewardController();
        $req = new WP_REST_Request('POST', '/rewards');
        $req->set_param('title', 'Sorvete');
        $req->set_param('costCoins', 100);
        $req->set_param('icon', 'icecream');
        $res = $ctrl->create($req);
        $data = $res->get_data();
        self::assertSame('Sorvete', $data['title']);
        self::assertSame(100, $data['costCoins']);
        self::assertTrue($data['active']);
    }

    public function testChildRewardsReturnsActivePlusBalance(): void
    {
        $this->wpdb->t['rewards'] = [
            1 => ['id' => 1, 'title' => 'Sorvete', 'cost_coins' => 100, 'icon' => 'icecream', 'active' => 1],
            2 => ['id' => 2, 'title' => 'Off', 'cost_coins' => 10, 'icon' => null, 'active' => 0],
        ];
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 1, 'coins' => 250],
        ];
        $data = (new RewardController())->childRewards($this->tokenReq('/child/rewards'))->get_data();
        self::assertSame(250, $data['balance']);
        self::assertCount(1, $data['rewards']);
        self::assertSame('Sorvete', $data['rewards'][0]['title']);
    }

    public function testChildRewards401WithoutToken(): void
    {
        $res = (new RewardController())->childRewards(new WP_REST_Request('GET', '/child/rewards'));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(401, $res->get_error_data()['status']);
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha.** `phpunit --filter RewardControllerTest` → FAIL ("Class ...RewardController not found").

- [ ] **Step 3: Implementar o controller.** Create `api/Controllers/RewardController.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Auth\ChildAuth;
use GuardKids\Database\ProgressionRepository;
use GuardKids\Database\RewardRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Catálogo de recompensas: CRUD dos pais (admin) + loja do filho (token).
 */
final class RewardController
{
    private readonly RewardRepository $repo;
    private readonly ProgressionRepository $progression;
    private readonly ChildAuth $auth;

    public function __construct()
    {
        $this->repo        = new RewardRepository();
        $this->progression = new ProgressionRepository();
        $this->auth        = new ChildAuth();
    }

    public function index(WP_REST_Request $req): WP_REST_Response
    {
        return rest_ensure_response(array_map([$this, 'toJson'], $this->repo->findAll('id')));
    }

    public function create(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $title = trim((string) $req->get_param('title'));
        $cost  = (int) $req->get_param('costCoins');
        if ($title === '' || $cost < 1) {
            return new WP_Error('invalid_payload', 'Título e custo (≥1) são obrigatórios.', ['status' => 422]);
        }
        $icon = $req->get_param('icon');
        $id = $this->repo->insert([
            'title'      => $title,
            'cost_coins' => $cost,
            'icon'       => is_string($icon) && $icon !== '' ? sanitize_text_field($icon) : null,
            'active'     => 1,
        ]);
        if ($id === 0) {
            return new WP_Error('db_error', 'Não foi possível salvar.', ['status' => 500]);
        }
        return new WP_REST_Response($this->toJson($this->repo->findById($id) ?? []), 201);
    }

    public function update(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id  = (int) $req['id'];
        $row = $this->repo->findById($id);
        if ($row === null) {
            return new WP_Error('not_found', 'Recompensa não encontrada.', ['status' => 404]);
        }
        $data = [];
        if ($req->get_param('title') !== null) {
            $title = trim((string) $req->get_param('title'));
            if ($title === '') {
                return new WP_Error('invalid_payload', 'Título não pode ser vazio.', ['status' => 422]);
            }
            $data['title'] = $title;
        }
        if ($req->get_param('costCoins') !== null) {
            $cost = (int) $req->get_param('costCoins');
            if ($cost < 1) {
                return new WP_Error('invalid_payload', 'Custo deve ser ≥ 1.', ['status' => 422]);
            }
            $data['cost_coins'] = $cost;
        }
        if ($req->get_param('icon') !== null) {
            $icon = (string) $req->get_param('icon');
            $data['icon'] = $icon !== '' ? sanitize_text_field($icon) : null;
        }
        if ($req->get_param('active') !== null) {
            $data['active'] = $req->get_param('active') ? 1 : 0;
        }
        if ($data !== []) {
            $this->repo->update($id, $data);
        }
        return rest_ensure_response($this->toJson($this->repo->findById($id) ?? []));
    }

    public function destroy(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id = (int) $req['id'];
        if (! $this->repo->delete($id)) {
            return new WP_Error('db_error', 'Falha ao deletar.', ['status' => 500]);
        }
        return rest_ensure_response(['deleted' => true]);
    }

    public function childRewards(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        $wallet  = $this->progression->findByChild($childId);
        $balance = $wallet !== null ? (int) $wallet['coins'] : 0;
        return rest_ensure_response([
            'balance' => $balance,
            'rewards' => array_map([$this, 'toJson'], $this->repo->findActive()),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toJson(array $row): array
    {
        return [
            'id'        => (int) ($row['id'] ?? 0),
            'title'     => (string) ($row['title'] ?? ''),
            'costCoins' => (int) ($row['cost_coins'] ?? 0),
            'icon'      => $row['icon'] ?? null,
            'active'    => (int) ($row['active'] ?? 0) === 1,
        ];
    }
}
```

- [ ] **Step 4: Registrar rotas.** Em `api/RestApi.php`: adicione o import após os outros controllers:
```php
use GuardKids\Api\Controllers\RewardController;
use GuardKids\Api\Controllers\RedemptionController;
```
Adicione a chamada em `registerRoutes()` após `$this->registerGamificationRoutes();`:
```php
        $this->registerRewardsRoutes();
```
E crie o método (junto dos outros `registerXRoutes`):
```php
    private function registerRewardsRoutes(): void
    {
        $rewards     = new RewardController();
        $redemptions = new RedemptionController();

        register_rest_route(self::NAMESPACE, '/rewards', [
            ['methods' => \WP_REST_Server::READABLE,  'callback' => [$rewards, 'index'],  'permission_callback' => [self::class, 'requireAdmin']],
            ['methods' => \WP_REST_Server::CREATABLE, 'callback' => [$rewards, 'create'], 'permission_callback' => [self::class, 'requireAdmin']],
        ]);
        register_rest_route(self::NAMESPACE, '/rewards/(?P<id>\d+)', [
            ['methods' => \WP_REST_Server::EDITABLE,  'callback' => [$rewards, 'update'],  'permission_callback' => [self::class, 'requireAdmin']],
            ['methods' => \WP_REST_Server::DELETABLE, 'callback' => [$rewards, 'destroy'], 'permission_callback' => [self::class, 'requireAdmin']],
        ]);
        register_rest_route(self::NAMESPACE, '/child/rewards', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$rewards, 'childRewards'],
            'permission_callback' => (new ChildAuth())->requireToken(),
        ]);

        register_rest_route(self::NAMESPACE, '/child/redemptions', [
            ['methods' => \WP_REST_Server::CREATABLE, 'callback' => [$redemptions, 'childCreate'], 'permission_callback' => (new ChildAuth())->requireToken()],
            ['methods' => \WP_REST_Server::READABLE,  'callback' => [$redemptions, 'childIndex'],  'permission_callback' => (new ChildAuth())->requireToken()],
        ]);
        register_rest_route(self::NAMESPACE, '/redemptions', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$redemptions, 'index'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);
        register_rest_route(self::NAMESPACE, '/redemptions/(?P<id>\d+)/approve', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$redemptions, 'approve'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);
        register_rest_route(self::NAMESPACE, '/redemptions/(?P<id>\d+)/deny', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$redemptions, 'deny'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);
    }
```
Confirme por Read que `ChildAuth` e `requireAdmin` já existem no `RestApi.php` (existem). Como o Task 6 cria o `RedemptionController`, o registro dele já fica pronto aqui (o arquivo é criado no próximo task; se rodar o PHP antes do Task 6, haverá erro de classe — por isso a suíte completa só roda no fim do Task 6).

- [ ] **Step 5: Rodar e confirmar que passa (só o filtro do controller, não a suíte inteira — RedemptionController ainda não existe).** `phpunit --filter RewardControllerTest` → PASS (4 tests).

- [ ] **Step 6: Commit.**
```bash
git add api/Controllers/RewardController.php api/RestApi.php tests/Unit/Api/RewardControllerTest.php
git commit -m "feat(rewards): RewardController (catálogo CRUD + loja) + rotas"
```

---

### Task 6: RedemptionController (resgate + aprovação atômica) + verificação

**Files:** Create `api/Controllers/RedemptionController.php`; Test `tests/Unit/Api/RedemptionControllerTest.php`.

- [ ] **Step 1: Escrever o teste que falha.** Create `tests/Unit/Api/RedemptionControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\RedemptionController;
use GuardKids\Auth\ChildAuth;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

final class RedemptionControllerTest extends TestCase
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
                    return 1;
                }
                return 0;
            }

            // spend atômico
            public function query($sql)
            {
                $sql = (string) $sql;
                if (! str_contains($sql, 'UPDATE') || ! str_contains($sql, 'guardkids_progression')) {
                    return 0;
                }
                preg_match('/coins = coins - (\d+)/', $sql, $mc);
                preg_match('/child_id = (\d+)/', $sql, $mChild);
                preg_match('/coins >= (\d+)/', $sql, $mMin);
                $amount = (int) ($mc[1] ?? 0);
                $childId = (int) ($mChild[1] ?? 0);
                $min = (int) ($mMin[1] ?? 0);
                foreach (($this->t['progression'] ?? []) as $id => $r) {
                    if ((int) $r['child_id'] === $childId && (int) $r['coins'] >= $min) {
                        $this->t['progression'][$id]['coins'] = (int) $r['coins'] - $amount;
                        return 1;
                    }
                }
                return 0;
            }

            public function get_row($sql, $output = OBJECT, $y = 0)
            {
                $n = $this->nameOf((string) $sql);
                if (preg_match('/id = (\d+)/', (string) $sql, $m) === 1) {
                    return $this->t[$n][(int) $m[1]] ?? null;
                }
                return null;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $n = $this->nameOf((string) $sql);
                $rows = array_values($this->t[$n] ?? []);
                foreach (['child_id', 'reward_id'] as $col) {
                    if (preg_match("/{$col} = (\d+)/", (string) $sql, $m) === 1) {
                        $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r[$col] ?? 0) === (int) $m[1]));
                    }
                }
                if (preg_match("/status = '([^']+)'/", (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (string) ($r['status'] ?? '') === $m[1]));
                }
                return $rows;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->token = (new ChildAuth())->issueToken(1, 'tablet')['token'];
        $this->wpdb->t['rewards'] = [
            7 => ['id' => 7, 'title' => 'Sorvete', 'cost_coins' => 100, 'icon' => 'icecream', 'active' => 1],
        ];
        $this->wpdb->t['children'] = [
            1 => ['id' => 1, 'name' => 'Lucas'],
        ];
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 1, 'coins' => 250],
        ];
    }

    private function tokenPost(int $rewardId): WP_REST_Request
    {
        $req = new WP_REST_Request('POST', '/child/redemptions');
        $req->set_header('X-GuardKids-Token', $this->token);
        $req->set_param('rewardId', $rewardId);
        return $req;
    }

    private function adminReq(string $method, string $route, int $id): WP_REST_Request
    {
        $req = new WP_REST_Request($method, $route);
        $req->set_param('id', $id);
        return $req;
    }

    public function testChildCreateBlocksDuplicatePending(): void
    {
        $ctrl = new RedemptionController();
        self::assertSame(201, $ctrl->childCreate($this->tokenPost(7))->get_status());
        $res = $ctrl->childCreate($this->tokenPost(7));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(409, $res->get_error_data()['status']);
        self::assertSame('already_pending', $res->get_error_code());
    }

    public function testChildCreateBlocksInsufficientBalance(): void
    {
        $this->wpdb->t['progression'][1]['coins'] = 50; // < 100
        $res = (new RedemptionController())->childCreate($this->tokenPost(7));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(409, $res->get_error_data()['status']);
        self::assertSame('insufficient_funds', $res->get_error_code());
    }

    public function testApproveDeductsSnapshotAtomically(): void
    {
        $ctrl = new RedemptionController();
        $ctrl->childCreate($this->tokenPost(7)); // cria redemption id 1, cost 100
        $res = $ctrl->approve($this->adminReq('POST', '/redemptions/1/approve', 1));
        self::assertSame('approved', $res->get_data()['status']);
        self::assertSame(150, (int) $this->wpdb->t['progression'][1]['coins']); // 250 - 100
    }

    public function testApproveFailsWhenBalanceDropped(): void
    {
        $ctrl = new RedemptionController();
        $ctrl->childCreate($this->tokenPost(7)); // cost 100 snapshot
        $this->wpdb->t['progression'][1]['coins'] = 30; // caiu abaixo do custo
        $res = $ctrl->approve($this->adminReq('POST', '/redemptions/1/approve', 1));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('insufficient_funds', $res->get_error_code());
        self::assertSame('pending', $this->wpdb->t['reward_redemptions'][1]['status']); // continua pending
        self::assertSame(30, (int) $this->wpdb->t['progression'][1]['coins']); // intacto
    }

    public function testApproveAlreadyDecided(): void
    {
        $ctrl = new RedemptionController();
        $ctrl->childCreate($this->tokenPost(7));
        $ctrl->approve($this->adminReq('POST', '/redemptions/1/approve', 1));
        $res = $ctrl->approve($this->adminReq('POST', '/redemptions/1/approve', 1));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(409, $res->get_error_data()['status']);
    }

    public function testDenyDoesNotSpend(): void
    {
        $ctrl = new RedemptionController();
        $ctrl->childCreate($this->tokenPost(7));
        $res = $ctrl->deny($this->adminReq('POST', '/redemptions/1/deny', 1));
        self::assertSame('denied', $res->get_data()['status']);
        self::assertSame(250, (int) $this->wpdb->t['progression'][1]['coins']); // nada saiu
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha.** `phpunit --filter RedemptionControllerTest` → FAIL ("Class ...RedemptionController not found").

- [ ] **Step 3: Implementar.** Create `api/Controllers/RedemptionController.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Auth\ChildAuth;
use GuardKids\Database\ChildRepository;
use GuardKids\Database\ProgressionRepository;
use GuardKids\Database\RewardRedemptionRepository;
use GuardKids\Database\RewardRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Resgates de recompensa: filho pede (token), pai aprova/nega (admin). A
 * aprovação deduz o snapshot de coins de forma atômica; a negação não mexe.
 */
final class RedemptionController
{
    private readonly RewardRedemptionRepository $repo;
    private readonly RewardRepository $rewards;
    private readonly ProgressionRepository $progression;
    private readonly ChildRepository $children;
    private readonly ChildAuth $auth;

    public function __construct()
    {
        $this->repo        = new RewardRedemptionRepository();
        $this->rewards     = new RewardRepository();
        $this->progression = new ProgressionRepository();
        $this->children    = new ChildRepository();
        $this->auth        = new ChildAuth();
    }

    public function childCreate(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        $rewardId = (int) $req->get_param('rewardId');
        $reward   = $this->rewards->findById($rewardId);
        if ($reward === null || (int) ($reward['active'] ?? 0) !== 1) {
            return new WP_Error('reward_unavailable', 'Recompensa indisponível.', ['status' => 404]);
        }
        if ($this->repo->hasPendingFor($childId, $rewardId)) {
            return new WP_Error('already_pending', 'Você já tem um pedido pendente dessa recompensa.', ['status' => 409]);
        }
        $cost   = (int) $reward['cost_coins'];
        $wallet = $this->progression->findByChild($childId);
        $balance = $wallet !== null ? (int) $wallet['coins'] : 0;
        if ($balance < $cost) {
            return new WP_Error('insufficient_funds', 'Coins insuficientes.', ['status' => 409]);
        }
        $id = $this->repo->create($childId, $rewardId, $cost);
        if ($id === 0) {
            return new WP_Error('db_error', 'Não foi possível salvar.', ['status' => 500]);
        }
        return new WP_REST_Response($this->toChildJson($this->repo->findById($id) ?? []), 201);
    }

    public function childIndex(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        return rest_ensure_response(array_map([$this, 'toChildJson'], $this->repo->findByChild($childId)));
    }

    public function index(WP_REST_Request $req): WP_REST_Response
    {
        $status = (string) ($req->get_param('status') ?? 'pending');
        $rows = $status === 'all' || $status === ''
            ? $this->repo->findByStatus('pending')
            : $this->repo->findByStatus($status);
        return rest_ensure_response(array_map([$this, 'toParentJson'], $rows));
    }

    public function approve(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id  = (int) $req['id'];
        $row = $this->repo->findById($id);
        if ($row === null) {
            return new WP_Error('not_found', 'Resgate não encontrado.', ['status' => 404]);
        }
        if ($row['status'] !== 'pending') {
            return new WP_Error('already_decided', 'Resgate já foi decidido.', ['status' => 409]);
        }
        if (! $this->progression->spend((int) $row['child_id'], (int) $row['cost_coins'])) {
            return new WP_Error('insufficient_funds', 'Saldo insuficiente para aprovar.', ['status' => 409]);
        }
        if (! $this->repo->decide($id, 'approved', get_current_user_id())) {
            return new WP_Error('db_error', 'Falha ao salvar.', ['status' => 500]);
        }
        return rest_ensure_response($this->toParentJson($this->repo->findById($id) ?? []));
    }

    public function deny(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id  = (int) $req['id'];
        $row = $this->repo->findById($id);
        if ($row === null) {
            return new WP_Error('not_found', 'Resgate não encontrado.', ['status' => 404]);
        }
        if ($row['status'] !== 'pending') {
            return new WP_Error('already_decided', 'Resgate já foi decidido.', ['status' => 409]);
        }
        if (! $this->repo->decide($id, 'denied', get_current_user_id())) {
            return new WP_Error('db_error', 'Falha ao salvar.', ['status' => 500]);
        }
        return rest_ensure_response($this->toParentJson($this->repo->findById($id) ?? []));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toChildJson(array $row): array
    {
        $reward = $this->rewards->findById((int) ($row['reward_id'] ?? 0));
        return [
            'id'        => (int) ($row['id'] ?? 0),
            'rewardId'  => (int) ($row['reward_id'] ?? 0),
            'title'     => (string) ($reward['title'] ?? '—'),
            'icon'      => $reward['icon'] ?? null,
            'costCoins' => (int) ($row['cost_coins'] ?? 0),
            'status'    => (string) ($row['status'] ?? 'pending'),
            'createdAt' => $row['created_at'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toParentJson(array $row): array
    {
        $reward = $this->rewards->findById((int) ($row['reward_id'] ?? 0));
        $child  = $this->children->findById((int) ($row['child_id'] ?? 0));
        return [
            'id'         => (int) ($row['id'] ?? 0),
            'childId'    => (int) ($row['child_id'] ?? 0),
            'childName'  => (string) ($child['name'] ?? 'Filho'),
            'rewardId'   => (int) ($row['reward_id'] ?? 0),
            'title'      => (string) ($reward['title'] ?? '—'),
            'costCoins'  => (int) ($row['cost_coins'] ?? 0),
            'status'     => (string) ($row['status'] ?? 'pending'),
            'decidedAt'  => $row['decided_at'] ?? null,
            'createdAt'  => $row['created_at'] ?? null,
        ];
    }
}
```

- [ ] **Step 4: Rodar o filtro + a suíte inteira.** `phpunit --filter RedemptionControllerTest` → PASS (6 tests). Depois `phpunit` → PASS geral (baseline 492 + novos das Tasks 2,3,4,5,6; a rota registrada no Task 5 agora resolve as duas classes).

- [ ] **Step 5: Commit.**
```bash
git add api/Controllers/RedemptionController.php tests/Unit/Api/RedemptionControllerTest.php
git commit -m "feat(rewards): RedemptionController (resgate + aprovação atômica)"
```

---

### Task 7: Front app-filho — api + página Loja + card na Home

**Files:** Create `public/app-child/src/api/rewards.ts`, `public/app-child/src/pages/Loja.tsx`, `public/app-child/src/pages/Loja.test.tsx`; Modify `public/app-child/src/pages/Home.tsx`, e o roteador de páginas do app-child.

- [ ] **Step 1: Criar a api.** Create `public/app-child/src/api/rewards.ts`:

```ts
import { apiFetch } from './client';

export type Reward = {
  id: number;
  title: string;
  costCoins: number;
  icon: string | null;
  active: boolean;
};

export type Redemption = {
  id: number;
  rewardId: number;
  title: string;
  icon: string | null;
  costCoins: number;
  status: 'pending' | 'approved' | 'denied';
  createdAt: string | null;
};

export function getStore(): Promise<{ balance: number; rewards: Reward[] }> {
  return apiFetch<{ balance: number; rewards: Reward[] }>('/child/rewards');
}

export function getMyRedemptions(): Promise<Redemption[]> {
  return apiFetch<Redemption[]>('/child/redemptions');
}

export function redeem(rewardId: number): Promise<Redemption> {
  return apiFetch<Redemption>('/child/redemptions', {
    method: 'POST',
    body: JSON.stringify({ rewardId }),
  });
}
```
Confirme por Read (`public/app-child/src/api/client.ts`) que `apiFetch` aceita `{ method, body }` (o `redeem`/`reportScheduleBlock` existentes já fazem POST assim — siga o mesmo formato).

- [ ] **Step 2: Escrever o teste da página que falha.** Create `public/app-child/src/pages/Loja.test.tsx`:

```tsx
import { screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { Loja } from './Loja';

const getStore = vi.fn();
const getMyRedemptions = vi.fn();
const redeem = vi.fn();
vi.mock('../api/rewards', () => ({
  getStore: () => getStore(),
  getMyRedemptions: () => getMyRedemptions(),
  redeem: (id: number) => redeem(id),
}));

const store = {
  balance: 120,
  rewards: [
    { id: 1, title: 'Sorvete', costCoins: 100, icon: 'icecream', active: true },
    { id: 2, title: 'Cinema', costCoins: 300, icon: 'movie', active: true },
  ],
};

describe('Loja', () => {
  afterEach(() => {
    getStore.mockReset();
    getMyRedemptions.mockReset();
    redeem.mockReset();
  });

  it('mostra saldo e recompensas, desabilitando a que não dá pra pagar', async () => {
    getStore.mockResolvedValueOnce(store);
    getMyRedemptions.mockResolvedValueOnce([]);
    renderWithClient(<Loja onNavigate={() => {}} />);
    expect(await screen.findByText('Sorvete')).toBeInTheDocument();
    expect(screen.getByText(/120/)).toBeInTheDocument();
    // Sorvete (100) resgatável; Cinema (300) não (saldo 120)
    const botoes = screen.getAllByRole('button', { name: /resgatar/i });
    expect(botoes[0]).toBeEnabled();
    expect(botoes[1]).toBeDisabled();
  });

  it('lista os resgates do filho com status', async () => {
    getStore.mockResolvedValueOnce(store);
    getMyRedemptions.mockResolvedValueOnce([
      { id: 9, rewardId: 1, title: 'Sorvete', icon: 'icecream', costCoins: 100, status: 'pending', createdAt: null },
    ]);
    renderWithClient(<Loja onNavigate={() => {}} />);
    expect(await screen.findByText(/pendente/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 3: Rodar e confirmar que falha.** `cd public/app-child && npx vitest run src/pages/Loja.test.tsx` → FAIL (não encontra `./Loja`).

- [ ] **Step 4: Implementar a página.** Create `public/app-child/src/pages/Loja.tsx`:

```tsx
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { getMyRedemptions, getStore, redeem } from '../api/rewards';
import type { PageId } from '../App';
import { Icon } from '../components/Icon';

const STATUS_LABEL: Record<string, string> = {
  pending: 'Pendente',
  approved: 'Aprovado',
  denied: 'Negado',
};

export function Loja({ onNavigate }: { onNavigate: (page: PageId) => void }) {
  const qc = useQueryClient();
  const storeQuery = useQuery({ queryKey: ['child', 'store'], queryFn: getStore });
  const mine = useQuery({ queryKey: ['child', 'redemptions'], queryFn: getMyRedemptions });

  const redeemMut = useMutation({
    mutationFn: (rewardId: number) => redeem(rewardId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['child', 'redemptions'] });
      qc.invalidateQueries({ queryKey: ['child', 'store'] });
    },
  });

  const balance = storeQuery.data?.balance ?? 0;
  const rewards = storeQuery.data?.rewards ?? [];
  const redemptions = mine.data ?? [];
  const pendingRewardIds = new Set(
    redemptions.filter((r) => r.status === 'pending').map((r) => r.rewardId),
  );

  return (
    <main className="flex flex-1 flex-col gap-stack-lg px-container-padding-mobile py-stack-md">
      <button
        type="button"
        onClick={() => onNavigate('home')}
        className="flex items-center gap-1 self-start text-label-sm text-on-surface-variant"
      >
        <Icon name="arrow_back" className="text-base" /> Voltar
      </button>

      <div className="flex items-center justify-between rounded-2xl bg-primary p-4 text-white shadow-sm">
        <span className="font-display text-title-md font-bold">Loja de Recompensas</span>
        <span className="flex items-center gap-1 text-title-md font-bold">
          <Icon name="paid" className="text-xl" filled /> {balance}
        </span>
      </div>

      <ul className="space-y-3">
        {rewards.map((r) => {
          const canAfford = balance >= r.costCoins;
          const alreadyPending = pendingRewardIds.has(r.id);
          const disabled = !canAfford || alreadyPending || redeemMut.isPending;
          return (
            <li
              key={r.id}
              className="flex items-center gap-3 rounded-2xl bg-surface-container p-4 shadow-sm"
            >
              <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-surface-variant text-on-surface-variant">
                <Icon name={r.icon ?? 'card_giftcard'} className="text-xl" filled />
              </div>
              <div className="min-w-0 flex-1">
                <div className="text-label-md text-on-surface">{r.title}</div>
                <div className="flex items-center gap-1 text-label-sm text-orange-500">
                  <Icon name="paid" className="text-sm" filled /> {r.costCoins}
                </div>
              </div>
              <button
                type="button"
                disabled={disabled}
                onClick={() => redeemMut.mutate(r.id)}
                className="shrink-0 rounded-xl bg-primary px-4 py-2 text-label-md font-semibold text-white disabled:opacity-40"
              >
                {alreadyPending ? 'Pedido enviado' : 'Resgatar'}
              </button>
            </li>
          );
        })}
      </ul>

      <div>
        <h3 className="mb-2 font-display text-label-md font-bold text-on-surface">Meus resgates</h3>
        {redemptions.length === 0 ? (
          <p className="text-label-sm text-on-surface-variant">Você ainda não resgatou nada.</p>
        ) : (
          <ul className="space-y-2">
            {redemptions.map((r) => (
              <li
                key={r.id}
                className="flex items-center justify-between rounded-xl bg-surface-container-low p-3"
              >
                <span className="text-label-md text-on-surface">{r.title}</span>
                <span className="text-label-sm font-semibold text-on-surface-variant">
                  {STATUS_LABEL[r.status] ?? r.status}
                </span>
              </li>
            ))}
          </ul>
        )}
      </div>
    </main>
  );
}
```

- [ ] **Step 5: Rodar e confirmar que passa.** `cd public/app-child && npx vitest run src/pages/Loja.test.tsx` → PASS (2 tests).

- [ ] **Step 6: Wire a página no roteador + card na Home.** Leia `public/app-child/src/App.tsx` pra ver como as páginas são registradas (o tipo `PageId` e o switch/render que mapeia pageId→componente) e replique o padrão de uma página existente (ex.: como `Localizacao` ou `Requests` é roteada). Adicione:
  - `'store'` ao union `PageId`.
  - o case que renderiza `<Loja onNavigate={...} />` passando a função de navegação usada pelas outras páginas.
  Em `public/app-child/src/pages/Home.tsx`, adicione um card de entrada logo após `<MedalsCard />` (reusa o saldo da query `['child','progression']` que o `ProgressCard` já dispara — NÃO cria fetch novo):
```tsx
      <button
        type="button"
        onClick={() => onNavigate('store')}
        className="flex items-center justify-between rounded-2xl bg-surface-container p-4 text-left shadow-sm"
      >
        <span className="flex items-center gap-2">
          <Icon name="storefront" className="text-xl text-primary" filled />
          <span className="font-display text-label-md font-bold text-on-surface">Loja de Recompensas</span>
        </span>
        <Icon name="chevron_right" className="text-on-surface-variant" />
      </button>
```
Confirme que `Home.tsx` já importa `Icon` e recebe `onNavigate` (recebe — `QuickActions`/`SafeBrowser` já usam). Se `Icon` não estiver importado, adicione `import { Icon } from '../components/Icon';`.

- [ ] **Step 7: Suíte vitest + tsc + build.** `cd public/app-child && npx vitest run && npx tsc --noEmit && npx vite build` → todos PASS (baseline 106 + 2 novos = 108); tsc limpo; build ok.

- [ ] **Step 8: Commit.**
```bash
git add public/app-child/src/api/rewards.ts public/app-child/src/pages/Loja.tsx public/app-child/src/pages/Loja.test.tsx public/app-child/src/pages/Home.tsx public/app-child/src/App.tsx
git commit -m "feat(rewards): Loja no app-filho (loja + meus resgates) + card na Home"
```

---

### Task 8: Front app-pais — api + página Recompensas + nav

**Files:** Create `public/app-parent/src/api/rewards.ts`, `public/app-parent/src/pages/Recompensas.tsx`, `public/app-parent/src/pages/Recompensas.test.tsx`; Modify `public/app-parent/src/App.tsx`, `public/app-parent/src/components/SideNav.tsx`.

- [ ] **Step 1: Criar a api.** Create `public/app-parent/src/api/rewards.ts`:

```ts
import { apiFetch } from './client';

export type Reward = {
  id: number;
  title: string;
  costCoins: number;
  icon: string | null;
  active: boolean;
};

export type PendingRedemption = {
  id: number;
  childId: number;
  childName: string;
  rewardId: number;
  title: string;
  costCoins: number;
  status: string;
  createdAt: string | null;
};

export function listRewards(): Promise<Reward[]> {
  return apiFetch<Reward[]>('/rewards');
}

export function createReward(input: { title: string; costCoins: number; icon?: string }): Promise<Reward> {
  return apiFetch<Reward>('/rewards', { method: 'POST', body: JSON.stringify(input) });
}

export function updateReward(id: number, input: Partial<{ title: string; costCoins: number; icon: string; active: boolean }>): Promise<Reward> {
  return apiFetch<Reward>(`/rewards/${id}`, { method: 'PUT', body: JSON.stringify(input) });
}

export function deleteReward(id: number): Promise<{ deleted: boolean }> {
  return apiFetch<{ deleted: boolean }>(`/rewards/${id}`, { method: 'DELETE' });
}

export function listPendingRedemptions(): Promise<PendingRedemption[]> {
  return apiFetch<PendingRedemption[]>('/redemptions?status=pending');
}

export function approveRedemption(id: number): Promise<PendingRedemption> {
  return apiFetch<PendingRedemption>(`/redemptions/${id}/approve`, { method: 'POST' });
}

export function denyRedemption(id: number): Promise<PendingRedemption> {
  return apiFetch<PendingRedemption>(`/redemptions/${id}/deny`, { method: 'POST' });
}
```
Confirme por Read (`public/app-parent/src/api/client.ts`) o formato de `apiFetch` (method/body) — siga o padrão de `api/children.ts`/`api/sites.ts` existentes.

- [ ] **Step 2: Escrever o teste da página que falha.** Create `public/app-parent/src/pages/Recompensas.test.tsx`:

```tsx
import { screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { Recompensas } from './Recompensas';

const listRewards = vi.fn();
const listPendingRedemptions = vi.fn();
vi.mock('../api/rewards', () => ({
  listRewards: () => listRewards(),
  createReward: vi.fn(),
  updateReward: vi.fn(),
  deleteReward: vi.fn(),
  listPendingRedemptions: () => listPendingRedemptions(),
  approveRedemption: vi.fn(),
  denyRedemption: vi.fn(),
}));

describe('Recompensas', () => {
  afterEach(() => {
    listRewards.mockReset();
    listPendingRedemptions.mockReset();
  });

  it('lista recompensas e resgates pendentes', async () => {
    listRewards.mockResolvedValue([
      { id: 1, title: 'Sorvete', costCoins: 100, icon: 'icecream', active: true },
    ]);
    listPendingRedemptions.mockResolvedValue([
      { id: 9, childId: 5, childName: 'Lucas', rewardId: 1, title: 'Sorvete', costCoins: 100, status: 'pending', createdAt: null },
    ]);
    renderWithClient(<Recompensas />);
    expect(await screen.findByText('Sorvete')).toBeInTheDocument();
    expect(await screen.findByText('Lucas')).toBeInTheDocument();
    expect(await screen.findByRole('button', { name: /aprovar/i })).toBeInTheDocument();
  });
});
```

- [ ] **Step 3: Rodar e confirmar que falha.** `cd public/app-parent && npx vitest run src/pages/Recompensas.test.tsx` → FAIL (não encontra `./Recompensas`).

- [ ] **Step 4: Implementar a página.** Create `public/app-parent/src/pages/Recompensas.tsx`:

```tsx
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import {
  approveRedemption,
  createReward,
  deleteReward,
  denyRedemption,
  listPendingRedemptions,
  listRewards,
  updateReward,
} from '../api/rewards';

export function Recompensas() {
  const qc = useQueryClient();
  const rewards = useQuery({ queryKey: ['rewards'], queryFn: listRewards });
  const pending = useQuery({ queryKey: ['redemptions', 'pending'], queryFn: listPendingRedemptions });

  const [title, setTitle] = useState('');
  const [cost, setCost] = useState('');
  const [error, setError] = useState<string | null>(null);

  const createMut = useMutation({
    mutationFn: () => createReward({ title: title.trim(), costCoins: Number(cost) }),
    onSuccess: () => {
      setTitle('');
      setCost('');
      qc.invalidateQueries({ queryKey: ['rewards'] });
    },
  });
  const toggleMut = useMutation({
    mutationFn: (r: { id: number; active: boolean }) => updateReward(r.id, { active: !r.active }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['rewards'] }),
  });
  const removeMut = useMutation({
    mutationFn: (id: number) => deleteReward(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['rewards'] }),
  });
  const decideMut = useMutation({
    mutationFn: (v: { id: number; approve: boolean }) =>
      v.approve ? approveRedemption(v.id) : denyRedemption(v.id),
    onSuccess: () => {
      setError(null);
      qc.invalidateQueries({ queryKey: ['redemptions', 'pending'] });
    },
    onError: () => setError('Não foi possível aprovar: saldo insuficiente do filho.'),
  });

  const list = rewards.data ?? [];
  const queue = pending.data ?? [];

  return (
    <main className="mx-auto w-full max-w-[1440px] flex-1 space-y-6 p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
      <div>
        <h1 className="font-display text-headline-lg text-on-background">Recompensas</h1>
        <p className="text-body-md text-on-surface-variant">
          Crie recompensas que seus filhos compram com GuardCoins e aprove os resgates.
        </p>
      </div>

      <section className="rounded-2xl border border-outline-variant bg-surface p-4">
        <h2 className="mb-3 font-display text-title-md text-on-surface">Gerir recompensas</h2>
        <div className="mb-4 flex flex-wrap items-end gap-2">
          <label className="flex flex-col text-label-sm text-on-surface-variant">
            Título
            <input
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              className="mt-1 rounded-lg border border-outline-variant bg-surface px-3 py-2 text-on-surface"
            />
          </label>
          <label className="flex flex-col text-label-sm text-on-surface-variant">
            Custo (coins)
            <input
              type="number"
              value={cost}
              onChange={(e) => setCost(e.target.value)}
              className="mt-1 w-32 rounded-lg border border-outline-variant bg-surface px-3 py-2 text-on-surface"
            />
          </label>
          <button
            type="button"
            disabled={title.trim() === '' || Number(cost) < 1 || createMut.isPending}
            onClick={() => createMut.mutate()}
            className="rounded-xl bg-primary px-4 py-2 text-label-md font-semibold text-white disabled:opacity-40"
          >
            Adicionar
          </button>
        </div>
        {list.length === 0 ? (
          <p className="text-label-sm text-on-surface-variant">Nenhuma recompensa ainda.</p>
        ) : (
          <ul className="space-y-2">
            {list.map((r) => (
              <li key={r.id} className="flex items-center justify-between rounded-lg bg-surface-container-low p-3">
                <span className="text-label-md text-on-surface">
                  {r.title} · <span className="text-orange-500">{r.costCoins}</span>
                  {!r.active && <span className="ml-2 text-label-sm text-on-surface-variant">(inativa)</span>}
                </span>
                <span className="flex gap-2">
                  <button type="button" onClick={() => toggleMut.mutate(r)} className="text-label-sm text-primary">
                    {r.active ? 'Desativar' : 'Ativar'}
                  </button>
                  <button type="button" onClick={() => removeMut.mutate(r.id)} className="text-label-sm text-error">
                    Remover
                  </button>
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className="rounded-2xl border border-outline-variant bg-surface p-4">
        <h2 className="mb-3 font-display text-title-md text-on-surface">Resgates pendentes</h2>
        {error && <p className="mb-2 rounded-lg bg-error/10 p-2 text-label-sm text-error">{error}</p>}
        {queue.length === 0 ? (
          <p className="text-label-sm text-on-surface-variant">Nenhum resgate pendente.</p>
        ) : (
          <ul className="space-y-2">
            {queue.map((q) => (
              <li key={q.id} className="flex items-center justify-between rounded-lg bg-surface-container-low p-3">
                <span className="text-label-md text-on-surface">
                  <strong>{q.childName}</strong> quer <strong>{q.title}</strong> · <span className="text-orange-500">{q.costCoins}</span>
                </span>
                <span className="flex gap-2">
                  <button
                    type="button"
                    onClick={() => decideMut.mutate({ id: q.id, approve: true })}
                    className="rounded-lg bg-primary px-3 py-1 text-label-sm font-semibold text-white"
                  >
                    Aprovar
                  </button>
                  <button
                    type="button"
                    onClick={() => decideMut.mutate({ id: q.id, approve: false })}
                    className="rounded-lg border border-outline-variant px-3 py-1 text-label-sm text-on-surface"
                  >
                    Negar
                  </button>
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>
    </main>
  );
}
```

- [ ] **Step 5: Rodar e confirmar que passa.** `cd public/app-parent && npx vitest run src/pages/Recompensas.test.tsx` → PASS (1 test).

- [ ] **Step 6: Wire nav.** Leia `public/app-parent/src/App.tsx` e `public/app-parent/src/components/SideNav.tsx` e replique EXATAMENTE como a página `GamificationDashboard` (`PageId 'gamification'`) foi registrada:
  - `'rewards'` ao union `PageId`.
  - item na SideNav: label "Recompensas", ícone `card_giftcard`, logo após "Gamificação".
  - case no render/switch que devolve `<Recompensas />`.

- [ ] **Step 7: Suíte vitest + tsc + build.** `cd public/app-parent && npx vitest run && npx tsc --noEmit && npx vite build` → todos PASS (baseline 302 + 1 novo = 303); tsc limpo; build ok.

- [ ] **Step 8: Commit.**
```bash
git add public/app-parent/src/api/rewards.ts public/app-parent/src/pages/Recompensas.tsx public/app-parent/src/pages/Recompensas.test.tsx public/app-parent/src/App.tsx public/app-parent/src/components/SideNav.tsx
git commit -m "feat(rewards): página Recompensas no painel dos pais (gestão + aprovação) + nav"
```

---

### Task 9: Verificação final

- [ ] **Step 1: Suíte PHP unit inteira.** `phpunit` → PASS (baseline 492 + novos das Tasks 2–6). Zero falhas.
- [ ] **Step 2: Vitest ambos apps.** `cd public/app-child && npx vitest run` (108) e `cd public/app-parent && npx vitest run` (303) → ambos PASS.
- [ ] **Step 3: e2e do app-filho** (o card da Loja reusa `/child/progression`, já estubado — não deve quebrar). `cd public/app-child && npx playwright test` → PASS (3).
- [ ] **Step 4: Árvore + histórico.** `git status -sb && git log --oneline -9`.

> **Fora do escopo deste plano** (feito na sessão): PR, merge squash, release v1.32.0 (bump `GUARDKIDS_VERSION`), deploy SSH, atualizar memória.

---

## Notas de verificação do plano vs. spec

- **Migração 021 (2 tabelas) + DB v21 + uninstall** → Task 1. ✅
- **`ProgressionRepository::spend` atômico** → Task 2. ✅
- **`RewardRepository` (findActive + CRUD)** → Task 3. ✅
- **`RewardRedemptionRepository` (create/hasPendingFor/decide/findBy*)** → Task 4. ✅
- **`RewardController` (CRUD admin + `/child/rewards` com saldo)** → Task 5. ✅
- **`RedemptionController` (pedir com bloqueios; approve atômico + `insufficient_funds`/`already_decided`; deny)** → Task 6. ✅
- **Rotas `registerRewardsRoutes`** → Task 5. ✅
- **App-filho Loja + card Home (reusa /child/progression)** → Task 7. ✅
- **App-pais Recompensas (gestão + fila) + nav** → Task 8. ✅
- **Testes: spend/repos/controllers + vitest ambos apps + e2e** → Tasks 2–9. ✅
- **Sem cron; só `spend` + `registerRewardsRoutes` tocam código existente** → respeitado. ✅
