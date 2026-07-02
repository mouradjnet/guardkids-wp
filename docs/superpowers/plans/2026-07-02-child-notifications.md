# Notificações do app-filho (fase 1) — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fundação in-app de notificações no app-filho: backend real (tabela + repositório + serviço Notifier), endpoints, geração por 4 gatilhos, página Alertas viva e badge de não-lidas.

**Architecture:** Tabela `wp_guardkids_notifications` (migração 014) + `NotificationRepository` + serviço `Notifier` (funil único de criação, com dedup idempotente). Notificações nascem em pontos de evento (RequestController, SiteController, ChildSelfController). O app-child lê via `/child/notifications` e o badge sai do `unreadNotifications` já embutido no `/child/me`.

**Tech Stack:** PHP 8.2 / WordPress (`$wpdb`), PHPUnit 9.6 (stubs, sem Docker); React 19 + TypeScript + TanStack Query 5 + Vitest 2 (app-child).

**Spec:** `docs/superpowers/specs/2026-07-02-child-notifications-design.md`

**Ambiente:** já na branch `feat/child-notifications`. Comando PHPUnit (Windows/LocalWP):
```bash
PHP="/c/Users/mysho/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe"
EXT="$("$PHP" -r 'echo dirname(PHP_BINARY);')/ext"
"$PHP" -d extension_dir="$EXT" -d extension=openssl -d extension=mbstring -d extension=sodium \
  vendor/bin/phpunit -c phpunit.xml.dist --testsuite unit --filter <Classe>
```
App-child: `cd public/app-child && pnpm test <arquivo>` / `pnpm exec tsc -b`.

---

## File Structure

**Backend (novo):**
- `database/migrations/014_notifications.php` — cria a tabela.
- `database/NotificationRepository.php` — CRUD + dedup + contagem.
- `includes/Notifications/Notifier.php` — funil de criação por gatilho + lógica pura de avisos.

**Backend (modificado):**
- `guardkids.php` — `GUARDKIDS_DB_VERSION` 13 → 14.
- `uninstall.php` — dropar a tabela nova.
- `api/RestApi.php` — 2 rotas novas em `registerChildSelfRoutes`.
- `api/Controllers/ChildSelfController.php` — `notificationsIndex`, `notificationsRead`, `unreadNotifications` no `me`, avisos no `me`, blocked no `eventsCreate`.
- `api/Controllers/RequestController.php` — notificar ao decidir.
- `api/Controllers/SiteController.php` — notificar ao liberar site.

**Frontend app-child (modificado):**
- `src/api/types.ts` — tipo `Notification`, `unreadNotifications` no `Child`.
- `src/api/child.ts` — `listNotifications`, `markNotificationsRead`.
- `src/pages/Alerts.tsx` — real (query + mark-read).
- `src/components/BottomNav.tsx` — badge condicional por `alertsUnread`.
- `src/App.tsx` — passa `alertsUnread` ao BottomNav.

**Testes (novos/modificados):** `tests/Unit/Database/NotificationRepositoryTest.php`, `tests/Unit/Notifications/NotifierTest.php`, `tests/Unit/Api/ChildSelfControllerTest.php` (estende fake wpdb), `tests/Unit/Api/RequestControllerTest.php`, `tests/Unit/Api/SiteControllerTest.php`, `public/app-child/src/api/child.test.ts` (ou novo), `Alerts.test.tsx`, `BottomNav.test.tsx`.

---

## Task 1: Migração 014 + tabela + bump DB version + uninstall

**Files:**
- Create: `database/migrations/014_notifications.php`
- Modify: `guardkids.php` (linha `define('GUARDKIDS_DB_VERSION', 13);`)
- Modify: `uninstall.php` (array `$tables`)

- [ ] **Step 1: Criar a migração**

`database/migrations/014_notifications.php`:
```php
<?php

declare(strict_types=1);

/**
 * Migration 014 — tabela de notificações in-app do app-filho.
 *
 * Fundação do sistema de notificações (fase 1 de push). Append-mostly:
 * só read_at muda depois da criação. dedup_key dá idempotência por janela/evento.
 *
 * CREATE TABLE via $wpdb->query com IF NOT EXISTS (idempotente).
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $table = $wpdb->prefix . 'guardkids_notifications';

    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS {$table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            child_id    BIGINT UNSIGNED NOT NULL,
            type        VARCHAR(32)     NOT NULL,
            title       VARCHAR(160)    NOT NULL,
            body        VARCHAR(255)    NULL,
            dedup_key   VARCHAR(191)    NULL,
            read_at     DATETIME        NULL,
            created_at  DATETIME        NOT NULL,
            PRIMARY KEY  (id),
            KEY child_created (child_id, created_at),
            UNIQUE KEY child_dedup (child_id, dedup_key)
        ) {$charsetCollate};"
    );
};
```

- [ ] **Step 2: Bump da versão do banco**

Em `guardkids.php`, trocar:
```php
define('GUARDKIDS_DB_VERSION', 13);
```
por:
```php
define('GUARDKIDS_DB_VERSION', 14);
```

- [ ] **Step 3: uninstall dropa a tabela**

Em `uninstall.php`, adicionar ao array `$tables` (após `companion_devices`):
```php
    $wpdb->prefix . 'guardkids_notifications',
```

- [ ] **Step 4: Verificar que a suíte unit segue verde (migração é auto-descoberta)**

Run:
```bash
"$PHP" -d extension_dir="$EXT" -d extension=openssl -d extension=mbstring -d extension=sodium \
  vendor/bin/phpunit -c phpunit.xml.dist --testsuite unit --filter MigrationRunnerTest
```
Expected: PASS (no Windows local o `MigrationRunnerTest` pode falso-falhar por glob em `C:\Windows\TEMP` — é artefato de ambiente; a CI Linux valida).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/014_notifications.php guardkids.php uninstall.php
git commit -m "feat(db): migração 014 — tabela de notificações + bump DB v14"
```

---

## Task 2: NotificationRepository

**Files:**
- Create: `database/NotificationRepository.php`
- Test: `tests/Unit/Database/NotificationRepositoryTest.php`

- [ ] **Step 1: Escrever o teste que falha**

`tests/Unit/Database/NotificationRepositoryTest.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\NotificationRepository;
use PHPUnit\Framework\TestCase;

