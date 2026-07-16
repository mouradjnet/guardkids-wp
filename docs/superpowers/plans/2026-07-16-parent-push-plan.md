# Web Push pro Guardião — Implementation Plan (v1.36.0)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fazer o guardião receber Web Push quando a criança pede acesso, esgota o tempo, tenta acessar bloqueado, ou pareia um aparelho novo.

**Architecture:** Abordagem A do spec — tabelas paralelas às da criança, puramente aditivo. Duas tabelas novas (subscriptions do guardião + dedupe por evento), `PushSender::sendToGuardians()` reusando o `sendOne()` intacto, um `GuardianNotifier` como funil único, rotas `/guardian/push/*`, e no front um `sw.js` em JS puro (sem Workbox) mais o toggle `notifications.push` destravado. Nenhuma linha do caminho de push da criança muda.

**Tech Stack:** PHP 8.2+, WP 6.4+, `$wpdb` direto, PHPUnit 9.6, React 19 + TypeScript + Vite, TanStack Query v5, Vitest.

**Spec:** `docs/superpowers/specs/2026-07-16-parent-push-design.md`
**Branch:** `feat/guardian-push` (já criada, spec commitado em `847584a`)

---

## Comandos que você vai usar o tempo todo

**PHPUnit** (o `php` do PATH é 8.1 e quebra com return types 8.2+; use o do LocalWP):

```bash
PHP="/c/Users/mysho/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe"
INI=$(ls /c/Users/mysho/AppData/Roaming/Local/run/*/conf/php/php.ini | head -1)
"$PHP" -c "$INI" vendor/bin/phpunit --testsuite unit --filter <NomeDoTeste>
```

O nome da testsuite é minúsculo (`unit`). Rodar a suíte inteira: omita `--filter`.

**Vitest** (app-parent):

```bash
cd public/app-parent && pnpm test
```

## A linha de base da suíte: 547 testes, 8 vermelhos que NÃO são seus

Antes de começar, rode a suíte inteira e guarde este número:

```
Tests: 547, Assertions: 1530, Errors: 6, Failures: 2.
```

Esses 8 já estão vermelhos no `master` e **não são regressão sua**. Todos são o mesmo gotcha: o openssl do Windows não gera chave EC (`RuntimeException: Falha ao gerar chave EC`). No CI Linux e em produção (PHP 8.3) eles passam.

| Vermelho pré-existente | Por quê |
|---|---|
| `EcKeysTest` (3 erros) | gera chave EC de verdade |
| `VapidTest` (2 erros) | idem |
| `ChildSelfControllerTest::testPushKeyReturnsVapidPublic` (1 erro) | `new VapidKeys()` → `ensure()` → gera |
| `PushSenderTest::testSendPostsWithVapidAndAes128gcmHeaders` (1 falha) | `Payload::encrypt` gera chave efêmera |
| `PushSenderTest::testGoneResponseRemovesSubscription` (1 falha) | idem |

**Os testes deste plano são desenhados pra ficar 100% verdes na sua máquina** — nenhum deles toca crypto real. Se algum teste novo seu aparecer vermelho localmente, é bug seu, não o gotcha. Se o total de vermelhos passar de 8, você quebrou algo.

Os dois truques que tornam isso possível, e que você vai ver em uso nas tasks:

1. **`VapidKeys::ensure()` retorna cedo se a option já existe** (`VapidKeys.php:31`). Semeando `$GLOBALS['gk_options']['guardkids_vapid_public']`, nenhuma chave é gerada. É por isso que o teste do `pushKey` do guardião passa onde o da criança falha.
2. **`PushSender` não é `final`** (`Vapid` e `Payload` são). Dá pra estender e gravar as chamadas, sem chegar perto do crypto.

---

## Estrutura de arquivos

**Criar:**

| Arquivo | Responsabilidade |
|---|---|
| `database/migrations/024_guardian_push.php` | as 2 tabelas novas |
| `database/GuardianPushSubscriptionRepository.php` | acesso a dados das subscriptions do guardião |
| `database/GuardianPushDedupRepository.php` | dedupe por evento (`createIfAbsent`) |
| `includes/Notifications/GuardianNotifier.php` | funil único dos 4 eventos |
| `api/Controllers/GuardianPushController.php` | key / subscribe / unsubscribe |
| `public/app-parent/public/sw.js` | listeners `push` + `notificationclick` |
| `public/app-parent/public/manifest.webmanifest` | instalabilidade (destrava iOS) |
| `public/app-parent/src/lib/push.ts` | subscribe/unsubscribe no browser |
| `tests/Unit/Database/GuardianPushSubscriptionRepositoryTest.php` | |
| `tests/Unit/Database/GuardianPushDedupRepositoryTest.php` | |
| `tests/Unit/Auth/GuardianAuthIsActiveGuardianTest.php` | |
| `tests/Unit/Notifications/GuardianNotifierTest.php` | |
| `tests/Unit/Notifications/WebPush/PushSenderGuardiansTest.php` | |
| `tests/Unit/Api/GuardianPushControllerTest.php` | |
| `public/app-parent/src/lib/push.test.ts` | |

**Modificar:**

| Arquivo | Mudança |
|---|---|
| `guardkids.php` | `GUARDKIDS_DB_VERSION` 23→24; `Version:` 1.35.0→1.36.0 |
| `uninstall.php` | drop das 2 tabelas |
| `includes/Auth/GuardianAuth.php` | `+ isActiveGuardian()` |
| `includes/Notifications/WebPush/PushSender.php` | `+ sendToGuardians()`, `+ guardianSubscriptions()` |
| `api/RestApi.php` | 3 rotas novas |
| `includes/Ui/ParentApp.php` | injeta `swUrl`; linka manifest |
| `public/app-parent/src/pages/Settings.tsx` | destrava `notifications.push` |
| `api/Controllers/ChildSelfController.php` | gatilhos: `requestsCreate`, `eventsCreate` |
| `api/Controllers/ChildController.php` | gatilho: `pair` |
| `includes/Maintenance/Purger.php` | expurgo do dedupe > 30d |
| `tests/bootstrap.php` | stub de `user_can` |

---

## Task 1: Migração 024 — as duas tabelas

**Files:**
- Create: `database/migrations/024_guardian_push.php`
- Modify: `guardkids.php` (constante `GUARDKIDS_DB_VERSION`)
- Modify: `uninstall.php`

**Contexto que você precisa:** `dbDelta` é proibido neste projeto — ele já causou um no-op silencioso em produção (migração 003) que deixou colunas sem criar. Use `$wpdb->query` com `CREATE TABLE IF NOT EXISTS`, como a 015 faz. E **bumpar `GUARDKIDS_DB_VERSION` no mesmo commit é obrigatório**: sem isso o `maybeRunMigrations` pula a migração e ela nunca roda.

- [ ] **Step 1: Escreva a migração**

Crie `database/migrations/024_guardian_push.php`:

```php
<?php

declare(strict_types=1);

/**
 * Migration 024 — Web Push do guardião: subscriptions + dedupe por evento.
 *
 * Tabelas paralelas às da criança (015). Não toca push_subscriptions.
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $subs = $wpdb->prefix . 'guardkids_guardian_push_subscriptions';
    $dedup = $wpdb->prefix . 'guardkids_guardian_push_dedup';

    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS {$subs} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT UNSIGNED NOT NULL,
            endpoint   VARCHAR(512) NOT NULL,
            p256dh     VARCHAR(255) NOT NULL,
            auth       VARCHAR(255) NOT NULL,
            created_at DATETIME     NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY endpoint_unq (endpoint(191)),
            KEY wp_user (wp_user_id)
        ) {$charsetCollate};"
    );

    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS {$dedup} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            dedup_key  VARCHAR(191) NOT NULL,
            created_at DATETIME     NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY dedup_unq (dedup_key)
        ) {$charsetCollate};"
    );
};
```

- [ ] **Step 2: Bumpe a DB version**

Em `guardkids.php`, troque:

```php
define('GUARDKIDS_DB_VERSION', 23);
```

por:

```php
define('GUARDKIDS_DB_VERSION', 24);
```

- [ ] **Step 3: Drop no uninstall**

Em `uninstall.php`, no array `$tables` (linha 16), acrescente as duas linhas logo depois de
`guardkids_push_subscriptions` (linha 28):

```php
    $wpdb->prefix . 'guardkids_push_subscriptions',
    $wpdb->prefix . 'guardkids_guardian_push_subscriptions',
    $wpdb->prefix . 'guardkids_guardian_push_dedup',
```

O `foreach` logo abaixo já faz o `DROP TABLE IF EXISTS` — nada mais a mudar.

- [ ] **Step 4: Rode a suíte pra garantir que nada quebrou**

```bash
"$PHP" -c "$INI" vendor/bin/phpunit --testsuite unit --filter MigrationRunner
```

Esperado: PASS. O `MigrationRunnerTest` varre o diretório de migrações; se o arquivo novo tiver erro de sintaxe ou não devolver um callable, ele acusa aqui.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/024_guardian_push.php guardkids.php uninstall.php
git commit -m "feat(push): migração 024 — tabelas de push do guardião (DB v24)"
```

---

## Task 2: GuardianPushSubscriptionRepository

**Files:**
- Create: `database/GuardianPushSubscriptionRepository.php`
- Test: `tests/Unit/Database/GuardianPushSubscriptionRepositoryTest.php`

**Contexto que você precisa:** **não use `$this->insert()` da base.** A `Repository::insert()` grava `updated_at`, coluna que estas tabelas não têm — o insert falharia. O `PushSubscriptionRepository` já resolve isso chamando `$this->db->insert()` direto; espelhe. `findAll()` vem de graça da base.

- [ ] **Step 1: Escreva o teste que falha**

Crie `tests/Unit/Database/GuardianPushSubscriptionRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\GuardianPushSubscriptionRepository;
use PHPUnit\Framework\TestCase;

final class GuardianPushSubscriptionRepositoryTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $seed = [];

    private function bootWpdb(): \wpdb
    {
        $test = $this;
        $wpdb = new class ($test) extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];
            /** @var array<int, array<string, mixed>> */
            public array $updates = [];

            public function __construct(private object $t)
            {
            }

            public function prepare($query, ...$args)
            {
                $flat = $args[0] ?? null;
                if (is_array($flat)) {
                    $args = $flat;
                }
                return vsprintf(str_replace(['%d', '%s'], ['%d', "'%s'"], (string) $query), $args);
            }

            public function get_results($sql, $output = ARRAY_A)
            {
                if (preg_match("/endpoint = '([^']*)'/", (string) $sql, $m) === 1) {
                    return array_values(array_filter(
                        $this->rows,
                        static fn (array $r): bool => (string) $r['endpoint'] === $m[1],
                    ));
                }
                return array_values($this->rows);
            }

            public function insert($table, $data, $format = null)
            {
                $this->insert_id = count($this->rows) + 1;
                $this->rows[$this->insert_id] = array_merge(['id' => $this->insert_id], $data);
                return 1;
            }

            public function update($table, $data, $where, $f = null, $wf = null)
            {
                $this->updates[] = ['data' => $data, 'where' => $where];
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $wpdb;
        return $wpdb;
    }

    public function testUpsertInsertsWhenEndpointIsNew(): void
    {
        $wpdb = $this->bootWpdb();

        (new GuardianPushSubscriptionRepository())
            ->upsertByEndpoint(7, 'https://fcm.example/abc', 'P256', 'AUTH');

        self::assertCount(1, $wpdb->rows);
        $row = $wpdb->rows[1];
        self::assertSame(7, $row['wp_user_id']);
        self::assertSame('https://fcm.example/abc', $row['endpoint']);
        self::assertArrayHasKey('created_at', $row);
        self::assertArrayNotHasKey('updated_at', $row, 'a tabela não tem updated_at');
    }

    public function testUpsertUpdatesWhenEndpointExists(): void
    {
        $wpdb = $this->bootWpdb();
        $wpdb->rows[1] = [
            'id' => 1, 'wp_user_id' => 7, 'endpoint' => 'https://fcm.example/abc',
            'p256dh' => 'OLD', 'auth' => 'OLD', 'created_at' => '2026-01-01 00:00:00',
        ];

        (new GuardianPushSubscriptionRepository())
            ->upsertByEndpoint(9, 'https://fcm.example/abc', 'NEW', 'NEWAUTH');

        self::assertCount(1, $wpdb->rows, 'não pode inserir duplicado');
        self::assertCount(1, $wpdb->updates);
        self::assertSame(9, $wpdb->updates[0]['data']['wp_user_id']);
        self::assertSame('NEW', $wpdb->updates[0]['data']['p256dh']);
        self::assertSame(['id' => 1], $wpdb->updates[0]['where']);
    }
}
```

- [ ] **Step 2: Rode e veja falhar**

```bash
"$PHP" -c "$INI" vendor/bin/phpunit --testsuite unit --filter GuardianPushSubscriptionRepository
```

Esperado: FAIL — `Class "GuardKids\Database\GuardianPushSubscriptionRepository" not found`.

- [ ] **Step 3: Implemente**

Crie `database/GuardianPushSubscriptionRepository.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Database;

/**
 * Subscriptions de Web Push dos guardiões. Paralela à PushSubscriptionRepository
 * (que serve a criança) — modelos de auth diferentes, tabelas diferentes.
 *
 * Repo burro de propósito: quem PODE receber push é decisão de autorização e
 * mora em GuardianAuth::isActiveGuardian(), não aqui.
 */
final class GuardianPushSubscriptionRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'guardian_push_subscriptions';
    }

    public function upsertByEndpoint(int $wpUserId, string $endpoint, string $p256dh, string $auth): void
    {
        $existing = $this->findWhere(['endpoint' => $endpoint]);
        if ($existing !== []) {
            // $this->db->update direto: a base grava updated_at, coluna que
            // esta tabela não tem.
            $this->db->update(
                $this->table(),
                ['wp_user_id' => $wpUserId, 'p256dh' => $p256dh, 'auth' => $auth],
                ['id' => (int) $existing[0]['id']],
            );
            return;
        }
        $this->db->insert($this->table(), [
            'wp_user_id' => $wpUserId,
            'endpoint'   => $endpoint,
            'p256dh'     => $p256dh,
            'auth'       => $auth,
            'created_at' => current_time('mysql', true),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByUser(int $wpUserId): array
    {
        return $this->findWhere(['wp_user_id' => $wpUserId]);
    }

    public function deleteByEndpoint(string $endpoint): void
    {
        $sql = $this->db->prepare(
            'DELETE FROM ' . $this->table() . ' WHERE endpoint = %s',
            $endpoint,
        );
        $this->db->query($sql);
    }
}
```

- [ ] **Step 4: Rode e veja passar**

```bash
"$PHP" -c "$INI" vendor/bin/phpunit --testsuite unit --filter GuardianPushSubscriptionRepository
```

Esperado: `OK (2 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add database/GuardianPushSubscriptionRepository.php tests/Unit/Database/GuardianPushSubscriptionRepositoryTest.php
git commit -m "feat(push): GuardianPushSubscriptionRepository"
```

---

## Task 3: GuardianPushDedupRepository

**Files:**
- Create: `database/GuardianPushDedupRepository.php`
- Test: `tests/Unit/Database/GuardianPushDedupRepositoryTest.php`

**Contexto que você precisa:** este repo é o coração da fatia — é ele que impede o guardião de ser bombardeado. `createIfAbsent` devolve `true` só na primeira vez que uma chave aparece.

- [ ] **Step 1: Escreva o teste que falha**

Crie `tests/Unit/Database/GuardianPushDedupRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\GuardianPushDedupRepository;
use PHPUnit\Framework\TestCase;

final class GuardianPushDedupRepositoryTest extends TestCase
{
    private function bootWpdb(): \wpdb
    {
        $wpdb = new class () extends \wpdb {
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
                return vsprintf(str_replace(['%d', '%s'], ['%d', "'%s'"], (string) $query), $args);
            }

            public function get_results($sql, $output = ARRAY_A)
            {
                if (preg_match("/dedup_key = '([^']*)'/", (string) $sql, $m) === 1) {
                    return array_values(array_filter(
                        $this->rows,
                        static fn (array $r): bool => (string) $r['dedup_key'] === $m[1],
                    ));
                }
                return array_values($this->rows);
            }

            public function insert($table, $data, $format = null)
            {
                $this->insert_id = count($this->rows) + 1;
                $this->rows[$this->insert_id] = array_merge(['id' => $this->insert_id], $data);
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $wpdb;
        return $wpdb;
    }

    public function testFirstCallCreatesAndReturnsTrue(): void
    {
        $wpdb = $this->bootWpdb();

        $created = (new GuardianPushDedupRepository())->createIfAbsent('req:42');

        self::assertTrue($created);
        self::assertCount(1, $wpdb->rows);
        self::assertSame('req:42', $wpdb->rows[1]['dedup_key']);
    }

    public function testSecondCallWithSameKeyReturnsFalseAndDoesNotInsert(): void
    {
        $wpdb = $this->bootWpdb();
        $repo = new GuardianPushDedupRepository();

        $repo->createIfAbsent('req:42');
        $second = $repo->createIfAbsent('req:42');

        self::assertFalse($second, 'a segunda vez não pode reenviar');
        self::assertCount(1, $wpdb->rows);
    }

    public function testDifferentKeysBothCreate(): void
    {
        $wpdb = $this->bootWpdb();
        $repo = new GuardianPushDedupRepository();

        self::assertTrue($repo->createIfAbsent('req:1'));
        self::assertTrue($repo->createIfAbsent('req:2'));
        self::assertCount(2, $wpdb->rows);
    }
}
```

- [ ] **Step 2: Rode e veja falhar**

```bash
"$PHP" -c "$INI" vendor/bin/phpunit --testsuite unit --filter GuardianPushDedupRepository
```

Esperado: FAIL — classe não encontrada.

- [ ] **Step 3: Implemente**

Crie `database/GuardianPushDedupRepository.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Database;

/**
 * Dedupe dos pushes do guardião, por EVENTO (não por destinatário): o evento
 * aconteceu uma vez, anuncia-se uma vez pra todos os guardiões ativos.
 *
 * Mesma semântica do NotificationRepository::createIfAbsent, sem o feed.
 */
final class GuardianPushDedupRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'guardian_push_dedup';
    }

    /**
     * @return bool true se a chave é nova (logo: deve enviar).
     */
    public function createIfAbsent(string $dedupKey): bool
    {
        if ($this->findWhere(['dedup_key' => $dedupKey]) !== []) {
            return false;
        }
        // $this->db->insert direto: a base grava updated_at, coluna ausente aqui.
        $ok = $this->db->insert($this->table(), [
            'dedup_key'  => $dedupKey,
            'created_at' => current_time('mysql', true),
        ]);
        return $ok !== false;
    }
}
```

- [ ] **Step 4: Rode e veja passar**

```bash
"$PHP" -c "$INI" vendor/bin/phpunit --testsuite unit --filter GuardianPushDedupRepository
```

Esperado: `OK (3 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add database/GuardianPushDedupRepository.php tests/Unit/Database/GuardianPushDedupRepositoryTest.php
git commit -m "feat(push): GuardianPushDedupRepository — dedupe por evento"
```

---

## Task 4: GuardianAuth::isActiveGuardian

**Files:**
- Modify: `includes/Auth/GuardianAuth.php`
- Modify: `tests/bootstrap.php` (stub de `user_can`)
- Test: `tests/Unit/Auth/GuardianAuthIsActiveGuardianTest.php`

**Contexto que você precisa — leia antes de codar.** Esta task existe por causa de um furo que quase passou. `GuardianAuth::currentRole()` devolve `'admin'` para **qualquer usuário com `manage_options`, tenha ou não linha na tabela `guardians`** (é o comportamento documentado na classe: "WP `manage_options` é autoridade final"). Mas `GuardianRepository::findActive()` só enxerga quem tem linha. Se o envio resolvesse destinatários por `findActive()`, **o admin WP dono da instalação nunca receberia push** — e o sintoma ("liguei o toggle e não chega nada") seria indistinguível de bug de infra.

Duas diferenças deliberadas em relação ao `currentRole()`:
- usa `user_can($id, ...)` e **não** `current_user_can()`, porque no momento do envio quem fez a request foi a **criança** — não há guardião logado;
- **não** faz o fallback por email, porque a subscription sempre grava um `wp_user_id` real.

`user_can` **não existe** no `tests/bootstrap.php` hoje (só `current_user_can`). Você vai adicioná-lo.

- [ ] **Step 1: Adicione o stub de `user_can` ao bootstrap**

Em `tests/bootstrap.php`, logo após o bloco `if (! function_exists('current_user_can'))` (por volta da linha 465), acrescente:

```php
if (! function_exists('user_can')) {
    function user_can($user, string $cap): bool
    {
        $id = is_object($user) ? (int) ($user->ID ?? 0) : (int) $user;
        return (bool) ($GLOBALS['gk_caps_by_user'][$id][$cap] ?? false);
    }
}
$GLOBALS['gk_caps_by_user'] = [];
```

- [ ] **Step 2: Escreva o teste que falha**

Crie `tests/Unit/Auth/GuardianAuthIsActiveGuardianTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Auth;

use GuardKids\Auth\GuardianAuth;
use GuardKids\Database\GuardianRepository;
use PHPUnit\Framework\TestCase;

final class GuardianAuthIsActiveGuardianTest extends TestCase
{
    /**
     * @param array<int, array<string, mixed>> $guardianRows
     */
    private function bootWpdb(array $guardianRows): void
    {
        $wpdb = new class ($guardianRows) extends \wpdb {
            public string $prefix = 'wp_';

            /** @param array<int, array<string, mixed>> $rows */
            public function __construct(private array $rows)
            {
            }

            public function prepare($query, ...$args)
            {
                $flat = $args[0] ?? null;
                if (is_array($flat)) {
                    $args = $flat;
                }
                return vsprintf(str_replace(['%d', '%s'], ['%d', "'%s'"], (string) $query), $args);
            }

            public function get_results($sql, $output = ARRAY_A)
            {
                if (preg_match('/wp_user_id = (\d+)/', (string) $sql, $m) === 1) {
                    return array_values(array_filter(
                        $this->rows,
                        static fn (array $r): bool => (int) ($r['wp_user_id'] ?? 0) === (int) $m[1],
                    ));
                }
                return [];
            }
        };
        $GLOBALS['wpdb'] = $wpdb;
    }

    protected function setUp(): void
    {
        $GLOBALS['gk_caps_by_user'] = [];
    }

    /** O caso que quase escapou do design. */
    public function testWpAdminWithoutGuardianRowIsActive(): void
    {
        $this->bootWpdb([]);
        $GLOBALS['gk_caps_by_user'][1] = ['manage_options' => true];

        self::assertTrue(GuardianAuth::isActiveGuardian(1, new GuardianRepository()));
    }

    public function testActiveGuardianRowIsActive(): void
    {
        $this->bootWpdb([['wp_user_id' => 5, 'role' => 'collaborator', 'status' => 'active']]);

        self::assertTrue(GuardianAuth::isActiveGuardian(5, new GuardianRepository()));
    }

    public function testInactiveGuardianRowIsNotActive(): void
    {
        $this->bootWpdb([['wp_user_id' => 5, 'role' => 'collaborator', 'status' => 'pending']]);

        self::assertFalse(GuardianAuth::isActiveGuardian(5, new GuardianRepository()));
    }

    public function testUnknownUserIsNotActive(): void
    {
        $this->bootWpdb([]);

        self::assertFalse(GuardianAuth::isActiveGuardian(99, new GuardianRepository()));
    }
}
```

- [ ] **Step 3: Rode e veja falhar**

```bash
"$PHP" -c "$INI" vendor/bin/phpunit --testsuite unit --filter GuardianAuthIsActiveGuardian
```

Esperado: FAIL — `Call to undefined method GuardKids\Auth\GuardianAuth::isActiveGuardian()`.

- [ ] **Step 4: Implemente**

Em `includes/Auth/GuardianAuth.php`, adicione o método depois de `currentRole()`:

```php
    /**
     * Um usuário arbitrário pode receber push de guardião?
     *
     * Espelha currentRole(), mas pra um user id qualquer — no momento do envio
     * quem fez a request foi a CRIANÇA, então não há usuário logado e
     * current_user_can() não serve.
     *
     * Inclui o admin WP sem linha em `guardians` de propósito: currentRole()
     * dá 'admin' por manage_options, então resolver destinatários só por
     * GuardianRepository::findActive() deixaria o dono da instalação sem push.
     */
    public static function isActiveGuardian(int $wpUserId, ?GuardianRepository $repo = null): bool
    {
        if ($wpUserId <= 0) {
            return false;
        }

        if (function_exists('user_can') && user_can($wpUserId, 'manage_options')) {
            return true;
        }

        $repo ??= new GuardianRepository();
        $row = $repo->findByWpUserId($wpUserId);

        return $row !== null && ($row['status'] ?? '') === 'active';
    }
```

- [ ] **Step 5: Rode e veja passar**

```bash
"$PHP" -c "$INI" vendor/bin/phpunit --testsuite unit --filter GuardianAuthIsActiveGuardian
```

Esperado: `OK (4 tests, ...)`.

- [ ] **Step 6: Rode a suíte inteira — o bootstrap mudou**

```bash
"$PHP" -c "$INI" vendor/bin/phpunit --testsuite unit
```

Esperado: `Errors: 6, Failures: 2` (os 8 pré-existentes, nada a mais). Você tocou o bootstrap, que todos os testes carregam — se subir de 8, o stub novo colidiu com algo.

- [ ] **Step 7: Commit**

```bash
git add includes/Auth/GuardianAuth.php tests/bootstrap.php tests/Unit/Auth/GuardianAuthIsActiveGuardianTest.php
git commit -m "feat(push): GuardianAuth::isActiveGuardian — inclui admin WP sem linha em guardians"
```

---

## Task 5: PushSender::sendToGuardians

**Files:**
- Modify: `includes/Notifications/WebPush/PushSender.php`
- Test: `tests/Unit/Notifications/WebPush/PushSenderGuardiansTest.php`

**Contexto que você precisa:** o `sendOne()` já é genérico (recebe endpoint/p256dh/auth) e já limpa endpoint morto em 404/410 — **não toque nele, nem no `sendToChild`**.

O filtro de autorização sai num método público separado (`guardianSubscriptions()`) **de propósito**: é a única parte testável sem crypto. O `PushSenderTest` existente é vermelho na sua máquina justamente por chamar o caminho completo, que passa por `Payload::encrypt` → chave EC → `RuntimeException`. Testando só o filtro, seu teste fica verde local e no CI.

- [ ] **Step 1: Escreva o teste que falha**

Crie `tests/Unit/Notifications/WebPush/PushSenderGuardiansTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Notifications\WebPush;

use GuardKids\Database\GuardianPushSubscriptionRepository;
use GuardKids\Notifications\WebPush\PushSender;
use PHPUnit\Framework\TestCase;

/**
 * Cobre só a SELEÇÃO de destinatários — não o envio. O envio passa por
 * Payload::encrypt, que gera chave EC e estoura no openssl do Windows (é o
 * gotcha que já deixa PushSenderTest vermelho local). Manter este teste longe
 * do crypto é o que o mantém verde nas duas pontas.
 */
final class PushSenderGuardiansTest extends TestCase
{
    /** @param array<int, array<string, mixed>> $subs */
    private function bootWpdb(array $subs): void
    {
        $wpdb = new class ($subs) extends \wpdb {
            public string $prefix = 'wp_';

            /** @param array<int, array<string, mixed>> $subs */
            public function __construct(private array $subs)
            {
            }

            public function prepare($query, ...$args)
            {
                $flat = $args[0] ?? null;
                if (is_array($flat)) {
                    $args = $flat;
                }
                return vsprintf(str_replace(['%d', '%s'], ['%d', "'%s'"], (string) $query), $args);
            }

            public function get_results($sql, $output = ARRAY_A)
            {
                // guardians: nenhuma linha (todos os ativos deste teste são
                // admins WP), subscriptions: as semeadas.
                if (str_contains((string) $sql, 'guardian_push_subscriptions')) {
                    return $this->subs;
                }
                return [];
            }
        };
        $GLOBALS['wpdb'] = $wpdb;
    }

    protected function setUp(): void
    {
        $GLOBALS['gk_caps_by_user'] = [];
    }

    public function testKeepsSubscriptionsOfActiveGuardians(): void
    {
        $this->bootWpdb([
            ['id' => 1, 'wp_user_id' => 1, 'endpoint' => 'https://a', 'p256dh' => 'P', 'auth' => 'A'],
        ]);
        $GLOBALS['gk_caps_by_user'][1] = ['manage_options' => true];

        $subs = (new PushSender())->guardianSubscriptions();

        self::assertCount(1, $subs);
        self::assertSame('https://a', $subs[0]['endpoint']);
    }

    public function testSkipsSubscriptionsOfWhoIsNoLongerGuardian(): void
    {
        $this->bootWpdb([
            ['id' => 1, 'wp_user_id' => 1, 'endpoint' => 'https://a', 'p256dh' => 'P', 'auth' => 'A'],
            ['id' => 2, 'wp_user_id' => 42, 'endpoint' => 'https://b', 'p256dh' => 'P', 'auth' => 'A'],
        ]);
        // Só o user 1 é admin. O 42 saiu do time: sem cap, sem linha ativa.
        $GLOBALS['gk_caps_by_user'][1] = ['manage_options' => true];

        $subs = (new PushSender())->guardianSubscriptions();

        self::assertCount(1, $subs);
        self::assertSame(1, (int) $subs[0]['wp_user_id']);
    }

    public function testEmptyWhenNobodySubscribed(): void
    {
        $this->bootWpdb([]);

        self::assertSame([], (new PushSender())->guardianSubscriptions());
    }
}
```

- [ ] **Step 2: Rode e veja falhar**

```bash
"$PHP" -c "$INI" vendor/bin/phpunit --testsuite unit --filter PushSenderGuardians
```

Esperado: FAIL — `Call to undefined method ...PushSender::guardianSubscriptions()`.

- [ ] **Step 3: Implemente**

Em `includes/Notifications/WebPush/PushSender.php`:

Nos `use` do topo, acrescente:

```php
use GuardKids\Auth\GuardianAuth;
use GuardKids\Database\GuardianPushSubscriptionRepository;
```

Adicione a propriedade e o 4º parâmetro do construtor (nos existentes, mantendo o default-null do padrão da classe):

```php
    private readonly GuardianPushSubscriptionRepository $guardianSubs;

    public function __construct(
        ?PushSubscriptionRepository $subs = null,
        ?Vapid $vapid = null,
        ?Payload $payload = null,
        ?GuardianPushSubscriptionRepository $guardianSubs = null
    ) {
        $this->subs         = $subs ?? new PushSubscriptionRepository();
        $this->vapid        = $vapid ?? new Vapid();
        $this->payload      = $payload ?? new Payload();
        $this->guardianSubs = $guardianSubs ?? new GuardianPushSubscriptionRepository();
    }
```

Adicione os dois métodos novos depois de `sendToChild()` (sem tocar nele):

```php
    /**
     * Subscriptions de quem AINDA é guardião ativo.
     *
     * Público e separado do envio de propósito: é a única parte testável sem
     * passar por Payload::encrypt (que gera chave EC e estoura no openssl do
     * Windows). Efeito colateral desejado: guardião removido do time para de
     * receber no envio seguinte, sem limpar a tabela de subscriptions.
     *
     * @return array<int, array<string, mixed>>
     */
    public function guardianSubscriptions(): array
    {
        return array_values(array_filter(
            $this->guardianSubs->findAll(),
            static fn (array $s): bool => GuardianAuth::isActiveGuardian((int) ($s['wp_user_id'] ?? 0)),
        ));
    }

    public function sendToGuardians(string $title, string $body): void
    {
        $data = (string) wp_json_encode([
            'title' => $title,
            'body'  => $body,
            'url'   => '/painel-pais/',
        ]);

        foreach ($this->guardianSubscriptions() as $sub) {
            try {
                $this->sendOne((string) $sub['endpoint'], (string) $sub['p256dh'], (string) $sub['auth'], $data);
            } catch (\Throwable $e) {
                error_log('[GuardKids] push do guardião falhou: ' . $e->getMessage());
            }
        }
    }
```

- [ ] **Step 4: Rode e veja passar**

```bash
"$PHP" -c "$INI" vendor/bin/phpunit --testsuite unit --filter PushSenderGuardians
```

Esperado: `OK (3 tests, ...)`.

- [ ] **Step 5: Confirme que o push da criança não regrediu**

```bash
"$PHP" -c "$INI" vendor/bin/phpunit --testsuite unit --filter PushSenderTest
```

Esperado: **exatamente** 2 failures (`testSendPostsWithVapidAndAes128gcmHeaders`, `testGoneResponseRemovesSubscription`) — os mesmos de antes, pelo gotcha do EC. `testSuccessKeepsSubscription` passa. Se aparecer um terceiro vermelho, você mexeu no caminho da criança.

- [ ] **Step 6: Commit**

```bash
git add includes/Notifications/WebPush/PushSender.php tests/Unit/Notifications/WebPush/PushSenderGuardiansTest.php
git commit -m "feat(push): PushSender::sendToGuardians + filtro de guardiões ativos"
```

---

## Task 6: GuardianNotifier

**Files:**
- Create: `includes/Notifications/GuardianNotifier.php`
- Test: `tests/Unit/Notifications/GuardianNotifierTest.php`

**Contexto que você precisa:** funil único, espelhando o `Notifier` que serve a criança.

**Todos os repos deste projeto são `final`** — `ChildRepository`, `GuardianRepository`,
`GuardianPushDedupRepository`, todos. Você **não pode** estendê-los pra fakear; é fatal error.
O padrão estabelecido aqui é **stubar `$GLOBALS['wpdb']` e usar os repos reais** (foi o que o
`SettingsRepository` forçou nos testes de License).

`PushSender`, por outro lado, **não** é `final` — é o único que dá pra estender, e é
justamente o que precisamos pra gravar as chamadas sem tocar no crypto (`Vapid` e `Payload`
são `final`, então não haveria outro caminho).

- [ ] **Step 1: Escreva o teste que falha**

Crie `tests/Unit/Notifications/GuardianNotifierTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Notifications;

use GuardKids\Notifications\GuardianNotifier;
use GuardKids\Notifications\WebPush\PushSender;
use PHPUnit\Framework\TestCase;

final class GuardianNotifierTest extends TestCase
{
    /** @var array<int, array{title:string, body:string}> */
    private array $sent = [];
    private PushSender $sender;

    protected function setUp(): void
    {
        $this->sent = [];
        $test = $this;

        // Os repos são final: fakeia o wpdb e usa os repos de verdade.
        // O filho 3 é "Lucas"; o dedupe vive no array $dedupKeys.
        $GLOBALS['wpdb'] = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<string, bool> */
            public array $dedupKeys = [];

            public function __construct()
            {
            }

            public function prepare($query, ...$args)
            {
                $flat = $args[0] ?? null;
                if (is_array($flat)) {
                    $args = $flat;
                }
                return vsprintf(str_replace(['%d', '%s'], ['%d', "'%s'"], (string) $query), $args);
            }

            /** ChildRepository::findById */
            public function get_row($sql, $output = ARRAY_A, $y = 0)
            {
                if (preg_match('/guardkids_children WHERE id = (\d+)/', (string) $sql, $m) === 1) {
                    return (int) $m[1] === 3 ? ['id' => 3, 'name' => 'Lucas'] : null;
                }
                return null;
            }

            /** GuardianPushDedupRepository::findWhere(['dedup_key' => ...]) */
            public function get_results($sql, $output = ARRAY_A)
            {
                if (preg_match("/dedup_key = '([^']*)'/", (string) $sql, $m) === 1) {
                    return isset($this->dedupKeys[$m[1]]) ? [['id' => 1, 'dedup_key' => $m[1]]] : [];
                }
                return [];
            }

            public function insert($table, $data, $format = null)
            {
                if (isset($data['dedup_key'])) {
                    $this->dedupKeys[(string) $data['dedup_key']] = true;
                }
                $this->insert_id = count($this->dedupKeys);
                return 1;
            }
        };

        // PushSender NÃO é final: dá pra gravar as chamadas sem tocar no crypto.
        $this->sender = new class ($test) extends PushSender {
            public function __construct(private object $t)
            {
            }

            public function sendToGuardians(string $title, string $body): void
            {
                $this->t->record($title, $body);
            }
        };
    }

    public function record(string $title, string $body): void
    {
        $this->sent[] = ['title' => $title, 'body' => $body];
    }

    /** Repos reais (final) sobre o wpdb fakeado; só o sender é injetado. */
    private function notifier(): GuardianNotifier
    {
        return new GuardianNotifier(null, null, $this->sender);
    }

    public function testRequestCreatedSendsWithChildName(): void
    {
        $this->notifier()->notifyRequestCreated(
            ['id' => 42, 'child_id' => 3, 'description' => 'YouTube Kids'],
        );

        self::assertCount(1, $this->sent);
        self::assertSame('Lucas pediu acesso', $this->sent[0]['title']);
        self::assertSame('YouTube Kids', $this->sent[0]['body']);
    }

    public function testSameRequestTwiceSendsOnce(): void
    {
        $n = $this->notifier();
        $n->notifyRequestCreated(['id' => 42, 'child_id' => 3, 'description' => 'X']);
        $n->notifyRequestCreated(['id' => 42, 'child_id' => 3, 'description' => 'X']);

        self::assertCount(1, $this->sent, 'dedupe por evento: req:42 só anuncia uma vez');
    }

    public function testUnknownChildFallsBackToGenericName(): void
    {
        $this->notifier()->notifyRequestCreated(['id' => 1, 'child_id' => 99, 'description' => 'X']);

        self::assertSame('Seu filho pediu acesso', $this->sent[0]['title']);
    }

    public function testLimitReachedSends(): void
    {
        $this->notifier()->notifyLimitReached(3);

        self::assertCount(1, $this->sent);
        self::assertSame('Lucas esgotou o tempo de tela', $this->sent[0]['title']);
    }

    public function testLimitReachedTwiceSameDaySendsOnce(): void
    {
        $n = $this->notifier();
        $n->notifyLimitReached(3);
        $n->notifyLimitReached(3);

        self::assertCount(1, $this->sent, 'no máximo 1 por filho por dia');
    }

    public function testBlockedAttemptSendsPerDetail(): void
    {
        $n = $this->notifier();
        $n->notifyBlockedAttempt(3, 'bedtime');
        $n->notifyBlockedAttempt(3, 'weekday');
        $n->notifyBlockedAttempt(3, 'bedtime');

        self::assertCount(2, $this->sent, 'dedupe é por detail: bedtime e weekday são eventos distintos');
        self::assertStringContainsString('na hora de dormir', $this->sent[0]['title']);
        self::assertStringContainsString('em dia bloqueado', $this->sent[1]['title']);
    }

    public function testDevicePairedSends(): void
    {
        $this->notifier()->notifyDevicePaired(3);

        self::assertCount(1, $this->sent);
        self::assertSame('Novo dispositivo conectado', $this->sent[0]['title']);
        self::assertStringContainsString('Lucas', $this->sent[0]['body']);
    }

    public function testZeroChildIdIsIgnored(): void
    {
        $this->notifier()->notifyLimitReached(0);

        self::assertSame([], $this->sent);
    }
}
```

- [ ] **Step 2: Rode e veja falhar**

```bash
"$PHP" -c "$INI" vendor/bin/phpunit --testsuite unit --filter GuardianNotifier
```

Esperado: FAIL — classe `GuardianNotifier` não encontrada.

- [ ] **Step 3: Implemente**

Crie `includes/Notifications/GuardianNotifier.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Notifications;

use GuardKids\Database\ChildRepository;
use GuardKids\Database\GuardianPushDedupRepository;
use GuardKids\Notifications\WebPush\PushSender;

/**
 * Funil único das notificações do guardião. Espelha o Notifier (que serve a
 * criança), com duas diferenças deliberadas:
 *
 * - dedupa por EVENTO, não por destinatário — o evento aconteceu uma vez,
 *   anuncia-se uma vez pra todos os guardiões ativos;
 * - não persiste feed: o destino do push é /painel-pais, que já mostra o que
 *   há pra decidir.
 */
final class GuardianNotifier
{
    private readonly GuardianPushDedupRepository $dedup;
    private readonly ChildRepository $children;
    private readonly PushSender $pushSender;

    public function __construct(
        ?GuardianPushDedupRepository $dedup = null,
        ?ChildRepository $children = null,
        ?PushSender $pushSender = null
    ) {
        $this->dedup      = $dedup ?? new GuardianPushDedupRepository();
        $this->children   = $children ?? new ChildRepository();
        $this->pushSender = $pushSender ?? new PushSender();
    }

    private function emit(string $dedupKey, string $title, string $body): void
    {
        if ($this->dedup->createIfAbsent($dedupKey)) {
            $this->pushSender->sendToGuardians($title, $body);
        }
    }

    /** Notificação sem nome ainda é útil; push que explode por causa de cópia, não. */
    private function childName(int $childId): string
    {
        $row  = $this->children->findById($childId);
        $name = trim((string) ($row['name'] ?? ''));

        return $name !== '' ? $name : 'Seu filho';
    }

    /**
     * @param array<string, mixed> $request linha de wp_guardkids_requests
     */
    public function notifyRequestCreated(array $request): void
    {
        $childId = (int) ($request['child_id'] ?? 0);
        $id      = (int) ($request['id'] ?? 0);
        if ($childId === 0 || $id === 0) {
            return;
        }

        $label = trim((string) ($request['description'] ?? ''));

        $this->emit(
            'req:' . $id,
            $this->childName($childId) . ' pediu acesso',
            $label !== '' ? $label : 'Toque para decidir.',
        );
    }

    public function notifyLimitReached(int $childId): void
    {
        if ($childId === 0) {
            return;
        }

        $this->emit(
            'lim:' . $childId . ':' . gmdate('Y-m-d'),
            $this->childName($childId) . ' esgotou o tempo de tela',
            'O limite diário de hoje acabou.',
        );
    }

    public function notifyBlockedAttempt(int $childId, string $detail): void
    {
        if ($childId === 0) {
            return;
        }

        $when = ['bedtime' => 'na hora de dormir', 'weekday' => 'em dia bloqueado'][$detail] ?? 'fora do horário';

        $this->emit(
            'blk:' . $childId . ':' . $detail . ':' . gmdate('Y-m-d'),
            $this->childName($childId) . ' tentou acessar ' . $when,
            'O acesso foi bloqueado pelas regras.',
        );
    }

    public function notifyDevicePaired(int $childId): void
    {
        if ($childId === 0) {
            return;
        }

        $this->emit(
            'pair:' . $childId . ':' . gmdate('Y-m-d'),
            'Novo dispositivo conectado',
            $this->childName($childId) . ' conectou um aparelho novo.',
        );
    }
}
```

- [ ] **Step 4: Rode e veja passar**

```bash
"$PHP" -c "$INI" vendor/bin/phpunit --testsuite unit --filter GuardianNotifier
```

Esperado: `OK (9 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add includes/Notifications/GuardianNotifier.php tests/Unit/Notifications/GuardianNotifierTest.php
git commit -m "feat(push): GuardianNotifier — funil único dos 4 eventos"
```

---

## Task 7: GuardianPushController + rotas

**Files:**
- Create: `api/Controllers/GuardianPushController.php`
- Modify: `api/RestApi.php`
- Test: `tests/Unit/Api/GuardianPushControllerTest.php`

**Contexto que você precisa:** o `permission_callback` já existe — `RestApi::requireCollaboratorOrAbove()` cobre exatamente "qualquer guardião ativo, admin ou collaborator". Não crie helper novo: o collaborator também decide pedidos, então também precisa ser avisado.

As **chaves VAPID são as mesmas da criança** — `VapidKeys` vive em `wp_options` e não sabe de quem é o push. Nada a gerar.

**O truque que mantém o teste verde:** `VapidKeys::publicKey()` chama `ensure()`, que **retorna cedo se a option já existe** (`VapidKeys.php:31`). Semeando `$GLOBALS['gk_options']['guardkids_vapid_public']`, nenhuma chave EC é gerada e o teste passa local. (O `ChildSelfControllerTest::testPushKeyReturnsVapidPublic` não faz isso e por isso é vermelho na sua máquina.)

- [ ] **Step 1: Escreva o teste que falha**

Crie `tests/Unit/Api/GuardianPushControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\GuardianPushController;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

final class GuardianPushControllerTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        // Semeia a chave: ensure() retorna cedo e NÃO gera chave EC (que
        // estouraria no openssl do Windows).
        $GLOBALS['gk_options'] = ['guardkids_vapid_public' => 'FAKE_PUBLIC_KEY'];
        $GLOBALS['gk_current_user_id'] = 7;

        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];
            /** @var array<int, string> */
            public array $queries = [];

            public function __construct()
            {
            }

            public function prepare($query, ...$args)
            {
                $flat = $args[0] ?? null;
                if (is_array($flat)) {
                    $args = $flat;
                }
                return vsprintf(str_replace(['%d', '%s'], ['%d', "'%s'"], (string) $query), $args);
            }

            public function get_results($sql, $output = ARRAY_A)
            {
                if (preg_match("/endpoint = '([^']*)'/", (string) $sql, $m) === 1) {
                    return array_values(array_filter(
                        $this->rows,
                        static fn (array $r): bool => (string) $r['endpoint'] === $m[1],
                    ));
                }
                return array_values($this->rows);
            }

            public function insert($table, $data, $format = null)
            {
                $this->insert_id = count($this->rows) + 1;
                $this->rows[$this->insert_id] = array_merge(['id' => $this->insert_id], $data);
                return 1;
            }

            public function query($sql)
            {
                $this->queries[] = (string) $sql;
                if (preg_match("/DELETE.*endpoint = '([^']*)'/s", (string) $sql, $m) === 1) {
                    foreach ($this->rows as $id => $r) {
                        if ((string) $r['endpoint'] === $m[1]) {
                            unset($this->rows[$id]);
                        }
                    }
                }
                return 0;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testKeyReturnsSeededPublicKey(): void
    {
        $resp = (new GuardianPushController())->pushKey(new WP_REST_Request());

        self::assertSame(['publicKey' => 'FAKE_PUBLIC_KEY'], $resp->get_data());
    }

    public function testSubscribePersistsWithCurrentUserId(): void
    {
        $req = new WP_REST_Request();
        $req->set_json_params([
            'endpoint' => 'https://fcm.example/xyz',
            'keys'     => ['p256dh' => 'P', 'auth' => 'A'],
        ]);

        $resp = (new GuardianPushController())->pushSubscribe($req);

        self::assertSame(['ok' => true], $resp->get_data());
        self::assertCount(1, $this->wpdb->rows);
        self::assertSame(7, $this->wpdb->rows[1]['wp_user_id'], 'grava o guardião logado');
        self::assertSame('https://fcm.example/xyz', $this->wpdb->rows[1]['endpoint']);
    }

    public function testSubscribeRejectsMissingEndpoint(): void
    {
        $req = new WP_REST_Request();
        $req->set_json_params(['keys' => ['p256dh' => 'P', 'auth' => 'A']]);

        $resp = (new GuardianPushController())->pushSubscribe($req);

        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('invalid_subscription', $resp->get_error_code());
    }

    public function testUnsubscribeDeletesByEndpoint(): void
    {
        $this->wpdb->rows[1] = [
            'id' => 1, 'wp_user_id' => 7, 'endpoint' => 'https://fcm.example/xyz',
            'p256dh' => 'P', 'auth' => 'A', 'created_at' => '2026-01-01 00:00:00',
        ];
        $req = new WP_REST_Request();
        $req->set_json_params(['endpoint' => 'https://fcm.example/xyz']);

        $resp = (new GuardianPushController())->pushUnsubscribe($req);

        self::assertSame(['ok' => true], $resp->get_data());
        self::assertCount(0, $this->wpdb->rows);
    }
}
```

> Se `$GLOBALS['gk_current_user_id']` não for a chave que o stub de `get_current_user_id()` lê em `tests/bootstrap.php:459`, ajuste o teste pra usar a chave real — leia o stub antes de rodar.

- [ ] **Step 2: Rode e veja falhar**

```bash
"$PHP" -c "$INI" vendor/bin/phpunit --testsuite unit --filter GuardianPushController
```

Esperado: FAIL — classe não encontrada.

- [ ] **Step 3: Implemente o controller**

Crie `api/Controllers/GuardianPushController.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Database\GuardianPushSubscriptionRepository;
use GuardKids\Notifications\WebPush\VapidKeys;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Web Push do guardião: chave pública, subscribe e unsubscribe.
 *
 * Auth é nonce do WP + RestApi::requireCollaboratorOrAbove (no registro da
 * rota) — collaborator também decide pedidos, então também é avisado.
 *
 * As chaves VAPID são as MESMAS da criança: VapidKeys vive em wp_options e
 * não sabe de quem é o push.
 */
final class GuardianPushController
{
    private readonly GuardianPushSubscriptionRepository $subs;
    private readonly VapidKeys $vapidKeys;

    public function __construct(?GuardianPushSubscriptionRepository $subs = null)
    {
        $this->subs      = $subs ?? new GuardianPushSubscriptionRepository();
        $this->vapidKeys = new VapidKeys();
    }

    public function pushKey(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        return rest_ensure_response(['publicKey' => $this->vapidKeys->publicKey()]);
    }

    public function pushSubscribe(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $endpoint = (string) ($req->get_param('endpoint') ?? '');
        $keys     = $req->get_param('keys');
        $p256dh   = is_array($keys) ? (string) ($keys['p256dh'] ?? '') : '';
        $auth     = is_array($keys) ? (string) ($keys['auth'] ?? '') : '';

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            return new WP_Error('invalid_subscription', 'Subscription incompleta.', ['status' => 400]);
        }

        $this->subs->upsertByEndpoint(get_current_user_id(), $endpoint, $p256dh, $auth);

        return rest_ensure_response(['ok' => true]);
    }

    public function pushUnsubscribe(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $endpoint = (string) ($req->get_param('endpoint') ?? '');
        if ($endpoint === '') {
            return new WP_Error('invalid_subscription', 'Endpoint ausente.', ['status' => 400]);
        }

        $this->subs->deleteByEndpoint($endpoint);

        return rest_ensure_response(['ok' => true]);
    }
}
```

- [ ] **Step 4: Registre as rotas**

Em `api/RestApi.php`, junto do bloco que registra as rotas `/child/push/*` (por volta da linha 584), acrescente:

```php
        $guardianPush = new \GuardKids\Api\Controllers\GuardianPushController();

        register_rest_route(self::NAMESPACE, '/guardian/push/key', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$guardianPush, 'pushKey'],
            'permission_callback' => [self::class, 'requireCollaboratorOrAbove'],
        ]);

        register_rest_route(self::NAMESPACE, '/guardian/push/subscribe', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$guardianPush, 'pushSubscribe'],
            'permission_callback' => [self::class, 'requireCollaboratorOrAbove'],
        ]);

        register_rest_route(self::NAMESPACE, '/guardian/push/unsubscribe', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$guardianPush, 'pushUnsubscribe'],
            'permission_callback' => [self::class, 'requireCollaboratorOrAbove'],
        ]);
```

- [ ] **Step 5: Rode e veja passar**

```bash
"$PHP" -c "$INI" vendor/bin/phpunit --testsuite unit --filter GuardianPushController
```

Esperado: `OK (4 tests, ...)`.

- [ ] **Step 6: Rode o smoke de rotas**

```bash
"$PHP" -c "$INI" vendor/bin/phpunit --testsuite unit --filter Routes
```

Esperado: PASS. Existe um smoke que garante que todo handler público de controller tem rota REST registrada (commits `516684a`/`7e0d267`) — ele pega handler órfão.

- [ ] **Step 7: Commit**

```bash
git add api/Controllers/GuardianPushController.php api/RestApi.php tests/Unit/Api/GuardianPushControllerTest.php
git commit -m "feat(push): rotas /guardian/push/{key,subscribe,unsubscribe}"
```

---

## Task 8: Service worker + manifest + ParentApp

**Files:**
- Create: `public/app-parent/public/sw.js`
- Create: `public/app-parent/public/manifest.webmanifest`
- Modify: `includes/Ui/ParentApp.php`

**Contexto que você precisa — a decisão de scope, que é o que barateia esta fatia.** O `ChildApp` virou static server e emite `Service-Worker-Allowed: /painel-filho/` porque o PWA da criança faz **precache**, e precache exige que o SW *controle* as páginas. **Push não exige controle de página**: `pushManager.subscribe()` roda sobre qualquer registro, o evento `push` chega ao SW sem página aberta, e `notificationclick` abre janela sozinho.

Como o `ParentApp` já serve os assets via `plugins_url()`, o `sw.js` é registrado de lá com o scope natural dele (o diretório `dist/`), que **não** cobre `/painel-pais/` — e não precisa cobrir. Por isso: sem `vite-plugin-pwa`, sem Workbox, sem header de scope, sem static server.

Arquivos em `public/` do Vite são copiados verbatim pro `dist/` (não passam pelo bundler) — por isso `sw.js` é JS puro, não TS.

- [ ] **Step 1: Crie o service worker**

Crie `public/app-parent/public/sw.js`:

```js
/**
 * Service worker do painel dos pais — SÓ Web Push.
 *
 * Diferente do app-child, aqui não há precache/Workbox: o SW não precisa
 * controlar /painel-pais/ pra receber push. Ele é registrado a partir do
 * plugins_url e tem o scope do próprio diretório dist/.
 */
self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch {
    data = { body: event.data ? event.data.text() : '' };
  }
  event.waitUntil(
    self.registration.showNotification(data.title || 'GuardKids', {
      body: data.body || '',
      tag: data.tag,
      data: { url: data.url || '/painel-pais/' },
    }),
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || '/painel-pais/';
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
      for (const client of clients) {
        if (client.url.includes('/painel-pais') && 'focus' in client) {
          return client.focus();
        }
      }
      return self.clients.openWindow(url);
    }),
  );
});
```

- [ ] **Step 2: Crie o manifest**

Copie os ícones do app-child:

```bash
cp public/app-child/public/pwa-192x192.png public/app-parent/public/pwa-192x192.png
cp public/app-child/public/pwa-512x512.png public/app-parent/public/pwa-512x512.png
```

> Se esses arquivos não existirem nesses nomes, liste `public/app-child/public/` e use os ícones 192/512 que houver, ajustando o manifest abaixo.

Crie `public/app-parent/public/manifest.webmanifest`:

```json
{
  "name": "GuardKids — Painel dos Pais",
  "short_name": "GuardKids Pais",
  "start_url": "/painel-pais/",
  "scope": "/painel-pais/",
  "display": "standalone",
  "background_color": "#ffffff",
  "theme_color": "#1e3a8a",
  "lang": "pt-BR",
  "icons": [
    { "src": "pwa-192x192.png", "sizes": "192x192", "type": "image/png" },
    { "src": "pwa-512x512.png", "sizes": "512x512", "type": "image/png", "purpose": "any maskable" }
  ]
}
```

**Este manifest não transforma o painel num PWA offline** — não há precache nem estratégia de cache. Ele só declara identidade e torna instalável, que é o que o iOS exige pra entregar Web Push.

- [ ] **Step 3: Injete `swUrl` e linke o manifest no ParentApp**

Em `includes/Ui/ParentApp.php`, no `maybeServe()`:

Depois da linha do `<link>` dos Material Symbols (por volta da linha 110), acrescente:

```php
        echo '  <link rel="manifest" href="' . esc_url($distUrl . 'manifest.webmanifest') . '">' . "\n";
