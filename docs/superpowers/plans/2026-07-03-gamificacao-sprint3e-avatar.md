# Sprint 3e — Avatar — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fechar o roadmap 3 de gamificação: o filho personaliza o próprio avatar (7 emojis), com opções desbloqueadas pela progressão (nível de 3a + medalhas de 3c). O avatar equipado aparece no Header/ProfileSheet.

**Architecture:** Peças puras (`AvatarCatalog` + `AvatarEvaluator`) definem os avatares e calculam o desbloqueio no read (sem ledger — derivado de nível + medalhas). O avatar equipado persiste numa coluna nova `equipped_avatar` em `progression` (migração 022 ALTER idempotente). `AvatarController` expõe listar/equipar; o `/child/me` passa a devolver o `avatarEmoji`. Página `Avatar` no app-filho, acessada pelo ProfileSheet.

**Tech Stack:** PHP 8.2 (WordPress, `$wpdb`, PSR-4 self-contained), PHPUnit 9.6, React/TS/Vite/Tailwind + TanStack Query, Vitest.

**Spec:** `docs/superpowers/specs/2026-07-03-gamificacao-sprint3e-avatar-design.md`

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
**Front:** `cd public/app-child && npx vitest run <arquivo>`. cwd = raiz do repo.

**Baselines (master pós-3d):** PHP unit **511**, vitest app-child **108**.

---

## File Structure

**Criar:** `database/migrations/022_avatar.php`, `includes/Avatars/AvatarCatalog.php`, `includes/Avatars/AvatarEvaluator.php`, `api/Controllers/AvatarController.php`, `public/app-child/src/api/avatars.ts`, `public/app-child/src/pages/Avatar.tsx` + os testes.

**Modificar:** `guardkids.php` (DB v22), `database/ProgressionRepository.php` (+`setEquippedAvatar`), `database/MedalUnlockRepository.php` (+`unlockedKeys`), `api/Controllers/ChildSelfController.php` (+`avatarEmoji` no `/child/me`), `api/RestApi.php` (+`registerAvatarRoutes`), `public/app-child/src/api/types.ts` (+`avatarEmoji` no `Child`), `public/app-child/src/components/ProfileSheet.tsx` (botão + emoji), `public/app-child/src/components/Header.tsx` (passa onNavigate + emoji), `public/app-child/src/App.tsx` + `public/app-child/src/data/mockData.ts` (`PageId 'avatar'`), `public/app-child/src/components/Header.tsx` titles.

---

### Task 1: Migração 022 (ALTER progression) + DB v22

**Files:** Create `database/migrations/022_avatar.php`; Modify `guardkids.php`.

- [ ] **Step 1: Criar a migração.** Create `database/migrations/022_avatar.php`:

```php
<?php

declare(strict_types=1);

/**
 * Migration 022 — avatar (gamificação 3e). Adiciona equipped_avatar na
 * progression (a escolha do filho). ADD COLUMN não é idempotente → guard.
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $addColumnIfMissing = static function (string $table, string $col, string $def) use ($wpdb): void {
        $found = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $col));
        if ($found === null) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
        }
    };

    $addColumnIfMissing($wpdb->prefix . 'guardkids_progression', 'equipped_avatar', 'VARCHAR(40) NULL');
};
```

- [ ] **Step 2: Bump DB version.** Em `guardkids.php`, troque `define('GUARDKIDS_DB_VERSION', 21);` por `define('GUARDKIDS_DB_VERSION', 22);` (NÃO mexa em `GUARDKIDS_VERSION`).

- [ ] **Step 3: Lint.** `"$PHP82" -l database/migrations/022_avatar.php && "$PHP82" -l guardkids.php` → `No syntax errors detected` em cada.

- [ ] **Step 4: Suíte unit.** `phpunit` → PASS (baseline 511, sem novas falhas).

- [ ] **Step 5: Commit.**
```bash
git add database/migrations/022_avatar.php guardkids.php
git commit -m "feat(avatar): migração 022 progression.equipped_avatar + DB v22"
```

> Sem mudança no `uninstall.php` (a coluna some com a tabela `progression`, que já é dropada).

---

### Task 2: AvatarCatalog (definição pura dos 7 avatares)

**Files:** Create `includes/Avatars/AvatarCatalog.php`; Test `tests/Unit/Avatars/AvatarCatalogTest.php`.