final class NotificationRepositoryTest extends TestCase
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
                $this->insert_id = count($this->rows) + 1;
                $this->rows[$this->insert_id] = array_merge(['id' => $this->insert_id], $data);
                return 1;
            }

            public function get_results($sql, $output = OBJECT)
            {
                // findByChild: filtra por child_id extraído do SQL
                if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    $out = array_values(array_filter(
                        $this->rows,
                        static fn (array $r): bool => (int) $r['child_id'] === (int) $m[1],
                    ));
                    // dedup lookup traz WHERE dedup_key = '...'
                    if (preg_match("/dedup_key = '([^']*)'/", (string) $sql, $d) === 1) {
                        $out = array_values(array_filter(
                            $out,
                            static fn (array $r): bool => (string) ($r['dedup_key'] ?? '') === $d[1],
                        ));
                    }
                    return $out;
                }
                return [];
            }

            public function get_var($sql, $x = 0, $y = 0)
            {
                if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    return (string) count(array_filter(
                        $this->rows,
                        static fn (array $r): bool => (int) $r['child_id'] === (int) $m[1]
                            && ($r['read_at'] ?? null) === null,
                    ));
                }
                return null;
            }

            public function query($sql)
            {
                // markAllRead
                if (preg_match('/UPDATE.*child_id = (\d+)/s', (string) $sql, $m) === 1) {
                    $n = 0;
                    foreach ($this->rows as &$r) {
                        if ((int) $r['child_id'] === (int) $m[1] && ($r['read_at'] ?? null) === null) {
                            $r['read_at'] = '2026-07-02 00:00:00';
                            $n++;
                        }
                    }
                    return $n;
                }
                return 0;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testCreateInsertsRowAndReturnsId(): void
    {
        $id = (new NotificationRepository())->create([
            'child_id' => 1,
            'type'     => 'request_approved',
            'title'    => 'Aprovado',
            'body'     => 'canva.com',
        ]);
        self::assertSame(1, $id);
        self::assertSame('request_approved', $this->wpdb->rows[1]['type']);
        self::assertNull($this->wpdb->rows[1]['read_at']);
    }

    public function testCreateIfAbsentSkipsDuplicateDedupKey(): void
    {
        $repo = new NotificationRepository();
        self::assertTrue($repo->createIfAbsent(1, 'req:9', ['type' => 'x', 'title' => 't']));
        self::assertFalse($repo->createIfAbsent(1, 'req:9', ['type' => 'x', 'title' => 't']));
        self::assertCount(1, $this->wpdb->rows);
    }

    public function testUnreadCountAndMarkAllRead(): void
    {
        $repo = new NotificationRepository();
        $repo->create(['child_id' => 1, 'type' => 'x', 'title' => 'a']);
        $repo->create(['child_id' => 1, 'type' => 'x', 'title' => 'b']);
        $repo->create(['child_id' => 2, 'type' => 'x', 'title' => 'c']);
        self::assertSame(2, $repo->unreadCount(1));
        self::assertSame(2, $repo->markAllRead(1));
        self::assertSame(0, $repo->unreadCount(1));
    }

    public function testFindByChildFiltersById(): void
    {
        $repo = new NotificationRepository();
        $repo->create(['child_id' => 1, 'type' => 'x', 'title' => 'a']);
        $repo->create(['child_id' => 2, 'type' => 'x', 'title' => 'b']);
        self::assertCount(1, $repo->findByChild(1));
    }
}
```

- [ ] **Step 2: Rodar o teste e ver falhar**

Run: `"$PHP" ... vendor/bin/phpunit -c phpunit.xml.dist --testsuite unit --filter NotificationRepositoryTest`
Expected: FAIL — `Class "GuardKids\Database\NotificationRepository" not found`.

- [ ] **Step 3: Implementar o repositório**

`database/NotificationRepository.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class NotificationRepository extends Repository
{
    private const MAX_LIMIT = 50;

    protected function tableSuffix(): string
    {
        return 'notifications';
    }

    /**
     * Insere direto (a tabela não tem updated_at; created_at em UTC).
     *
     * @param array{child_id:int,type:string,title:string,body?:?string,dedup_key?:?string} $data
     */
    public function create(array $data): int
    {
        $ok = $this->db->insert($this->table(), [
            'child_id'   => (int) $data['child_id'],
            'type'       => (string) $data['type'],
            'title'      => (string) $data['title'],
            'body'       => $data['body'] ?? null,
            'dedup_key'  => $data['dedup_key'] ?? null,
            'read_at'    => null,
            'created_at' => current_time('mysql', true),
        ]);
        return $ok === false ? 0 : (int) $this->db->insert_id;
    }

    /**
     * Cria só se não existir linha com (child_id, dedup_key). Idempotente.
     *
     * @param array{type:string,title:string,body?:?string} $data
     */
    public function createIfAbsent(int $childId, string $dedupKey, array $data): bool
    {
        if ($this->findWhere(['child_id' => $childId, 'dedup_key' => $dedupKey]) !== []) {
            return false;
        }
        return $this->create($data + ['child_id' => $childId, 'dedup_key' => $dedupKey]) > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByChild(int $childId, int $limit = self::MAX_LIMIT): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $sql = $this->db->prepare(
            'SELECT * FROM ' . $this->table()
            . ' WHERE child_id = %d ORDER BY created_at DESC, id DESC LIMIT %d',
            $childId,
            $limit,
        );
        $rows = $this->db->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public function unreadCount(int $childId): int
    {
        $sql = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE child_id = %d AND read_at IS NULL',
            $childId,
        );
        return (int) $this->db->get_var($sql);
    }

    public function markAllRead(int $childId): int
    {
        $sql = $this->db->prepare(
            'UPDATE ' . $this->table() . ' SET read_at = %s WHERE child_id = %d AND read_at IS NULL',
            current_time('mysql', true),
            $childId,
        );
        $affected = $this->db->query($sql);
        return is_numeric($affected) ? (int) $affected : 0;
    }
}
```

- [ ] **Step 4: Rodar o teste e ver passar**

Run: `"$PHP" ... --filter NotificationRepositoryTest`
Expected: PASS (4 testes).

- [ ] **Step 5: Commit**

```bash
git add database/NotificationRepository.php tests/Unit/Database/NotificationRepositoryTest.php
git commit -m "feat(db): NotificationRepository (create/dedup/unread/markRead)"
```

---

## Task 3: Serviço Notifier

**Files:**
- Create: `includes/Notifications/Notifier.php`
- Test: `tests/Unit/Notifications/NotifierTest.php`

Notas de dependência: `Notifier` recebe `?NotificationRepository` e `?ChildRepository` (padrão dos controllers). `notifySiteAllowed` reusa `SiteRepository::normalizeDomain`. `approachingWarnings` é `public static` (função pura, testável sem DB).

- [ ] **Step 1: Escrever o teste que falha**

`tests/Unit/Notifications/NotifierTest.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Notifications;

use GuardKids\Database\NotificationRepository;
use GuardKids\Notifications\Notifier;
use PHPUnit\Framework\TestCase;