```

E troque o bloco do `window.guardkidsApi` (linha ~119) por:

```php
        echo '  <script>window.guardkidsApi = ' . wp_json_encode([
            'nonce'     => $nonce,
            'root'      => $root,
            'logoutUrl' => $logoutUrl,
            'swUrl'     => $distUrl . 'sw.js',
        ]) . ';</script>' . "\n";
```

- [ ] **Step 4: Build pra confirmar que os arquivos são copiados**

```bash
cd public/app-parent && pnpm build && ls dist/sw.js dist/manifest.webmanifest && cd ../..
```

Esperado: os dois arquivos existem em `dist/`.

- [ ] **Step 5: Commit**

```bash
git add public/app-parent/public/ includes/Ui/ParentApp.php
git commit -m "feat(push): service worker + manifest do painel dos pais"
```

---

## Task 9: lib/push.ts do app-parent

**Files:**
- Create: `public/app-parent/src/lib/push.ts`
- Test: `public/app-parent/src/lib/push.test.ts`

**Contexto que você precisa — NÃO copie o `push.ts` do app-child.** Ele usa `navigator.serviceWorker.ready` (linha 28), que resolve com o registro que **controla a página atual**. Como o SW do pai tem scope do `dist/` e não de `/painel-pais/`, esse `await` **nunca resolveria** — travaria pra sempre, em silêncio, sem erro. Use o registro devolvido pelo `register()` e espere a ativação à mão.

- [ ] **Step 1: Escreva o teste que falha**

Crie `public/app-parent/src/lib/push.test.ts`:

```ts
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';