- [ ] **Step 1: Escrever o teste que falha.** Create `tests/Unit/Avatars/AvatarCatalogTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Avatars;

use GuardKids\Avatars\AvatarCatalog;
use PHPUnit\Framework\TestCase;

final class AvatarCatalogTest extends TestCase
{
    public function testHasSevenAvatars(): void
    {
        $keys = array_column(AvatarCatalog::all(), 'key');
        self::assertSame(
            ['star', 'heart', 'rocket', 'crown', 'fire', 'book', 'trophy'],
            $keys,
        );
    }

    public function testGatesAndFields(): void
    {
        $byKey = [];
        foreach (AvatarCatalog::all() as $a) {
            self::assertArrayHasKey('emoji', $a);
            self::assertArrayHasKey('label', $a);
            self::assertContains($a['gate'], ['free', 'level', 'medal']);
            $byKey[$a['key']] = $a;
        }
        self::assertSame('free', $byKey['star']['gate']);
        self::assertSame('level', $byKey['rocket']['gate']);
        self::assertSame(5, $byKey['rocket']['threshold']);
        self::assertSame('level', $byKey['crown']['gate']);
        self::assertSame(10, $byKey['crown']['threshold']);
        self::assertSame('medal', $byKey['fire']['gate']);
        self::assertSame('faithful_7', $byKey['fire']['medalKey']);
        self::assertSame('devourer_50', $byKey['book']['medalKey']);
        self::assertSame('veteran_10', $byKey['trophy']['medalKey']);
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha.** `phpunit --filter AvatarCatalogTest` → FAIL ("Class AvatarCatalog not found").

- [ ] **Step 3: Implementar.** Create `includes/Avatars/AvatarCatalog.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Avatars;

/**
 * Catálogo dos avatares (puro, sem $wpdb). Cada um tem um `gate`
 * (free/level/medal) com `threshold` (nível) ou `medalKey` (medalha da 3c).
 */
final class AvatarCatalog
{
    /**
     * @return array<int, array{key:string, emoji:string, label:string, gate:string, threshold:int, medalKey:?string}>
     */
    public static function all(): array
    {
        return [
            ['key' => 'star',   'emoji' => '⭐', 'label' => 'Estrela', 'gate' => 'free',  'threshold' => 0,  'medalKey' => null],
            ['key' => 'heart',  'emoji' => '❤️', 'label' => 'Coração', 'gate' => 'free',  'threshold' => 0,  'medalKey' => null],
            ['key' => 'rocket', 'emoji' => '🚀', 'label' => 'Foguete', 'gate' => 'level', 'threshold' => 5,  'medalKey' => null],
            ['key' => 'crown',  'emoji' => '👑', 'label' => 'Coroa',   'gate' => 'level', 'threshold' => 10, 'medalKey' => null],
            ['key' => 'fire',   'emoji' => '🔥', 'label' => 'Chama',   'gate' => 'medal', 'threshold' => 0,  'medalKey' => 'faithful_7'],
            ['key' => 'book',   'emoji' => '📚', 'label' => 'Livro',   'gate' => 'medal', 'threshold' => 0,  'medalKey' => 'devourer_50'],
            ['key' => 'trophy', 'emoji' => '🏅', 'label' => 'Troféu',  'gate' => 'medal', 'threshold' => 0,  'medalKey' => 'veteran_10'],
        ];
    }
}
```

- [ ] **Step 4: Rodar e confirmar que passa.** `phpunit --filter AvatarCatalogTest` → PASS (2 tests).

- [ ] **Step 5: Commit.**
```bash
git add includes/Avatars/AvatarCatalog.php tests/Unit/Avatars/AvatarCatalogTest.php
git commit -m "feat(avatar): AvatarCatalog com os 7 avatares (gates nível/medalha)"
```

---

### Task 3: AvatarEvaluator (avaliação pura do desbloqueio)

**Files:** Create `includes/Avatars/AvatarEvaluator.php`; Test `tests/Unit/Avatars/AvatarEvaluatorTest.php`.

- [ ] **Step 1: Escrever o teste que falha.** Create `tests/Unit/Avatars/AvatarEvaluatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Avatars;

use GuardKids\Avatars\AvatarEvaluator;
use PHPUnit\Framework\TestCase;

final class AvatarEvaluatorTest extends TestCase
{
    /**
     * @param array{level:int, unlockedMedals:array<int,string>} $signals
     * @return array<string, mixed>
     */
    private function avatar(array $signals, string $key): array
    {
        foreach (AvatarEvaluator::evaluate($signals) as $a) {
            if ($a['key'] === $key) {
                return $a;
            }
        }
        self::fail("avatar {$key} not found");
    }

    public function testFreeAlwaysUnlocked(): void
    {
        $s = ['level' => 1, 'unlockedMedals' => []];
        self::assertTrue($this->avatar($s, 'star')['unlocked']);
        self::assertSame('Grátis', $this->avatar($s, 'star')['requirementLabel']);
    }

    public function testLevelGate(): void
    {
        self::assertFalse($this->avatar(['level' => 4, 'unlockedMedals' => []], 'rocket')['unlocked']);
        self::assertTrue($this->avatar(['level' => 5, 'unlockedMedals' => []], 'rocket')['unlocked']);
        self::assertSame('Nível 5', $this->avatar(['level' => 5, 'unlockedMedals' => []], 'rocket')['requirementLabel']);
    }