final class NotifierTest extends TestCase
{
    /** Repo fake em memória, sem $wpdb. */
    private function fakeRepo(): NotificationRepository
    {
        return new class () extends NotificationRepository {
            /** @var array<int, array<string, mixed>> */
            public array $created = [];

            public function __construct()
            {
            }

            public function create(array $data): int
            {
                $this->created[] = $data;
                return count($this->created);
            }

            public function createIfAbsent(int $childId, string $dedupKey, array $data): bool
            {
                foreach ($this->created as $c) {
                    if (($c['child_id'] ?? null) === $childId && ($c['dedup_key'] ?? null) === $dedupKey) {
                        return false;
                    }
                }
                $this->create($data + ['child_id' => $childId, 'dedup_key' => $dedupKey]);
                return true;
            }
        };
    }

    public function testNotifyRequestDecidedApproved(): void
    {
        $repo = $this->fakeRepo();
        (new Notifier($repo))->notifyRequestDecided(
            ['id' => 9, 'child_id' => 1, 'description' => 'Liberar site', 'highlight' => 'canva.com'],
            'approved',
        );
        self::assertCount(1, $repo->created);
        self::assertSame('request_approved', $repo->created[0]['type']);
        self::assertSame('Seu pedido foi aprovado! 🎉', $repo->created[0]['title']);
        self::assertSame('Liberar site canva.com', $repo->created[0]['body']);
        self::assertSame('req:9', $repo->created[0]['dedup_key']);
    }

    public function testNotifyRequestDecidedDenied(): void
    {
        $repo = $this->fakeRepo();
        (new Notifier($repo))->notifyRequestDecided(['id' => 3, 'child_id' => 1], 'denied');
        self::assertSame('request_denied', $repo->created[0]['type']);
        self::assertSame('Seu pedido não foi aprovado', $repo->created[0]['title']);
    }

    public function testNotifyBlockedUsesDetailTitle(): void
    {
        $repo = $this->fakeRepo();
        (new Notifier($repo))->notifyBlocked(1, 'bedtime');
        self::assertSame('blocked', $repo->created[0]['type']);
        self::assertSame('Hora de dormir', $repo->created[0]['title']);
    }

    public function testApproachingWarningsLimit(): void
    {
        $now = new \DateTimeImmutable('2026-07-02 15:00:00');
        $warnings = Notifier::approachingWarnings(
            ['daily_limit_enabled' => 1, 'limit_minutes' => 60],
            52, // faltam 8
            $now,
        );
        self::assertCount(1, $warnings);
        self::assertSame('time_warning', $warnings[0]['type']);
        self::assertSame('Faltam 8 min de tela hoje.', $warnings[0]['body']);
        self::assertSame('limit:2026-07-02', $warnings[0]['dedup_key']);
    }

    public function testApproachingWarningsNoLimitWhenFarFromCap(): void
    {
        $now = new \DateTimeImmutable('2026-07-02 15:00:00');
        $warnings = Notifier::approachingWarnings(
            ['daily_limit_enabled' => 1, 'limit_minutes' => 60],
            30, // faltam 30 (> 10)
            $now,
        );
        self::assertSame([], $warnings);
    }

    public function testApproachingWarningsBedtime(): void
    {
        $now = new \DateTimeImmutable('2026-07-02 20:53:00');
        $warnings = Notifier::approachingWarnings(
            ['bedtime_enabled' => 1, 'bedtime_start' => '21:00:00'],
            0,
            $now,
        );
        self::assertCount(1, $warnings);
        self::assertSame('bedtime_warning', $warnings[0]['type']);
        self::assertSame('A hora de dormir começa em 7 min.', $warnings[0]['body']);
        self::assertSame('bedtime:2026-07-02', $warnings[0]['dedup_key']);
    }
}
```

- [ ] **Step 2: Rodar o teste e ver falhar**

Run: `"$PHP" ... --filter NotifierTest`
Expected: FAIL — `Class "GuardKids\Notifications\Notifier" not found`.

- [ ] **Step 3: Implementar o serviço**

`includes/Notifications/Notifier.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Notifications;

use DateTimeImmutable;
use GuardKids\Database\ChildRepository;
use GuardKids\Database\NotificationRepository;
use GuardKids\Database\SiteRepository;

/**
 * Funil único de criação de notificações do app-filho. Cada gatilho vira uma
 * linha (idempotente via dedup_key). É o ponto onde o Web Push (fase 2) vai
 * plugar: depois de criar a linha, um PushSender futuro entrega.
 */
final class Notifier
{
    private const WARNING_MINUTES = 10;

    private readonly NotificationRepository $repo;
    private readonly ChildRepository $children;

    public function __construct(?NotificationRepository $repo = null, ?ChildRepository $children = null)
    {
        $this->repo     = $repo ?? new NotificationRepository();
        $this->children = $children ?? new ChildRepository();
    }

    /**
     * @param array<string, mixed> $request linha de wp_guardkids_requests
     */
    public function notifyRequestDecided(array $request, string $decision): void
    {
        $childId = (int) ($request['child_id'] ?? 0);
        if ($childId === 0) {
            return;
        }
        $label = trim(((string) ($request['description'] ?? '')) . ' ' . ((string) ($request['highlight'] ?? '')));
        $approved = $decision === 'approved';
        $this->repo->createIfAbsent($childId, 'req:' . (int) ($request['id'] ?? 0), [
            'type'  => $approved ? 'request_approved' : 'request_denied',
            'title' => $approved ? 'Seu pedido foi aprovado! 🎉' : 'Seu pedido não foi aprovado',
            'body'  => $label !== '' ? $label : null,
        ]);
    }

    /** A whitelist é da família → 1 notificação por filho. */
    public function notifySiteAllowed(string $domain): void
    {
        $domain = SiteRepository::normalizeDomain($domain);
        if ($domain === '') {
            return;
        }
        foreach ($this->children->findAll() as $child) {
            $this->repo->createIfAbsent((int) ($child['id'] ?? 0), 'site:' . $domain, [
                'type'  => 'site_allowed',
                'title' => 'Novo site liberado',
                'body'  => 'Agora você pode acessar ' . $domain,
            ]);
        }
    }

    public function notifyBlocked(int $childId, string $detail): void
    {
        $titles = ['bedtime' => 'Hora de dormir', 'weekday' => 'Dia bloqueado', 'limit' => 'Tempo esgotado'];
        $this->repo->createIfAbsent($childId, 'blocked:' . $detail . ':' . gmdate('Y-m-d'), [
            'type'  => 'blocked',
            'title' => $titles[$detail] ?? 'Acesso pausado',
            'body'  => 'O acesso está pausado agora.',
        ]);
    }