const apiFetchMock = vi.hoisted(() => vi.fn());
vi.mock('../api/client', () => ({ apiFetch: apiFetchMock }));

import { isPushSupported, subscribe } from './push';

const VALID_KEY = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';

describe('isPushSupported', () => {
  afterEach(() => { vi.unstubAllGlobals(); });

  it('é falso quando o browser não tem PushManager', () => {
    vi.stubGlobal('navigator', {});
    expect(isPushSupported()).toBe(false);
  });
});

describe('subscribe', () => {
  beforeEach(() => {
    apiFetchMock.mockReset();
    vi.stubGlobal('window', {
      PushManager: class {},
      Notification: { permission: 'granted', requestPermission: vi.fn().mockResolvedValue('granted') },
      guardkidsApi: { swUrl: 'https://site.test/plugins/dist/sw.js' },
    });
    vi.stubGlobal('Notification', {
      permission: 'granted',
      requestPermission: vi.fn().mockResolvedValue('granted'),
    });
  });

  afterEach(() => { vi.unstubAllGlobals(); });

  it('registra o SW pela swUrl e NÃO usa serviceWorker.ready', async () => {
    const register = vi.fn().mockResolvedValue({
      active: {},
      pushManager: {
        subscribe: vi.fn().mockResolvedValue({
          toJSON: () => ({ endpoint: 'https://fcm/x', keys: { p256dh: 'P', auth: 'A' } }),
        }),
      },
    });
    // `ready` é uma promise que NUNCA resolve: se o código a usar, o teste
    // estoura por timeout — que é exatamente o bug que queremos impedir.
    vi.stubGlobal('navigator', { serviceWorker: { register, ready: new Promise(() => {}) } });
    apiFetchMock.mockResolvedValueOnce({ publicKey: VALID_KEY }).mockResolvedValueOnce({ ok: true });

    await subscribe();

    expect(register).toHaveBeenCalledWith('https://site.test/plugins/dist/sw.js');
    expect(apiFetchMock).toHaveBeenCalledWith('/guardian/push/key');
    expect(apiFetchMock.mock.calls[1][0]).toBe('/guardian/push/subscribe');
    expect(JSON.parse(apiFetchMock.mock.calls[1][1].body)).toEqual({
      endpoint: 'https://fcm/x',
      keys: { p256dh: 'P', auth: 'A' },
    });
  });

  it('propaga erro quando a permissão é negada', async () => {
    vi.stubGlobal('Notification', {
      permission: 'default',
      requestPermission: vi.fn().mockResolvedValue('denied'),
    });
    vi.stubGlobal('navigator', {
      serviceWorker: { register: vi.fn(), ready: new Promise(() => {}) },
    });
    apiFetchMock.mockResolvedValueOnce({ publicKey: VALID_KEY });

    await expect(subscribe()).rejects.toThrow(/permiss/i);
  });
});
```

- [ ] **Step 2: Rode e veja falhar**

```bash
cd public/app-parent && pnpm test -- push.test && cd ../..
```

Esperado: FAIL — `Failed to resolve import "./push"`.

- [ ] **Step 3: Implemente**

Crie `public/app-parent/src/lib/push.ts`:

```ts
import { apiFetch } from '../api/client';