    public function testMedalGate(): void
    {
        $off = ['level' => 99, 'unlockedMedals' => []];
        $on  = ['level' => 1, 'unlockedMedals' => ['faithful_7']];
        self::assertFalse($this->avatar($off, 'fire')['unlocked']);
        self::assertTrue($this->avatar($on, 'fire')['unlocked']);
        // requirementLabel usa o label da medalha do MedalCatalog
        self::assertStringContainsString('Fiel', $this->avatar($on, 'fire')['requirementLabel']);
    }

    public function testEmojiPresent(): void
    {
        self::assertSame('⭐', $this->avatar(['level' => 1, 'unlockedMedals' => []], 'star')['emoji']);
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha.** `phpunit --filter AvatarEvaluatorTest` → FAIL ("Class AvatarEvaluator not found").

- [ ] **Step 3: Implementar.** Create `includes/Avatars/AvatarEvaluator.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Avatars;

use GuardKids\Medals\MedalCatalog;

/**
 * Avaliação pura: recebe nível + medalhas desbloqueadas e devolve cada avatar
 * com unlocked + requirementLabel. Não toca no banco.
 */
final class AvatarEvaluator
{
    /**
     * @param array{level:int, unlockedMedals:array<int,string>} $signals
     * @return array<int, array{key:string, emoji:string, label:string, gate:string, requirementLabel:string, unlocked:bool}>
     */
    public static function evaluate(array $signals): array
    {
        $level          = (int) ($signals['level'] ?? 0);
        $unlockedMedals = $signals['unlockedMedals'] ?? [];
        $medalLabels    = self::medalLabels();

        $out = [];
        foreach (AvatarCatalog::all() as $a) {
            if ($a['gate'] === 'free') {
                $unlocked = true;
                $label    = 'Grátis';
            } elseif ($a['gate'] === 'level') {
                $unlocked = $level >= $a['threshold'];
                $label    = 'Nível ' . $a['threshold'];
            } else {
                $unlocked = in_array($a['medalKey'], $unlockedMedals, true);
                $label    = 'Medalha ' . ($medalLabels[$a['medalKey']] ?? (string) $a['medalKey']);
            }
            $out[] = [
                'key'              => $a['key'],
                'emoji'            => $a['emoji'],
                'label'            => $a['label'],
                'gate'             => $a['gate'],
                'requirementLabel' => $label,
                'unlocked'         => $unlocked,
            ];
        }
        return $out;
    }

    /**
     * @return array<string, string>
     */
    private static function medalLabels(): array
    {
        $map = [];
        foreach (MedalCatalog::all() as $m) {
            $map[$m['key']] = $m['title'];
        }
        return $map;
    }
}
```

- [ ] **Step 4: Rodar e confirmar que passa.** `phpunit --filter AvatarEvaluatorTest` → PASS (4 tests).

- [ ] **Step 5: Commit.**
```bash
git add includes/Avatars/AvatarEvaluator.php tests/Unit/Avatars/AvatarEvaluatorTest.php
git commit -m "feat(avatar): AvatarEvaluator puro (nível+medalhas -> desbloqueio)"
```

---

### Task 4: Métodos de repo (setEquippedAvatar + unlockedKeys)

**Files:** Modify `database/ProgressionRepository.php`, `database/MedalUnlockRepository.php`; Test `tests/Unit/Database/AvatarRepoMethodsTest.php`.

- [ ] **Step 1: Escrever o teste que falha.** Create `tests/Unit/Database/AvatarRepoMethodsTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\MedalUnlockRepository;
use GuardKids\Database\ProgressionRepository;
use PHPUnit\Framework\TestCase;

final class AvatarRepoMethodsTest extends TestCase
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

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                $n = $this->nameOf((string) $table);
                $id = (int) ($where['id'] ?? 0);
                if (isset($this->t[$n][$id])) {
                    $this->t[$n][$id] = array_merge($this->t[$n][$id], $data);
                    return 1;
                }
                return 0;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $n = $this->nameOf((string) $sql);
                $rows = array_values($this->t[$n] ?? []);
                if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['child_id'] ?? 0) === (int) $m[1]));
                }
                return $rows;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testSetEquippedAvatarPersists(): void
    {
        $repo = new ProgressionRepository();
        $repo->setEquippedAvatar(1, 'rocket');
        $row = $repo->findByChild(1);
        self::assertSame('rocket', $row['equipped_avatar']);
    }

    public function testUnlockedKeys(): void
    {
        $this->wpdb->t['medal_unlocks'] = [
            1 => ['id' => 1, 'child_id' => 1, 'medal_key' => 'faithful_7'],
            2 => ['id' => 2, 'child_id' => 1, 'medal_key' => 'devourer_50'],
            3 => ['id' => 3, 'child_id' => 2, 'medal_key' => 'veteran_10'],
        ];
        $keys = (new MedalUnlockRepository())->unlockedKeys(1);
        sort($keys);
        self::assertSame(['devourer_50', 'faithful_7'], $keys);
        self::assertSame([], (new MedalUnlockRepository())->unlockedKeys(99));
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha.** `phpunit --filter AvatarRepoMethodsTest` → FAIL ("Call to undefined method ...setEquippedAvatar()").

- [ ] **Step 3a: Implementar `setEquippedAvatar`.** Em `database/ProgressionRepository.php`, adicione dentro da classe (após `apply` ou `spend`):

```php
    public function setEquippedAvatar(int $childId, string $avatarKey): void
    {
        $row = $this->ensure($childId);
        $this->update((int) $row['id'], ['equipped_avatar' => $avatarKey]);
    }
```

- [ ] **Step 3b: Implementar `unlockedKeys`.** Em `database/MedalUnlockRepository.php`, adicione dentro da classe:

```php
    /**
     * @return array<int, string>
     */
    public function unlockedKeys(int $childId): array
    {
        $sql = $this->db->prepare(
            'SELECT medal_key FROM ' . $this->table() . ' WHERE child_id = %d',
            $childId,
        );
        $rows = $this->db->get_results($sql, ARRAY_A);
        return is_array($rows)
            ? array_map(static fn ($r) => (string) $r['medal_key'], $rows)
            : [];
    }
```

- [ ] **Step 4: Rodar e confirmar que passa.** `phpunit --filter AvatarRepoMethodsTest` → PASS (2 tests).

- [ ] **Step 5: Commit.**
```bash
git add database/ProgressionRepository.php database/MedalUnlockRepository.php tests/Unit/Database/AvatarRepoMethodsTest.php
git commit -m "feat(avatar): ProgressionRepository::setEquippedAvatar + MedalUnlockRepository::unlockedKeys"
```

---

### Task 5: AvatarController + rotas

**Files:** Create `api/Controllers/AvatarController.php`; Modify `api/RestApi.php`; Test `tests/Unit/Api/AvatarControllerTest.php`.

- [ ] **Step 1: Escrever o teste que falha.** Create `tests/Unit/Api/AvatarControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\AvatarController;
use GuardKids\Auth\ChildAuth;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

final class AvatarControllerTest extends TestCase
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
                }
                return 1;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $n = $this->nameOf((string) $sql);
                $rows = array_values($this->t[$n] ?? []);
                if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['child_id'] ?? 0) === (int) $m[1]));
                }
                return $rows;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->token = (new ChildAuth())->issueToken(1, 'tablet')['token'];
    }

    private function tokenReq(string $method, string $route): WP_REST_Request
    {
        $req = new WP_REST_Request($method, $route);
        $req->set_header('X-GuardKids-Token', $this->token);
        return $req;
    }

    /** @param array<int,array<string,mixed>> $avatars */
    private function pick(array $avatars, string $key): array
    {
        return array_values(array_filter($avatars, static fn ($a) => $a['key'] === $key))[0];
    }

    public function testListReturns401WithoutToken(): void
    {
        $res = (new AvatarController())->childAvatars(new WP_REST_Request('GET', '/child/avatars'));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(401, $res->get_error_data()['status']);
    }

    public function testListDefaultsToStarAndComputesUnlocked(): void
    {
        // sem carteira → level 1, equipped 'star', só free desbloqueado
        $data = (new AvatarController())->childAvatars($this->tokenReq('GET', '/child/avatars'))->get_data();
        self::assertSame('star', $data['equipped']);
        self::assertTrue($this->pick($data['avatars'], 'star')['isEquipped']);
        self::assertTrue($this->pick($data['avatars'], 'star')['unlocked']);
        self::assertFalse($this->pick($data['avatars'], 'rocket')['unlocked']);
    }

    public function testEquipUnlockedPersists(): void
    {
        // level alto o suficiente pro rocket (nível 5 = xp >= 2000; usa 5000 p/ garantir)
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 1, 'xp' => 5000, 'coins' => 0, 'streak_days' => 0, 'equipped_avatar' => null],
        ];
        $req = $this->tokenReq('POST', '/child/avatar');
        $req->set_param('avatarKey', 'rocket');
        $data = (new AvatarController())->equip($req)->get_data();
        self::assertSame('rocket', $data['equipped']);
        self::assertSame('rocket', $this->wpdb->t['progression'][1]['equipped_avatar']);
    }

    public function testEquipLockedIsRejected(): void
    {
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 1, 'xp' => 0, 'coins' => 0, 'streak_days' => 0, 'equipped_avatar' => null],
        ];
        $req = $this->tokenReq('POST', '/child/avatar');
        $req->set_param('avatarKey', 'rocket'); // nível 5 não atingido
        $res = (new AvatarController())->equip($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(409, $res->get_error_data()['status']);
        self::assertSame('avatar_locked', $res->get_error_code());
    }

    public function testEquipUnknownKeyIs404(): void
    {
        $req = $this->tokenReq('POST', '/child/avatar');
        $req->set_param('avatarKey', 'dragon');
        $res = (new AvatarController())->equip($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(404, $res->get_error_data()['status']);
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha.** `phpunit --filter AvatarControllerTest` → FAIL ("Class ...AvatarController not found").

- [ ] **Step 3: Implementar o controller.** Create `api/Controllers/AvatarController.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Auth\ChildAuth;
use GuardKids\Avatars\AvatarCatalog;
use GuardKids\Avatars\AvatarEvaluator;
use GuardKids\Database\MedalUnlockRepository;
use GuardKids\Database\ProgressionRepository;
use GuardKids\Progression\LevelCurve;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Avatares do filho: listar (com desbloqueio derivado de nível+medalhas) e
 * equipar (só se desbloqueado). Cosmético, sem envolvimento dos pais.
 */
final class AvatarController
{
    private readonly ProgressionRepository $progression;
    private readonly MedalUnlockRepository $medals;
    private readonly ChildAuth $auth;

    public function __construct()
    {
        $this->progression = new ProgressionRepository();
        $this->medals      = new MedalUnlockRepository();
        $this->auth        = new ChildAuth();
    }

    public function childAvatars(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        $equipped = $this->equippedKey($childId);
        $avatars  = array_map(
            static function (array $a) use ($equipped): array {
                $a['isEquipped'] = $a['key'] === $equipped;
                return $a;
            },
            AvatarEvaluator::evaluate($this->signals($childId)),
        );
        return rest_ensure_response(['equipped' => $equipped, 'avatars' => $avatars]);
    }

    public function equip(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        $key = (string) $req->get_param('avatarKey');
        if (! in_array($key, array_column(AvatarCatalog::all(), 'key'), true)) {
            return new WP_Error('avatar_not_found', 'Avatar inexistente.', ['status' => 404]);
        }
        $target = null;
        foreach (AvatarEvaluator::evaluate($this->signals($childId)) as $a) {
            if ($a['key'] === $key) {
                $target = $a;
                break;
            }
        }
        if ($target === null || $target['unlocked'] === false) {
            return new WP_Error('avatar_locked', 'Esse avatar ainda está bloqueado.', ['status' => 409]);
        }
        $this->progression->setEquippedAvatar($childId, $key);
        return rest_ensure_response(['equipped' => $key]);
    }

    /**
     * @return array{level:int, unlockedMedals:array<int,string>}
     */
    private function signals(int $childId): array
    {
        $row = $this->progression->findByChild($childId);
        $xp  = $row !== null ? (int) $row['xp'] : 0;
        return [
            'level'          => LevelCurve::levelForXp($xp),
            'unlockedMedals' => $this->medals->unlockedKeys($childId),
        ];
    }

    private function equippedKey(int $childId): string
    {
        $row = $this->progression->findByChild($childId);
        $key = $row !== null ? ($row['equipped_avatar'] ?? null) : null;
        return is_string($key) && $key !== '' ? $key : 'star';
    }
}
```

- [ ] **Step 4: Registrar rotas.** Em `api/RestApi.php`: adicione o import `use GuardKids\Api\Controllers\AvatarController;`; em `registerRoutes()` após `$this->registerRewardsRoutes();` adicione `$this->registerAvatarRoutes();`; crie o método:
```php
    private function registerAvatarRoutes(): void
    {
        $controller = new AvatarController();
        register_rest_route(self::NAMESPACE, '/child/avatars', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'childAvatars'],
            'permission_callback' => (new ChildAuth())->requireToken(),
        ]);
        register_rest_route(self::NAMESPACE, '/child/avatar', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'equip'],
            'permission_callback' => (new ChildAuth())->requireToken(),
        ]);
    }
```

- [ ] **Step 5: Rodar o filtro + a suíte inteira.** `phpunit --filter AvatarControllerTest` → PASS (5 tests). Depois `phpunit` → PASS geral.

- [ ] **Step 6: Commit.**
```bash
git add api/Controllers/AvatarController.php api/RestApi.php tests/Unit/Api/AvatarControllerTest.php
git commit -m "feat(avatar): AvatarController + rotas /child/avatars e /child/avatar"
```

---

### Task 6: `avatarEmoji` no `/child/me`

**Files:** Modify `api/Controllers/ChildSelfController.php`; Test `tests/Unit/Api/ChildSelfControllerAvatarTest.php`.

- [ ] **Step 1: Escrever o teste que falha.** Create `tests/Unit/Api/ChildSelfControllerAvatarTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\ChildSelfController;
use GuardKids\Auth\ChildAuth;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

final class ChildSelfControllerAvatarTest extends TestCase
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
                return '0';
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
                if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['child_id'] ?? 0) === (int) $m[1]));
                }
                return $rows;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->token = (new ChildAuth())->issueToken(1, 'tablet')['token'];
        $this->wpdb->t['children'] = [
            1 => ['id' => 1, 'slug' => 'lucas', 'name' => 'Lucas', 'age' => 9, 'avatar_url' => null,
                   'daily_limit_minutes' => 60, 'bedtime_enabled' => 0, 'bedtime_start' => null,
                   'bedtime_end' => null, 'allowed_weekdays' => null, 'status' => 'active', 'device_name' => null],
        ];
    }

    private function meReq(): WP_REST_Request
    {
        $req = new WP_REST_Request('GET', '/child/me');
        $req->set_header('X-GuardKids-Token', $this->token);
        return $req;
    }

    public function testMeIncludesAvatarEmojiNullByDefault(): void
    {
        $data = (new ChildSelfController())->me($this->meReq())->get_data();
        self::assertArrayHasKey('avatarEmoji', $data);
        self::assertNull($data['avatarEmoji']);
    }

    public function testMeIncludesEquippedEmoji(): void
    {
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 1, 'xp' => 0, 'coins' => 0, 'streak_days' => 0, 'equipped_avatar' => 'rocket'],
        ];
        $data = (new ChildSelfController())->me($this->meReq())->get_data();
        self::assertSame('🚀', $data['avatarEmoji']);
    }
}
```

> Nota: o `me()` tem vários colaboradores (eventos/schedule/notifier). O fake acima devolve `'0'` no `get_var` default pros COUNTs. Se ele não bastar pra o `me()` montar a resposta, ESPELHE o fake de um teste existente que já exercita `me()` (procure em `tests/Unit/Api/` por um teste de ChildSelf/me com schedule — ex.: `ChildSelfMeScheduleTest`) e só acrescente as tabelas `children`/`progression` que este teste usa. Não invente comportamento — copie o fake que já funciona.

- [ ] **Step 2: Rodar e confirmar que falha.** `phpunit --filter ChildSelfControllerAvatarTest` → FAIL (chave `avatarEmoji` ausente / null esperado).

- [ ] **Step 3: Implementar.** Em `api/Controllers/ChildSelfController.php`:
(a) Adicione os imports no topo (junto aos outros `use`):
```php
use GuardKids\Avatars\AvatarCatalog;
use GuardKids\Database\ProgressionRepository;
```
(b) Adicione uma propriedade + init no construtor (leia o construtor e siga o padrão das outras deps):
```php
    private readonly ProgressionRepository $progression;
```
e no `__construct`, junto às outras: `$this->progression = new ProgressionRepository();`
(c) No método `me`, na montagem final do array de resposta, adicione a chave `'avatarEmoji'`:
```php
        return rest_ensure_response(
            $this->childToJson($row) + [
                'schedule'            => $schedule,
                'pinUnlockEnabled'    => $this->pinUnlockEnabled(),
                'unreadNotifications' => $this->notifications->unreadCount($childId),
                'avatarEmoji'         => $this->avatarEmoji($childId),
            ]
        );
```
(d) Adicione o método privado:
```php
    private function avatarEmoji(int $childId): ?string
    {
        $wallet = $this->progression->findByChild($childId);
        $key = $wallet !== null ? ($wallet['equipped_avatar'] ?? null) : null;
        if (! is_string($key) || $key === '') {
            return null;
        }
        foreach (AvatarCatalog::all() as $a) {
            if ($a['key'] === $key) {
                return $a['emoji'];
            }
        }
        return null;
    }
```

- [ ] **Step 4: Rodar o filtro + a suíte inteira.** `phpunit --filter ChildSelfControllerAvatarTest` → PASS (2 tests). Depois `phpunit` → PASS geral.

- [ ] **Step 5: Commit.**
```bash
git add api/Controllers/ChildSelfController.php tests/Unit/Api/ChildSelfControllerAvatarTest.php
git commit -m "feat(avatar): avatarEmoji no /child/me"
```

---

### Task 7: Front app-filho — api + página Avatar + entrada ProfileSheet + emoji

**Files:** Create `public/app-child/src/api/avatars.ts`, `public/app-child/src/pages/Avatar.tsx`, `public/app-child/src/pages/Avatar.test.tsx`; Modify `public/app-child/src/api/types.ts`, `public/app-child/src/components/ProfileSheet.tsx`, `public/app-child/src/components/Header.tsx`, `public/app-child/src/App.tsx`, `public/app-child/src/data/mockData.ts`.

- [ ] **Step 1: Api + tipo.** Create `public/app-child/src/api/avatars.ts`:

```ts
import { apiFetch } from './client';

export type Avatar = {
  key: string;
  emoji: string;
  label: string;
  requirementLabel: string;
  unlocked: boolean;
  isEquipped: boolean;
};

export function getAvatars(): Promise<{ equipped: string; avatars: Avatar[] }> {
  return apiFetch<{ equipped: string; avatars: Avatar[] }>('/child/avatars');
}

export function equipAvatar(avatarKey: string): Promise<{ equipped: string }> {
  return apiFetch<{ equipped: string }>('/child/avatar', {
    method: 'POST',
    body: JSON.stringify({ avatarKey }),
  });
}
```
Em `public/app-child/src/api/types.ts`, adicione `avatarEmoji?: string | null;` ao tipo `Child`.

- [ ] **Step 2: Escrever o teste da página que falha.** Create `public/app-child/src/pages/Avatar.test.tsx`:

```tsx
import { fireEvent, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { Avatar } from './Avatar';

const getAvatars = vi.fn();
const equipAvatar = vi.fn();
vi.mock('../api/avatars', () => ({
  getAvatars: () => getAvatars(),
  equipAvatar: (k: string) => equipAvatar(k),
}));

const payload = {
  equipped: 'star',
  avatars: [
    { key: 'star', emoji: '⭐', label: 'Estrela', requirementLabel: 'Grátis', unlocked: true, isEquipped: true },
    { key: 'rocket', emoji: '🚀', label: 'Foguete', requirementLabel: 'Nível 5', unlocked: false, isEquipped: false },
  ],
};

describe('Avatar', () => {
  afterEach(() => {
    getAvatars.mockReset();
    equipAvatar.mockReset();
  });

  it('mostra desbloqueados e bloqueados com requisito', async () => {
    getAvatars.mockResolvedValueOnce(payload);
    renderWithClient(<Avatar onNavigate={() => {}} />);
    expect(await screen.findByText('Estrela')).toBeInTheDocument();
    expect(screen.getByText('Nível 5')).toBeInTheDocument();
    expect(screen.getByTestId('avatar-locked-rocket')).toBeInTheDocument();
  });

  it('equipa ao tocar num desbloqueado', async () => {
    getAvatars.mockResolvedValue(payload);
    equipAvatar.mockResolvedValueOnce({ equipped: 'star' });
    renderWithClient(<Avatar onNavigate={() => {}} />);
    fireEvent.click(await screen.findByTestId('avatar-option-star'));
    expect(equipAvatar).toHaveBeenCalledWith('star');
  });
});
```

- [ ] **Step 3: Rodar e confirmar que falha.** `cd public/app-child && npx vitest run src/pages/Avatar.test.tsx` → FAIL (não encontra `./Avatar`).

- [ ] **Step 4: Implementar a página.** Create `public/app-child/src/pages/Avatar.tsx`:

```tsx
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { equipAvatar, getAvatars } from '../api/avatars';
import type { PageId } from '../data/mockData';
import { Icon } from '../components/Icon';

export function Avatar({ onNavigate }: { onNavigate: (page: PageId) => void }) {
  const qc = useQueryClient();
  const query = useQuery({ queryKey: ['child', 'avatars'], queryFn: getAvatars });

  const equipMut = useMutation({
    mutationFn: (key: string) => equipAvatar(key),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['child', 'avatars'] });
      qc.invalidateQueries({ queryKey: ['child', 'me'] });
    },
  });

  const avatars = query.data?.avatars ?? [];

  return (
    <main className="flex flex-1 flex-col gap-stack-lg px-container-padding-mobile py-stack-md">
      <button
        type="button"
        onClick={() => onNavigate('home')}
        className="flex items-center gap-1 self-start text-label-sm text-on-surface-variant"
      >
        <Icon name="arrow_back" className="text-base" /> Voltar
      </button>

      <h2 className="font-display text-headline-md font-bold text-on-surface">Meu Avatar</h2>

      <ul className="grid grid-cols-3 gap-4">
        {avatars.map((a) => (
          <li key={a.key} className="flex flex-col items-center gap-1 text-center">
            <button
              type="button"
              data-testid={a.unlocked ? `avatar-option-${a.key}` : `avatar-locked-${a.key}`}
              disabled={!a.unlocked || equipMut.isPending}
              onClick={() => a.unlocked && equipMut.mutate(a.key)}
              className={`relative flex h-16 w-16 items-center justify-center rounded-full text-3xl ${
                a.isEquipped ? 'ring-4 ring-primary' : ''
              } ${a.unlocked ? 'bg-surface-container' : 'bg-surface-variant opacity-40'}`}
            >
              <span>{a.emoji}</span>
              {!a.unlocked && (
                <span className="absolute -bottom-1 -right-1 rounded-full bg-surface p-0.5">
                  <Icon name="lock" className="text-sm text-on-surface-variant" filled />
                </span>
              )}
            </button>
            <span className="text-label-sm text-on-surface">{a.label}</span>
            {!a.unlocked && (
              <span className="text-label-sm text-on-surface-variant">{a.requirementLabel}</span>
            )}
          </li>
        ))}
      </ul>
    </main>
  );
}
```
NOTA: `PageId` no app-filho fica em `src/data/mockData.ts` (não em `App.tsx`) — o import acima usa `../data/mockData`.

- [ ] **Step 5: Rodar e confirmar que passa.** `cd public/app-child && npx vitest run src/pages/Avatar.test.tsx` → PASS (2 tests).

- [ ] **Step 6: Wire roteamento + PageId + entrada ProfileSheet + emoji.**
  1. Em `public/app-child/src/data/mockData.ts`: adicione `'avatar'` ao union `PageId`.
  2. Em `public/app-child/src/App.tsx`: import `Avatar` + `case 'avatar': return <Avatar onNavigate={setActivePage} />;` (siga o padrão do `case 'store'` já existente da 3d).
  3. Em `public/app-child/src/components/Header.tsx`: se houver um `Record<PageId, string>` de títulos, adicione a chave `avatar: 'Meu Avatar'` (pra o tsc não quebrar — mesma situação da 3d). Passe `onNavigate` ao `<ProfileSheet ... onNavigate={onNavigate} />`. Se o Header renderiza o ícone/avatar do perfil e tem acesso ao `child` (via a query `/child/me` que já usa), renderize `child.avatarEmoji` (como texto emoji) quando presente, caindo pro ícone atual.
  4. Em `public/app-child/src/components/ProfileSheet.tsx`: adicione a prop `onNavigate: (page: PageId) => void` ao tipo `ProfileSheetProps` (import `PageId` de `../data/mockData`). No bloco do avatar (linhas ~34-45), renderize `child.avatarEmoji` (num círculo, `text-3xl`) quando presente, antes do fallback `avatarUrl`/`account_circle`. Adicione um botão "Trocar avatar" que faz `onClose()` e depois `onNavigate('avatar')`.

- [ ] **Step 7: Suíte vitest + tsc + build.** `cd public/app-child && npx vitest run && npx tsc --noEmit && npx vite build` → todos PASS (baseline 108 + 2 = 110; ajuste testes existentes de Header/ProfileSheet se a mudança de props quebrar — ex.: passar `onNavigate={() => {}}` nos testes desses componentes); tsc limpo; build ok.

- [ ] **Step 8: Commit.**
```bash
git add public/app-child/src/api/avatars.ts public/app-child/src/api/types.ts public/app-child/src/pages/Avatar.tsx public/app-child/src/pages/Avatar.test.tsx public/app-child/src/App.tsx public/app-child/src/data/mockData.ts public/app-child/src/components/Header.tsx public/app-child/src/components/ProfileSheet.tsx
git commit -m "feat(avatar): página Avatar no app-filho + entrada no ProfileSheet + emoji no Header"
```

---

### Task 8: Verificação final

- [ ] **Step 1: Suíte PHP unit.** `phpunit` → PASS (baseline 511 + novos das Tasks 2–6). Zero falhas.
- [ ] **Step 2: Vitest app-filho + e2e.** `cd public/app-child && npx vitest run` (110) e `npx playwright test` (3). A tela Avatar não está na Home → e2e intacto.
- [ ] **Step 3: app-parent** (sem mudança nesta fatia, mas confirmar). `cd public/app-parent && npx vitest run` → 303.
- [ ] **Step 4: Árvore + histórico.** `git status -sb && git log --oneline -9`.

> **Fora do escopo** (feito na sessão): PR, merge, release v1.33.0, deploy SSH, atualizar memória.

---

## Notas de verificação do plano vs. spec

- **Migração 022 (ALTER progression +equipped_avatar) + DB v22** → Task 1. ✅
- **`AvatarCatalog` (7, gates) puro** → Task 2. ✅
- **`AvatarEvaluator` puro (free/level/medal + requirementLabel)** → Task 3. ✅
- **`setEquippedAvatar` + `unlockedKeys`** → Task 4. ✅
- **`AvatarController` + rotas /child/avatars e /child/avatar** → Task 5. ✅
- **`avatarEmoji` no /child/me** → Task 6. ✅
- **App-filho: página Avatar + ProfileSheet + emoji Header** → Task 7. ✅
- **Testes: catalog/evaluator/repos/controller/me + vitest** → Tasks 2–7. ✅
- **Sem cron; sem tabela nova; app-pais fora de escopo** → respeitado. ✅