    /** Persiste os avisos de aproximação (chamado pelo /child/me quando não bloqueado). */
    public function persistWarnings(int $childId, DateTimeImmutable $now, array $child, int $usedMinutes): void
    {
        foreach (self::approachingWarnings($child, $usedMinutes, $now) as $w) {
            $this->repo->createIfAbsent($childId, (string) $w['dedup_key'], [
                'type'  => (string) $w['type'],
                'title' => (string) $w['title'],
                'body'  => (string) $w['body'],
            ]);
        }
    }

    /**
     * Lógica pura dos avisos de tempo/bedtime (limiar de 10 min). Assume que o
     * filho NÃO está bloqueado agora (o caller checa schedule.isBlocked antes).
     *
     * @param array<string, mixed> $child linha de wp_guardkids_children
     * @return array<int, array{type:string,title:string,body:string,dedup_key:string}>
     */
    public static function approachingWarnings(array $child, int $usedMinutes, DateTimeImmutable $now): array
    {
        $warnings = [];
        $today = $now->format('Y-m-d');

        $limitEnabled = (int) ($child['daily_limit_enabled'] ?? 0) === 1;
        $limit        = (int) ($child['limit_minutes'] ?? 0);
        if ($limitEnabled && $limit > 0) {
            $remaining = $limit - $usedMinutes;
            if ($remaining > 0 && $remaining <= self::WARNING_MINUTES) {
                $warnings[] = [
                    'type'      => 'time_warning',
                    'title'     => 'Tempo acabando',
                    'body'      => "Faltam {$remaining} min de tela hoje.",
                    'dedup_key' => 'limit:' . $today,
                ];
            }
        }

        $bedtimeEnabled = (int) ($child['bedtime_enabled'] ?? 0) === 1;
        $start = $child['bedtime_start'] ?? null;
        if ($bedtimeEnabled && is_string($start) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $start) === 1) {
            $startDt = $now->setTime((int) substr($start, 0, 2), (int) substr($start, 3, 2), (int) substr($start, 6, 2));
            if ($now < $startDt) {
                $mins = (int) floor(($startDt->getTimestamp() - $now->getTimestamp()) / 60);
                if ($mins <= self::WARNING_MINUTES) {
                    $n = max(1, $mins);
                    $warnings[] = [
                        'type'      => 'bedtime_warning',
                        'title'     => 'Hora de dormir chegando',
                        'body'      => "A hora de dormir começa em {$n} min.",
                        'dedup_key' => 'bedtime:' . $today,
                    ];
                }
            }
        }

        return $warnings;
    }
}
```

- [ ] **Step 4: Rodar o teste e ver passar**

Run: `"$PHP" ... --filter NotifierTest`
Expected: PASS (6 testes).

- [ ] **Step 5: Commit**

```bash
git add includes/Notifications/Notifier.php tests/Unit/Notifications/NotifierTest.php
git commit -m "feat(notifications): serviço Notifier (gatilhos + avisos puros)"
```

---

## Task 4: ChildSelfController — endpoints, unread no /me, avisos e blocked

**Files:**
- Modify: `api/Controllers/ChildSelfController.php`
- Modify: `api/RestApi.php` (`registerChildSelfRoutes`)
- Test: `tests/Unit/Api/ChildSelfControllerTest.php` (estender o fake wpdb)

- [ ] **Step 1: Escrever os testes que falham**

Adicionar em `tests/Unit/Api/ChildSelfControllerTest.php`. Primeiro, estender o fake wpdb do `setUp` para conhecer a tabela de notificações — adicionar a propriedade e os branches:

Na classe anônima do `setUp`, adicionar a propriedade:
```php
            /** @var array<int, array<string, mixed>> */
            public array $notifications = [];
```
No método `get_results`, adicionar antes do `return []`:
```php
                if (str_contains((string) $sql, 'guardkids_notifications')) {
                    $out = $this->notifications;
                    if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                        $out = array_values(array_filter(
                            $out,
                            static fn (array $r): bool => (int) $r['child_id'] === (int) $m[1],
                        ));
                    }
                    if (preg_match("/dedup_key = '([^']*)'/", (string) $sql, $d) === 1) {
                        $out = array_values(array_filter(
                            $out,
                            static fn (array $r): bool => (string) ($r['dedup_key'] ?? '') === $d[1],
                        ));
                    }
                    return $out;
                }
```
No método `get_var`, adicionar antes do `return null`:
```php
                if (str_contains((string) $sql, 'guardkids_notifications')
                    && preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    return (string) count(array_filter(
                        $this->notifications,
                        static fn (array $r): bool => (int) $r['child_id'] === (int) $m[1]
                            && ($r['read_at'] ?? null) === null,
                    ));
                }
```
No método `insert`, adicionar antes do `return 0`:
```php
                if (str_contains((string) $table, 'guardkids_notifications')) {
                    $id = count($this->notifications) + 1;
                    $this->notifications[$id] = array_merge(['id' => $id], $data);
                    return 1;
                }
```
Adicionar o método `query` na classe anônima (se ainda não existir):
```php
            public function query($sql)
            {
                if (str_contains((string) $sql, 'guardkids_notifications')
                    && preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    $n = 0;
                    foreach ($this->notifications as &$r) {
                        if ((int) $r['child_id'] === (int) $m[1] && ($r['read_at'] ?? null) === null) {
                            $r['read_at'] = '2026-07-02 00:00:00';
                            $n++;
                        }
                    }
                    return $n;
                }
                return 0;
            }
```

Depois, adicionar os casos de teste:
```php
    public function testNotificationsIndexFiltersByChildId(): void
    {
        $this->wpdb->notifications = [
            1 => ['id' => 1, 'child_id' => 1, 'type' => 'blocked', 'title' => 'x', 'body' => null, 'read_at' => null, 'created_at' => '2026-07-02 10:00:00'],
            2 => ['id' => 2, 'child_id' => 2, 'type' => 'blocked', 'title' => 'y', 'body' => null, 'read_at' => null, 'created_at' => '2026-07-02 10:00:00'],
        ];
        $res = (new ChildSelfController())->notificationsIndex($this->authedRequest('GET', '/child/notifications'));
        self::assertInstanceOf(WP_REST_Response::class, $res);
        $data = $res->get_data();
        self::assertCount(1, $data);
        self::assertSame('blocked', $data[0]['type']);
        self::assertFalse($data[0]['read']);
    }

    public function testNotificationsIndex401WithoutToken(): void
    {
        $res = (new ChildSelfController())->notificationsIndex(new WP_REST_Request('GET', '/child/notifications'));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(401, $res->get_error_data()['status']);
    }

    public function testNotificationsReadMarksAll(): void
    {
        $this->wpdb->notifications = [
            1 => ['id' => 1, 'child_id' => 1, 'read_at' => null],
            2 => ['id' => 2, 'child_id' => 1, 'read_at' => null],
        ];
        $res = (new ChildSelfController())->notificationsRead($this->authedRequest('POST', '/child/notifications/read'));
        self::assertSame(2, $res->get_data()['updated']);
    }

    public function testMeIncludesUnreadNotifications(): void
    {
        $this->wpdb->notifications = [
            1 => ['id' => 1, 'child_id' => 1, 'read_at' => null],
        ];
        $res = (new ChildSelfController())->me($this->authedRequest('GET', '/child/me'));
        self::assertSame(1, $res->get_data()['unreadNotifications']);
    }
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `"$PHP" ... --filter ChildSelfControllerTest`
Expected: FAIL — `Call to undefined method ...::notificationsIndex()`.