declare global {
  interface Window {
    guardkidsApi?: { nonce: string; root: string; logoutUrl: string; swUrl?: string };
  }
}

export function isPushSupported(): boolean {
  return (
    typeof navigator !== 'undefined' &&
    'serviceWorker' in navigator &&
    typeof window !== 'undefined' &&
    'PushManager' in window &&
    typeof Notification !== 'undefined'
  );
}

export function getPermission(): NotificationPermission {
  return typeof Notification !== 'undefined' ? Notification.permission : 'denied';
}

function urlBase64ToUint8Array(base64: string): Uint8Array {
  const padding = '='.repeat((4 - (base64.length % 4)) % 4);
  const b64 = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = atob(b64);
  const out = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
  return out;
}

/**
 * Registra o SW e espera ele ativar.
 *
 * NÃO usa `navigator.serviceWorker.ready`: ela resolve com o registro que
 * CONTROLA a página, e o nosso tem scope do dist/ (não de /painel-pais/).
 * Usar `ready` aqui travaria pra sempre, em silêncio.
 */
async function registerSw(): Promise<ServiceWorkerRegistration> {
  const swUrl = window.guardkidsApi?.swUrl;
  if (!swUrl) throw new Error('Service worker não configurado.');

  const reg = await navigator.serviceWorker.register(swUrl);
  if (reg.active) return reg;

  const worker = reg.installing ?? reg.waiting;
  if (worker) {
    await new Promise<void>((resolve) => {
      worker.addEventListener('statechange', () => {
        if (worker.state === 'activated') resolve();
      });
    });
  }
  return reg;
}

export async function subscribe(): Promise<void> {
  const { publicKey } = await apiFetch<{ publicKey: string }>('/guardian/push/key');

  if (Notification.permission !== 'granted') {
    const result = await Notification.requestPermission();
    if (result !== 'granted') {
      throw new Error('Permissão de notificação negada no navegador.');
    }
  }

  const registration = await registerSw();
  const sub = await registration.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(publicKey) as BufferSource,
  });

  const json = sub.toJSON() as { endpoint?: string; keys?: { p256dh?: string; auth?: string } };
  await apiFetch('/guardian/push/subscribe', {
    method: 'POST',
    body: JSON.stringify({
      endpoint: json.endpoint,
      keys: { p256dh: json.keys?.p256dh, auth: json.keys?.auth },
    }),
  });
}

export async function unsubscribe(): Promise<void> {
  const reg = await navigator.serviceWorker.getRegistration(window.guardkidsApi?.swUrl);
  const sub = await reg?.pushManager.getSubscription();
  if (!sub) return;

  const endpoint = sub.endpoint;
  await sub.unsubscribe();
  await apiFetch('/guardian/push/unsubscribe', {
    method: 'POST',
    body: JSON.stringify({ endpoint }),
  });
}
```

- [ ] **Step 4: Rode e veja passar**

```bash
cd public/app-parent && pnpm test -- push.test && cd ../..
```

Esperado: 3 testes passando.

- [ ] **Step 5: Commit**

```bash
git add public/app-parent/src/lib/push.ts public/app-parent/src/lib/push.test.ts
git commit -m "feat(push): lib/push.ts do app-parent (sem serviceWorker.ready)"
```

---

## Task 10: Destravar o toggle no Settings

**Files:**
- Modify: `public/app-parent/src/pages/Settings.tsx`
- Test: `public/app-parent/src/pages/Settings.test.tsx`

**Contexto que você precisa:** o toggle `notifications.push` **já existe** em `Settings.tsx:113` com a prop `locked` (linha 119). Remova o `locked` e ligue no subscribe/unsubscribe.

**Erro é obrigatório aqui, não opcional.** A auditoria deste app achou 83 `useMutation` contra 19 `onError` — falha silenciosa é um padrão sistêmico e não vamos repeti-lo. Nas três situações (permissão negada, browser sem suporte, falha de rede) o toggle **volta pro estado desligado** em vez de mentir que está ligado. Use o `<MutationError error={...} />` que a página já usa (linha ~154); não invente um terceiro padrão.

- [ ] **Step 1: Leia o componente antes de mexer**

```bash
sed -n '100,160p' public/app-parent/src/pages/Settings.tsx
sed -n '470,500p' public/app-parent/src/pages/Settings.tsx
```

Entenda como `SettingToggleRow` recebe `get`/`set`/`locked` e como `MutationError` é renderizado. O toggle grava em `wp_guardkids_settings` via a mutation da página — o push é um **efeito colateral** disso, não um substituto.

- [ ] **Step 2: Escreva o teste que falha**

Em `public/app-parent/src/pages/Settings.test.tsx`, acrescente (mocando `../lib/push` no topo, junto dos mocks existentes):

```ts
const pushMock = vi.hoisted(() => ({
  isPushSupported: vi.fn(() => true),
  subscribe: vi.fn().mockResolvedValue(undefined),
  unsubscribe: vi.fn().mockResolvedValue(undefined),
  getPermission: vi.fn(() => 'granted' as NotificationPermission),
}));
vi.mock('../lib/push', () => pushMock);
```

E os casos:

```ts
it('o toggle de push não está mais bloqueado', async () => {
  renderSettings();
  const toggle = await screen.findByRole('switch', { name: /Notificações push/i });
  expect(toggle).not.toBeDisabled();
});

it('assina o push ao ligar o toggle', async () => {
  renderSettings();
  const toggle = await screen.findByRole('switch', { name: /Notificações push/i });
  await userEvent.click(toggle);
  await waitFor(() => expect(pushMock.subscribe).toHaveBeenCalled());
});

it('mostra erro e mantém desligado quando a permissão é negada', async () => {
  pushMock.subscribe.mockRejectedValueOnce(new Error('Permissão de notificação negada no navegador.'));
  renderSettings();
  const toggle = await screen.findByRole('switch', { name: /Notificações push/i });
  await userEvent.click(toggle);

  expect(await screen.findByRole('alert')).toHaveTextContent(/permiss/i);
  expect(toggle).toHaveAttribute('aria-checked', 'false');
});
```

> `renderSettings` é o helper que o arquivo já usa — reaproveite, não crie outro. Se o nome acessível do switch não bater, leia como `SettingToggleRow` monta o label e ajuste o seletor. Asserções em switch usam `aria-checked` neste codebase.

- [ ] **Step 3: Rode e veja falhar**

```bash
cd public/app-parent && pnpm test -- Settings && cd ../..
```

Esperado: FAIL — o toggle está `disabled` por causa do `locked`.

- [ ] **Step 4: Implemente**

Em `Settings.tsx`, importe no topo:

```ts
import { isPushSupported, subscribe as pushSubscribe, unsubscribe as pushUnsubscribe } from '../lib/push';
```

Depois do `const set = ...` (linha 95), acrescente o handler dedicado:

```tsx
  const [pushError, setPushError] = useState<Error | null>(null);
  const pushSupported = isPushSupported();

  // O push é efeito colateral do toggle, não substituto: só persiste o setting
  // se a assinatura no browser der certo. Falha => volta pro desligado com o
  // motivo na tela (nunca mente que está ligado).
  const setPush = async (key: string, value: boolean) => {
    setPushError(null);
    try {
      if (value) {
        await pushSubscribe();
      } else {
        await pushUnsubscribe();
      }
    } catch (err) {
      setPushError(err instanceof Error ? err : new Error('Não foi possível ativar as notificações.'));
      return;
    }
    set(key, value);
  };
```

Troque o `SettingToggleRow` de `notifications.push` (linhas 112-122) inteiro por:

```tsx
        <SettingToggleRow
          settingsKey="notifications.push"
          title="Notificações push"
          description={
            pushSupported
              ? 'Recebe alertas no celular sobre pedidos e bloqueios.'
              : 'Este navegador não suporta notificações push.'
          }
          fallback={false}
          loading={settingsQuery.isLoading}
          saving={mutation.isPending}
          locked={!pushSupported}
          get={get}
          set={setPush}
        />
        {pushError ? (
          <p role="alert" className="rounded-lg bg-error/10 p-2 text-label-sm text-error">
            {pushError.message}
          </p>
        ) : null}