- [ ] **Step 3: Implementar no ChildSelfController**

Adicionar os imports no topo:
```php
use GuardKids\Database\NotificationRepository;
use GuardKids\Notifications\Notifier;
```
Adicionar propriedades e init no construtor (seguindo o padrão existente):
```php
    private readonly NotificationRepository $notifications;
    private readonly Notifier $notifier;
```
No `__construct`, após `$this->sites = new SiteRepository();`:
```php
        $this->notifications = new NotificationRepository();
        $this->notifier      = new Notifier();
```

No método `me`, antes do `return rest_ensure_response(...)`, inserir a geração de avisos (só quando não bloqueado) e incluir o unread no payload. Trocar o bloco final do `me`:
```php
        $schedule = $this->evaluator->evaluate($row, $now, $usedMin);

        return rest_ensure_response(
            $this->childToJson($row) + [
                'schedule'         => $schedule,
                'pinUnlockEnabled' => $this->pinUnlockEnabled(),
            ]
        );
```
por:
```php
        $schedule = $this->evaluator->evaluate($row, $now, $usedMin);

        if ($schedule['isBlocked'] === false) {
            $this->notifier->persistWarnings($childId, $now, $row, $usedMin);
        }

        return rest_ensure_response(
            $this->childToJson($row) + [
                'schedule'            => $schedule,
                'pinUnlockEnabled'    => $this->pinUnlockEnabled(),
                'unreadNotifications' => $this->notifications->unreadCount($childId),
            ]
        );
```

No método `eventsCreate`, logo após inserir o evento com sucesso (antes do `return new WP_REST_Response([...], 201);`), adicionar o gatilho de blocked:
```php
        if ($type === 'schedule_block' && $detail !== null) {
            $this->notifier->notifyBlocked($childId, $detail);
        }
```

Adicionar os dois métodos novos (após `requestsIndex` ou `sitesIndex`):
```php
    public function notificationsIndex(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        $rows = $this->notifications->findByChild($childId);
        return rest_ensure_response(array_map([$this, 'notificationToJson'], $rows));
    }

    public function notificationsRead(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        return rest_ensure_response(['updated' => $this->notifications->markAllRead($childId)]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function notificationToJson(array $row): array
    {
        return [
            'id'        => (int) ($row['id'] ?? 0),
            'type'      => (string) ($row['type'] ?? ''),
            'title'     => (string) ($row['title'] ?? ''),
            'body'      => $row['body'] ?? null,
            'read'      => ($row['read_at'] ?? null) !== null,
            'createdAt' => $row['created_at'] ?? null,
        ];
    }
```

- [ ] **Step 4: Registrar as rotas**

Em `api/RestApi.php`, dentro de `registerChildSelfRoutes`, após o bloco de `/child/sites`:
```php
        register_rest_route(self::NAMESPACE, '/child/notifications', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'notificationsIndex'],
            'permission_callback' => $requireToken,
        ]);

        register_rest_route(self::NAMESPACE, '/child/notifications/read', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'notificationsRead'],
            'permission_callback' => $requireToken,
        ]);
```

- [ ] **Step 5: Rodar e ver passar**

Run: `"$PHP" ... --filter ChildSelfControllerTest`
Expected: PASS (todos, incl. os 4 novos).

- [ ] **Step 6: Commit**

```bash
git add api/Controllers/ChildSelfController.php api/RestApi.php tests/Unit/Api/ChildSelfControllerTest.php
git commit -m "feat(api): /child/notifications + unread no /me + avisos e blocked"
```

---

## Task 5: Gatilho no RequestController (pedido decidido)

**Files:**
- Modify: `api/Controllers/RequestController.php`
- Test: `tests/Unit/Api/RequestControllerTest.php`

- [ ] **Step 1: Escrever o teste que falha**

Ler `tests/Unit/Api/RequestControllerTest.php` para o padrão do fake wpdb. Adicionar um teste que, ao aprovar, cria uma notificação. Se o fake wpdb do arquivo não capturar inserts em `guardkids_notifications`, adicionar `public array $notifications = []` e um branch no `insert` (igual ao da Task 4). Depois:
```php
    public function testApproveCreatesNotificationForChild(): void
    {
        // Arrange: um request pending do child 1 (seguir o helper de seed do arquivo).
        // ... cria o request via o mesmo padrão dos outros testes deste arquivo ...

        // Act: aprovar
        // $resp = (new RequestController())->approve($this->requestWithId($id));

        // Assert: nasceu 1 notificação request_approved pro child 1
        self::assertNotEmpty($this->wpdb->notifications);
        $last = end($this->wpdb->notifications);
        self::assertSame('request_approved', $last['type']);
        self::assertSame(1, (int) $last['child_id']);
    }
```
> Observação p/ o executor: adaptar o arranjo ao helper de seed já existente em `RequestControllerTest.php` (não reinventar o fake). O ponto do teste é: após `approve`, existe 1 linha em `notifications` com `type=request_approved`.

- [ ] **Step 2: Rodar e ver falhar**

Run: `"$PHP" ... --filter RequestControllerTest`
Expected: FAIL — nenhuma notificação criada.

- [ ] **Step 3: Implementar o gatilho**

Em `api/Controllers/RequestController.php`, adicionar o import:
```php
use GuardKids\Notifications\Notifier;
```
Adicionar propriedade + init:
```php
    private readonly Notifier $notifier;
```
No `__construct`, após `$this->sites = new SiteRepository();`:
```php
        $this->notifier = new Notifier();
```
No método `decide`, após o bloco `if ($decision === 'approved' && ... allowDomain(...))`, adicionar (antes do `return`):
```php
        $this->notifier->notifyRequestDecided($row, $decision);
```
(`$row` é a linha pré-decisão, com `child_id`, `id`, `description`, `highlight` — suficiente.)