```

Três detalhes que importam:

- **`fallback` vai de `true` pra `false`.** Enquanto o toggle era `locked`, "ligado por
  padrão" era cosmético e inofensivo. Agora que é funcional, um default `true` mostraria
  ligado sem existir subscription nenhuma — mentira na cara do usuário.
- **`locked={!pushSupported}`** mantém o toggle desabilitado em browser sem suporte, com a
  descrição explicando. Melhor que deixar clicar e falhar.
- **Não uso `<MutationError />` aqui** porque ela prefixa "Falha ao salvar:", que estaria
  errado pra "permissão negada" — não é falha de save. Mesmas classes visuais, cópia certa.
  Isso não é um padrão novo: é o mesmo `<p role="alert">` com o mesmo estilo.

`set` é tipado como `(key, value) => void` e `setPush` devolve `Promise<void>` — TypeScript
aceita (retorno `void` absorve qualquer retorno), não precisa mudar a assinatura do
`SettingToggleRow`.

- [ ] **Step 5: Rode e veja passar**

```bash
cd public/app-parent && pnpm test -- Settings && cd ../..
```

Esperado: PASS, incluindo os testes que já existiam no arquivo.

- [ ] **Step 6: Commit**

```bash
git add public/app-parent/src/pages/Settings.tsx public/app-parent/src/pages/Settings.test.tsx
git commit -m "feat(push): destrava o toggle de notificações push com erro visível"
```

---

## Task 11: Os gatilhos

**Files:**
- Modify: `api/Controllers/ChildSelfController.php` (`requestsCreate` ~264, `eventsCreate` ~296)
- Modify: `api/Controllers/ChildController.php` (`pair`)
- Test: `tests/Unit/Api/ChildSelfControllerTest.php`, `tests/Unit/Api/ChildControllerTest.php`

**Contexto que você precisa:** os gatilhos entram **por último de propósito** — até agora nada disparava, então o canal inteiro pôde ser construído e testado sem risco de spammar ninguém.

**A falha nunca pode derrubar o gatilho.** Se o push explodir, a criança ainda tem que conseguir criar o pedido. O `PushSender` já engole as falhas dele, mas envolva a chamada do notifier em try/catch mesmo assim — o `GuardianNotifier` toca o banco (dedupe), e banco também falha.

Note que `requestsCreate` **não chama notifier nenhum hoje** — é a lacuna que motivou a fatia inteira.

- [ ] **Step 1: Escreva os testes que falham**

Em `tests/Unit/Api/ChildSelfControllerTest.php`, acrescente um caso que prova que criar pedido notifica o guardião, e outro que prova que falha de notificação não derruba o 201. Siga o padrão de stub de `$GLOBALS['wpdb']` que o arquivo já usa; injete um `GuardianNotifier` fake se o controller aceitar injeção, ou verifique a linha do dedupe no wpdb fake.

```php
public function testRequestCreateNotifiesGuardians(): void
{
    // ... monta o wpdb fake como os outros testes do arquivo ...
    $req = new WP_REST_Request();
    $req->set_json_params(['description' => 'YouTube Kids']);

    $resp = $this->controller()->requestsCreate($req);

    self::assertSame(201, $resp->get_status());
    // O dedupe grava a linha só quando o notifier roda:
    self::assertNotEmpty(array_filter(
        $this->wpdb->rows,
        static fn (array $r): bool => str_starts_with((string) ($r['dedup_key'] ?? ''), 'req:'),
    ));
}
```

- [ ] **Step 2: Rode e veja falhar**

```bash
"$PHP" -c "$INI" vendor/bin/phpunit --testsuite unit --filter ChildSelfControllerTest
```

Esperado: FAIL no caso novo (nenhuma linha de dedupe) — os demais do arquivo continuam como estavam.

- [ ] **Step 3: Ligue o gatilho do pedido**

Em `ChildSelfController.php`, adicione ao topo:

```php
use GuardKids\Notifications\GuardianNotifier;
```

à propriedade e ao construtor (junto do `$this->notifier`):

```php
    private readonly GuardianNotifier $guardianNotifier;
    // no construtor:
    $this->guardianNotifier = new GuardianNotifier();
```

e em `requestsCreate`, logo depois de a linha do pedido ser criada e antes do return, com o id em mãos:

```php
        try {
            $this->guardianNotifier->notifyRequestCreated([
                'id'          => $id,
                'child_id'    => $childId,
                'description' => $description,
            ]);
        } catch (\Throwable $e) {
            // Push nunca derruba o gatilho: a criança tem que conseguir pedir.
            error_log('[GuardKids] notificar guardião falhou: ' . $e->getMessage());
        }
```

> Use os nomes de variáveis que já existem no método (`$id`, `$childId`, `$description` podem se chamar outra coisa — leia o método antes).

- [ ] **Step 4: Ligue o gatilho do bloqueio**

Em `eventsCreate`, no ramo que persiste `type === 'schedule_block'`, depois de gravar o evento:

```php
        if ($type === 'schedule_block') {
            try {
                if ($detail === 'limit') {
                    $this->guardianNotifier->notifyLimitReached($childId);
                } else {
                    $this->guardianNotifier->notifyBlockedAttempt($childId, $detail);
                }
            } catch (\Throwable $e) {
                error_log('[GuardKids] notificar guardião falhou: ' . $e->getMessage());
            }
        }
```

- [ ] **Step 5: Ligue o gatilho do pareamento**

Em `ChildController::pair` (o método que emite o token de dispositivo), depois de o token ser emitido:

```php
        try {
            (new GuardianNotifier())->notifyDevicePaired($childId);
        } catch (\Throwable $e) {
            error_log('[GuardKids] notificar guardião falhou: ' . $e->getMessage());
        }
```

com o `use GuardKids\Notifications\GuardianNotifier;` no topo.

- [ ] **Step 6: Rode a suíte inteira**

```bash
"$PHP" -c "$INI" vendor/bin/phpunit --testsuite unit
```

Esperado: `Errors: 6, Failures: 2` — os 8 pré-existentes do gotcha do EC, **nada além**. Se subir, um gatilho quebrou um teste existente.

- [ ] **Step 7: Commit**

```bash
git add api/Controllers/ChildSelfController.php api/Controllers/ChildController.php tests/Unit/Api/
git commit -m "feat(push): liga os gatilhos — pedido, bloqueio, limite e pareamento"
```

---

## Task 12: GC do dedupe + release 1.36.0

**Files:**
- Modify: `includes/Maintenance/Purger.php`
- Modify: `guardkids.php` (header `Version`)

**Contexto que você precisa:** `guardian_push_dedup` cresce ~1 linha por evento por dia e ninguém lê linha velha. O `Purger` já roda diário via `guardkids_daily_purge` (`Plugin.php:27`) e já expurga notificações/locations > 30d — acrescente o dedupe no mesmo padrão.

- [ ] **Step 1: Leia o Purger e siga o padrão**

```bash
sed -n '1,80p' includes/Maintenance/Purger.php
```

- [ ] **Step 2: Escreva o teste que falha**

Em `tests/Unit/Maintenance/PurgerTest.php` (ou o nome que o arquivo tiver), acrescente um caso que prova que o purge emite `DELETE` na tabela `guardian_push_dedup` com corte de 30 dias, no mesmo estilo dos casos já existentes.

- [ ] **Step 3: Rode e veja falhar**

```bash
"$PHP" -c "$INI" vendor/bin/phpunit --testsuite unit --filter Purger
```

- [ ] **Step 4: Implemente e veja passar**

Acrescente o expurgo de `guardian_push_dedup` com `created_at < (agora - 30d)`, espelhando o purge que já existe.

```bash
"$PHP" -c "$INI" vendor/bin/phpunit --testsuite unit --filter Purger
```

Esperado: PASS.

- [ ] **Step 5: Bumpe a versão**

Em `guardkids.php`, no header do plugin:

```php
 * Version:           1.36.0
```

(A `GUARDKIDS_DB_VERSION` já foi pra 24 na Task 1 — confira que está lá.)

- [ ] **Step 6: Gate de qualidade completo**

```bash
"$PHP" -c "$INI" vendor/bin/phpunit --testsuite unit
cd public/app-parent && pnpm test && pnpm build && cd ../..
cd public/app-child && pnpm test && cd ../..
```

Esperado: PHP com **exatamente** `Errors: 6, Failures: 2`; vitest do app-parent 100% verde (os seus testes novos inclusos); app-child intocado e verde; build limpo.

- [ ] **Step 7: Commit**

```bash
git add includes/Maintenance/Purger.php guardkids.php tests/
git commit -m "chore(release): v1.36.0 — Web Push do guardião"
```

---

## Smoke real — o critério de sucesso de verdade

**Suíte verde não fecha esta fatia.** O critério é: **um pedido criado no app-filho faz o celular do guardião apitar**.

O site LocalWP precisa estar **Started** (na última sessão nada respondia na porta 80 — foi o que bloqueou o smoke anterior).

- [ ] **1.** Suba o LocalWP e confirme: `curl -s -o /dev/null -w "%{http_code}" http://guardkids-wp.local/` → `200`
- [ ] **2.** Rode as migrações: abra `http://guardkids-wp.local/wp-admin` e confirme que `guardkids_db_version` foi pra `24`
- [ ] **3.** Abra `http://guardkids-wp.local/painel-pais` → Configurações → ligue **Notificações push** → aceite a permissão do browser
- [ ] **4.** Confirme a linha: `SELECT * FROM wp_guardkids_guardian_push_subscriptions` tem 1 linha com o seu `wp_user_id`
- [ ] **5.** Abra `http://guardkids-wp.local/painel-filho` num browser/aba separada, pareie um filho, e **crie um pedido**
- [ ] **6.** **A notificação aparece** no desktop com "«Nome» pediu acesso"
- [ ] **7.** Clicar na notificação abre/foca `/painel-pais`
- [ ] **8.** Crie um segundo pedido → chega outra notificação (chave `req:{id}` diferente)
- [ ] **9.** Force o mesmo bloqueio duas vezes no mesmo dia → **chega só uma** (prova o dedupe em runtime, que é o que o teste unitário só simula)

**Atenção ao browser limpo:** extensões suas já fingiram bug neste projeto antes (os 4 erros de Console do Adobe Acrobat/Grabbit na investigação do delete). Se algo estranho aparecer, reproduza numa janela anônima sem extensões antes de caçar o bug no código.

---

## Depois do smoke

- Tag + GitHub release `v1.36.0`, zip via `python tools/build-release-zip.py`
- Deploy em prod (Hostinger): as tabelas são novas e `IF NOT EXISTS`, nenhum dado existente é tocado, o caminho da criança não muda — **deploy idempotente, rollback é desativar o plugin**
- Smoke em prod: ligar o toggle em `https://guardiaokids.site/painel-pais` e confirmar a subscription
- Atualizar a memória: `project_guardkids_wp.md` (v1.36.0/DB v24) e o índice `MEMORY.md`