- [ ] **Step 4: Rodar e ver passar**

Run: `"$PHP" ... --filter RequestControllerTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add api/Controllers/RequestController.php tests/Unit/Api/RequestControllerTest.php
git commit -m "feat(api): notifica o filho ao aprovar/negar pedido"
```

---

## Task 6: Gatilho no SiteController (site liberado)

**Files:**
- Modify: `api/Controllers/SiteController.php`
- Test: `tests/Unit/Api/SiteControllerTest.php`

- [ ] **Step 1: Escrever o teste que falha**

Em `tests/Unit/Api/SiteControllerTest.php`, garantir que o fake wpdb tenha `children` (≥1 filho) e capture inserts em `guardkids_notifications` (adicionar `public array $notifications = []` + branch no `insert`, e um `get_results` que devolva os children em `findAll`). Teste:
```php
    public function testCreateWhitelistNotifiesChildren(): void
    {
        // 1 filho no fake
        $this->wpdb->children = [1 => ['id' => 1, 'name' => 'Lucas']];

        $req = $this->makeRequest('POST', '/sites', [
            'domain'    => 'https://www.canva.com/design',
            'list_type' => 'whitelist',
        ]);
        (new SiteController($this->allowGate()))->create($req);

        self::assertNotEmpty($this->wpdb->notifications);
        $last = end($this->wpdb->notifications);
        self::assertSame('site_allowed', $last['type']);
        self::assertSame('Agora você pode acessar canva.com', $last['body']); // normalizado
    }

    public function testCreateBlacklistDoesNotNotify(): void
    {
        $this->wpdb->children = [1 => ['id' => 1, 'name' => 'Lucas']];
        $req = $this->makeRequest('POST', '/sites', ['domain' => 'x.com', 'list_type' => 'blacklist']);
        (new SiteController())->create($req);
        self::assertEmpty($this->wpdb->notifications);
    }
```
> Observação p/ o executor: reusar o helper de Gate/`makeRequest` do arquivo. `allowGate()` = um Gate que retorna `can('browser')=true` (a whitelist é premium — ver o padrão de gating já testado no arquivo). Se o arquivo já injeta um Gate liberado, usar o mesmo.

- [ ] **Step 2: Rodar e ver falhar**

Run: `"$PHP" ... --filter SiteControllerTest`
Expected: FAIL — nenhuma notificação.

- [ ] **Step 3: Implementar o gatilho**

Em `api/Controllers/SiteController.php`, adicionar import:
```php
use GuardKids\Notifications\Notifier;
```
Propriedade + init:
```php
    private readonly Notifier $notifier;
```
No `__construct` (aceita `?Gate` hoje; manter e adicionar), após `$this->gate = $gate ?? new Gate();`:
```php
        $this->notifier = new Notifier();
```
No método `create`, após o `if ($id === 0) { return ... }` (insert bem-sucedido), e **só** para whitelist:
```php
        if (((string) ($req->get_param('list_type') ?? 'whitelist')) === 'whitelist') {
            $this->notifier->notifySiteAllowed($domain);
        }
```
(`$domain` já é a string do parâmetro; `notifySiteAllowed` normaliza internamente.)

- [ ] **Step 4: Rodar e ver passar**

Run: `"$PHP" ... --filter SiteControllerTest`
Expected: PASS.

- [ ] **Step 5: Rodar a suíte PHP unit inteira**

Run: `"$PHP" ... vendor/bin/phpunit -c phpunit.xml.dist --testsuite unit`
Expected: PASS (tudo verde).

- [ ] **Step 6: Commit**

```bash
git add api/Controllers/SiteController.php tests/Unit/Api/SiteControllerTest.php
git commit -m "feat(api): notifica os filhos ao liberar site (whitelist)"
```

---

## Task 7: Frontend — camada de API (tipos + funções)

**Files:**
- Modify: `public/app-child/src/api/types.ts`
- Modify: `public/app-child/src/api/child.ts`
- Test: `public/app-child/src/api/child.test.ts` (criar se não existir; senão adicionar)

- [ ] **Step 1: Adicionar o tipo e o campo**

Em `src/api/types.ts`, adicionar:
```ts
export type Notification = {
  id: number;
  type: string;
  title: string;
  body: string | null;
  read: boolean;
  createdAt: string | null;
};
```
E no tipo `Child`, adicionar o campo opcional:
```ts
  unreadNotifications?: number;
```

- [ ] **Step 2: Escrever o teste que falha**

`public/app-child/src/api/child.test.ts` (adicionar; se o arquivo não existir, criar com este conteúdo):
```ts
import { afterEach, describe, expect, it, vi } from 'vitest';
import { listNotifications, markNotificationsRead } from './child';

const apiFetch = vi.fn();
vi.mock('./client', () => ({
  apiFetch: (path: string, init?: RequestInit) => apiFetch(path, init),
}));

describe('notifications api', () => {
  afterEach(() => apiFetch.mockReset());

  it('listNotifications faz GET /child/notifications', async () => {
    apiFetch.mockResolvedValueOnce([]);
    await listNotifications();
    expect(apiFetch).toHaveBeenCalledWith('/child/notifications');
  });

  it('markNotificationsRead faz POST /child/notifications/read', async () => {
    apiFetch.mockResolvedValueOnce({ updated: 2 });
    await markNotificationsRead();
    expect(apiFetch).toHaveBeenCalledWith('/child/notifications/read', { method: 'POST' });
  });
});
```

- [ ] **Step 3: Rodar e ver falhar**

Run: `cd public/app-child && pnpm test child.test`
Expected: FAIL — `listNotifications is not exported`.

- [ ] **Step 4: Implementar**

Em `src/api/child.ts`, adicionar o import do tipo e as funções:
```ts
import type { AllowedSite, Child, CreateRequestInput, MyRequest, Notification } from './types';
```
(atualizar a linha de import de tipos existente para incluir `Notification`.)
```ts
/** Notificações in-app do filho (mais recentes primeiro). */
export function listNotifications(): Promise<Notification[]> {
  return apiFetch<Notification[]>('/child/notifications');
}

/** Marca todas as notificações como lidas. */
export function markNotificationsRead(): Promise<{ updated: number }> {
  return apiFetch<{ updated: number }>('/child/notifications/read', { method: 'POST' });
}
```

- [ ] **Step 5: Rodar e ver passar**

Run: `pnpm test child.test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add public/app-child/src/api/types.ts public/app-child/src/api/child.ts public/app-child/src/api/child.test.ts
git commit -m "feat(child-app): api de notificações (list + markRead) e tipo"
```

---

## Task 8: Frontend — Alerts.tsx real

**Files:**
- Modify: `public/app-child/src/pages/Alerts.tsx`
- Test: `public/app-child/src/pages/Alerts.test.tsx`

- [ ] **Step 1: Escrever o teste que falha**

`public/app-child/src/pages/Alerts.test.tsx` (substituir o conteúdo, se existir):
```tsx
import { screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { Notification } from '../api/types';
import { renderWithClient } from '../test/queryClient';
import { Alerts } from './Alerts';

const listNotifications = vi.fn();
const markNotificationsRead = vi.fn();
vi.mock('../api/child', () => ({
  listNotifications: () => listNotifications(),
  markNotificationsRead: () => markNotificationsRead(),
}));

const sample: Notification[] = [
  { id: 1, type: 'request_approved', title: 'Seu pedido foi aprovado! 🎉', body: 'canva.com', read: false, createdAt: new Date().toISOString() },
];

describe('Alerts', () => {
  afterEach(() => {
    listNotifications.mockReset();
    markNotificationsRead.mockReset();
  });

  it('lista as notificações reais da API', async () => {
    listNotifications.mockResolvedValueOnce(sample);
    markNotificationsRead.mockResolvedValueOnce({ updated: 1 });
    renderWithClient(<Alerts />);
    expect(await screen.findByText('Seu pedido foi aprovado! 🎉')).toBeInTheDocument();
    expect(screen.getByText('canva.com')).toBeInTheDocument();
  });

  it('mostra empty state quando não há notificações', async () => {
    listNotifications.mockResolvedValueOnce([]);
    markNotificationsRead.mockResolvedValueOnce({ updated: 0 });
    renderWithClient(<Alerts />);
    expect(await screen.findByText(/nenhum aviso/i)).toBeInTheDocument();
  });

  it('marca como lidas ao abrir', async () => {
    listNotifications.mockResolvedValueOnce(sample);
    markNotificationsRead.mockResolvedValueOnce({ updated: 1 });
    renderWithClient(<Alerts />);
    await waitFor(() => expect(markNotificationsRead).toHaveBeenCalledTimes(1));
  });
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `pnpm test Alerts`
Expected: FAIL — Alerts ainda usa mock.

- [ ] **Step 3: Implementar**

Substituir `public/app-child/src/pages/Alerts.tsx`:
```tsx
import { useEffect } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { listNotifications, markNotificationsRead } from '../api/child';
import type { Notification } from '../api/types';
import { Icon } from '../components/Icon';

function relative(iso: string | null): string {
  if (!iso) return '';
  const diffMin = Math.floor((Date.now() - new Date(iso).getTime()) / 60_000);
  if (Number.isNaN(diffMin)) return '';
  if (diffMin < 1) return 'agora';
  if (diffMin < 60) return `há ${diffMin} min`;
  const h = Math.floor(diffMin / 60);
  if (h < 24) return `há ${h}h`;
  return `há ${Math.floor(h / 24)}d`;
}

const styleFor: Record<string, { icon: string; bg: string; text: string }> = {
  request_approved: { icon: 'check_circle', bg: 'bg-secondary-container/40', text: 'text-secondary' },
  site_allowed:     { icon: 'public',       bg: 'bg-primary/10',            text: 'text-primary' },
  time_warning:     { icon: 'schedule',     bg: 'bg-orange-warm/15',        text: 'text-orange-warm' },
  bedtime_warning:  { icon: 'bedtime',      bg: 'bg-orange-warm/15',        text: 'text-orange-warm' },
  request_denied:   { icon: 'cancel',       bg: 'bg-error-container/60',    text: 'text-error' },
  blocked:          { icon: 'block',        bg: 'bg-error-container/60',    text: 'text-error' },
};

export function Alerts() {
  const queryClient = useQueryClient();
  const query = useQuery({ queryKey: ['child', 'notifications'], queryFn: listNotifications });
  const markRead = useMutation({
    mutationFn: markNotificationsRead,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['child', 'me'] }),
  });

  useEffect(() => {
    markRead.mutate();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <main className="flex flex-1 flex-col gap-stack-md px-container-padding-mobile py-stack-md">
      <p className="px-1 text-label-md text-on-surface-variant">
        Avisos novinhos pra você.
      </p>

      {query.isLoading && (
        <div className="glass-panel h-24 animate-pulse rounded-2xl bg-surface-container-low" />
      )}

      {query.error && (
        <div className="glass-panel flex flex-col items-center gap-2 rounded-2xl bg-error/5 p-4 text-error">
          <Icon name="error" className="text-2xl" />
          <p className="text-label-sm">Não deu pra carregar seus avisos agora.</p>
        </div>
      )}

      {query.data && query.data.length === 0 && (
        <div className="glass-panel flex flex-col items-center justify-center gap-2 rounded-2xl p-6 text-center text-on-surface-variant">
          <Icon name="notifications_off" className="text-3xl text-primary" filled />
          <p className="text-label-md font-semibold">Nenhum aviso por aqui</p>
          <p className="text-label-sm">Quando algo acontecer, aparece aqui.</p>
        </div>
      )}

      {query.data && query.data.length > 0 && (
        <div className="glass-panel rounded-2xl shadow-ambient">
          <ul className="divide-y divide-outline-variant/50">
            {query.data.map((n: Notification) => {
              const s = styleFor[n.type] ?? styleFor.blocked;
              return (
                <li key={n.id} className="flex items-start gap-3 p-4">
                  <div className={`flex h-10 w-10 items-center justify-center rounded-xl ${s.bg}`}>
                    <Icon name={s.icon} className={s.text} filled />
                  </div>
                  <div className="flex-1">
                    <div className="text-label-md font-semibold text-on-surface">{n.title}</div>
                    {n.body && <div className="text-label-sm text-on-surface-variant">{n.body}</div>}
                  </div>
                  <span className="text-label-sm text-on-surface-variant">{relative(n.createdAt)}</span>
                </li>
              );
            })}
          </ul>
        </div>
      )}
    </main>
  );
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `pnpm test Alerts`
Expected: PASS (3 testes).

- [ ] **Step 5: Commit**

```bash
git add public/app-child/src/pages/Alerts.tsx public/app-child/src/pages/Alerts.test.tsx
git commit -m "feat(child-app): página Alertas real (query + mark-read)"
```

---

## Task 9: Frontend — badge de não-lidas na BottomNav + App

**Files:**
- Modify: `public/app-child/src/components/BottomNav.tsx`
- Modify: `public/app-child/src/App.tsx`
- Modify: `public/app-child/src/api/types.ts` (já feito na Task 7)
- Test: `public/app-child/src/components/BottomNav.test.tsx`

- [ ] **Step 1: Escrever o teste que falha**

Adicionar em `public/app-child/src/components/BottomNav.test.tsx` (seguir o padrão do arquivo; se novo, criar):
```tsx
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { BottomNav } from './BottomNav';

describe('BottomNav badge', () => {
  it('mostra o ponto de alerta quando há não-lidas', () => {
    const { container } = render(
      <BottomNav activePage="home" onNavigate={() => {}} alertsUnread={2} />,
    );
    expect(container.querySelector('.bg-error')).toBeTruthy();
  });

  it('esconde o ponto quando não há não-lidas', () => {
    const { container } = render(
      <BottomNav activePage="home" onNavigate={() => {}} alertsUnread={0} />,
    );
    expect(container.querySelector('.bg-error')).toBeFalsy();
  });
});
```
> Se o `BottomNav.test.tsx` existente renderiza `<BottomNav>` sem `alertsUnread`, atualizar essas chamadas para passar `alertsUnread={0}` (evita TS/prop faltando).

- [ ] **Step 2: Rodar e ver falhar**

Run: `pnpm test BottomNav`
Expected: FAIL — prop `alertsUnread` não existe / badge sempre presente.

- [ ] **Step 3: Implementar na BottomNav**

Em `src/components/BottomNav.tsx`, trocar o tipo de props e o render do badge:
```tsx
type BottomNavProps = {
  activePage: PageId;
  onNavigate: (page: PageId) => void;
  alertsUnread: number;
};

export function BottomNav({ activePage, onNavigate, alertsUnread }: BottomNavProps) {
```
E a condição do badge:
```tsx
            {item.badge && !isActive && alertsUnread > 0 && (
              <span className="absolute right-3 top-1 h-2 w-2 rounded-full bg-error" />
            )}
```

- [ ] **Step 4: Passar o unread no App**

Em `src/App.tsx`, na renderização do `<BottomNav ... />` (há duas: manter as duas), adicionar a prop a partir do `meQuery` já existente. Localizar `<BottomNav activePage={activePage} onNavigate={setActivePage} />` e trocar por:
```tsx
      <BottomNav
        activePage={activePage}
        onNavigate={setActivePage}
        alertsUnread={meQuery.data?.unreadNotifications ?? 0}
      />
```

- [ ] **Step 5: Rodar e ver passar + tsc**

Run:
```bash
pnpm test BottomNav
pnpm exec tsc -b
```
Expected: testes PASS; TypeScript sem erros.

- [ ] **Step 6: Commit**

```bash
git add public/app-child/src/components/BottomNav.tsx public/app-child/src/App.tsx public/app-child/src/components/BottomNav.test.tsx
git commit -m "feat(child-app): badge de notificações não-lidas na BottomNav"
```

---

## Task 10: Verificação completa + release + deploy

**Files:** `guardkids.php` (versão), release/deploy.

- [ ] **Step 1: Suítes completas**

Run (PHP unit):
```bash
"$PHP" -d extension_dir="$EXT" -d extension=openssl -d extension=mbstring -d extension=sodium \
  vendor/bin/phpunit -c phpunit.xml.dist --testsuite unit
```
Run (app-child):
```bash
cd public/app-child && pnpm test && pnpm exec tsc -b && pnpm build && pnpm test:e2e
```
Expected: tudo verde.

- [ ] **Step 2: PR + CI**

```bash
git push -u origin feat/child-notifications
gh pr create --base master --head feat/child-notifications \
  --title "feat(child-app): notificações in-app (fase 1 de push)" \
  --body "Fundação de notificações: tabela 014, NotificationRepository, Notifier (4 gatilhos), /child/notifications + unread no /me, Alertas real + badge. Migração DB v14. Spec em docs/superpowers/specs/2026-07-02-child-notifications-design.md."
```
Acompanhar CI (4 jobs) verde. **Atenção:** o job Integration roda a migração 014 em MySQL real — se falhar, checar o CREATE TABLE.

- [ ] **Step 3: Merge squash**

```bash
gh pr merge <N> --squash --delete-branch
git checkout master && git pull --ff-only
```

- [ ] **Step 4: Bump da versão do plugin + tag + release**

A migração já bumpou `GUARDKIDS_DB_VERSION` (Task 1). Bumpar `Version:` e `GUARDKIDS_VERSION` em `guardkids.php` para a próxima minor (feature nova; ex.: `1.25.0`), commitar como `chore(release): v1.25.0 — notificações in-app do app-filho`, tag `v1.25.0`, push, gerar zip:
```bash
"$PHP" -d extension_dir="$EXT" -d extension=zip scripts/build-release-zip.php
gh release create v1.25.0 --title "v1.25.0 — notificações in-app do app-filho" \
  --notes "<resumo>" "C:/Users/mysho/OneDrive/Documentos/guardkids-wp/guardkids-wp-1.25.0.zip"
```

- [ ] **Step 5: Deploy SSH + smoke (com migração → confirmar DB version)**

```bash
scp -o BatchMode=yes -P 65002 "<zip>" u217136411@82.25.73.253:~/
ssh -o BatchMode=yes -p 65002 u217136411@82.25.73.253 \
  'cd ~/domains/guardiaokids.site/public_html \
   && cp -r wp-content/plugins/guardkids-wp wp-content/plugins/guardkids-wp.bak-$(date +%Y%m%d-%H%M) \
   && wp plugin install ~/guardkids-wp-1.25.0.zip --force \
   && wp plugin get guardkids-wp --field=version \
   && wp option get guardkids_db_version \
   && rm -f ~/guardkids-wp-1.25.0.zip'
```
Expected: version `1.25.0`, `guardkids_db_version` **14** (a migração rodou). Smoke: `curl` home 200, `/child/notifications` sem token → 401, `/painel-filho` 200.

---

## Self-Review (feito na escrita do plano)

- **Cobertura do spec:** §2 tabela+repo → Tasks 1-2; §3 API → Task 4 (+7-9 front); §4 gatilhos → Tasks 3,4,5,6; §5 frontend → Tasks 7-9; §6 testes → embutidos; §7 fase 2 → fora de escopo (só o gancho `Notifier`). ✅
- **Placeholders:** os Steps de teste de RequestController/SiteController (Tasks 5-6) pedem ao executor para reusar o helper de seed já existente naqueles arquivos — o comportamento a verificar está explícito (nasce 1 notificação do tipo certo). Sem TODO/TBD soltos.
- **Consistência de tipos:** `Notification {id,type,title,body,read,createdAt}` igual no PHP `notificationToJson`, no TS `types.ts` e nos testes; `unreadNotifications` no `/me` e no `Child`; `alertsUnread` prop na BottomNav e no App. `createIfAbsent(childId, dedupKey, data)` e `create(data)` consistentes entre repo, fakes e Notifier. ✅
