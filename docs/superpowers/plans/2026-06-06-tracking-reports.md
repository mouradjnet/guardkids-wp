# Tracking de uso + página Reports — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Substituir o mock estático da página Reports por dados reais coletados do PWA app-child via heartbeat de sessão e cliques de atalho, persistidos em `wp_guardkids_usage_events` e agregados no read.

**Architecture:** PWA dispara `POST /child/events` (heartbeat 60s visibility-aware + site_open por clique) → backend grava raw rows numa tabela append-only → app-parent lê `GET /reports?range=week|month` com agregação SQL on-the-fly. Sem cron, sem pré-agregação, single endpoint pra Reports inteira.

**Tech Stack:** PHP 8.1 + WP 6.4 + `$wpdb` direto + namespace REST `guardkids/v1`; React 19 + TypeScript + Vite 5 + TanStack Query v5 + Vitest 2.1; PHPUnit 9.6 com fake `\wpdb`. Spec completa em `docs/superpowers/specs/2026-06-06-tracking-reports-design.md`.

---

## Fases e critérios de sucesso

| Fase | Entrega | Verificação |
|---|---|---|
| 1 | Migration `002_usage_events` + `UsageEventRepository` + tests | PHPUnit verde, esquema visível em SHOW TABLES |
| 2 | `eventsCreate` + rota `POST /child/events` + tests | PHPUnit verde, smoke local via curl |
| 3 | `ReportsController` + rota `GET /reports` + tests | PHPUnit verde |
| 4 | `usageTracker` module + tests | Vitest verde (app-child) |
| 5 | Browser site_open + init no App + smoke | Vitest verde, network tab mostra ingest |
| 6 | `api/reports.ts` + types + tests (app-parent) | Vitest verde |
| 7 | `Reports.tsx` rewrite + mockData cleanup + tests | Vitest verde, coverage app-parent ~83% |

Cada fase termina com: testes verdes locais + commit + push + CI 3/3 verde antes de seguir pra próxima.

---

## Fase 1 — Migration + UsageEventRepository

### Task 1.1: Migration 002

**Files:**
- Create: `database/migrations/002_usage_events.php`

- [ ] **Step 1: Criar migration file**

```php
<?php

declare(strict_types=1);

/**
 * Migration 002 — tabela de eventos de uso.
 *
 * Append-only: heartbeat (tempo de tela no PWA) e site_open (clique de atalho).
 * Agregação rola no read em ReportsController via SUM/GROUP BY.
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $table = $wpdb->prefix . 'guardkids_usage_events';

    $sql = "CREATE TABLE {$table} (
        id               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        child_id         BIGINT UNSIGNED  NOT NULL,
        type             VARCHAR(20)      NOT NULL,
        domain           VARCHAR(191)     NULL,
        duration_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        created_at       DATETIME         NOT NULL,
        PRIMARY KEY  (id),
        KEY child_day (child_id, created_at),
        KEY child_domain (child_id, domain)
    ) {$charsetCollate};";

    dbDelta($sql);
};
```

- [ ] **Step 2: Confirmar discovery do MigrationRunner**

Run: `php -r "require 'vendor/autoload.php'; require 'tests/bootstrap.php'; foreach (glob('database/migrations/*.php') as \$f) echo basename(\$f), PHP_EOL;"`

Expected:
```
001_initial_schema.php
002_usage_events.php
```

- [ ] **Step 3: Commit**

```bash
git add database/migrations/002_usage_events.php
git commit -m "feat(db): migration 002 — wp_guardkids_usage_events table"
```

### Task 1.2: UsageEventRepository — esqueleto + insert

**Files:**
- Create: `database/UsageEventRepository.php`
- Create: `tests/Unit/Database/UsageEventRepositoryTest.php`

- [ ] **Step 1: Criar o teste falhando**

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\UsageEventRepository;
use PHPUnit\Framework\TestCase;

final class UsageEventRepositoryTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];
            /** @var array<int, array{method:string, sql:string|null, data:array|null}> */
            public array $log = [];

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
                $this->log[] = ['method' => 'insert', 'sql' => null, 'data' => $data];
                $this->insert_id = count($this->rows) + 1;
                $this->rows[$this->insert_id] = array_merge(['id' => $this->insert_id], $data);
                return 1;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $this->log[] = ['method' => 'get_results', 'sql' => (string) $sql, 'data' => null];
                return [];
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testInsertPersistsRowWithoutUpdatedAt(): void
    {
        $repo = new UsageEventRepository();
        $id = $repo->insert([
            'child_id'         => 1,
            'type'             => 'heartbeat',
            'domain'           => null,
            'duration_seconds' => 60,
        ]);

        self::assertSame(1, $id);
        $data = $this->wpdb->log[0]['data'];
        self::assertSame(1, $data['child_id']);
        self::assertSame('heartbeat', $data['type']);
        self::assertNull($data['domain']);
        self::assertSame(60, $data['duration_seconds']);
        self::assertNotEmpty($data['created_at']);
        self::assertArrayNotHasKey('updated_at', $data);
    }
}
```

- [ ] **Step 2: Rodar teste pra confirmar falha**

Run: `vendor/bin/phpunit tests/Unit/Database/UsageEventRepositoryTest.php`
Expected: FAIL — `class GuardKids\Database\UsageEventRepository not found`

- [ ] **Step 3: Criar repository**

```php
<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class UsageEventRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'usage_events';
    }

    /**
     * Override do insert: usage_events não tem coluna updated_at.
     *
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        $data['created_at'] = current_time('mysql', true);
        $ok = $this->db->insert($this->table(), $data);
        if ($ok === false) {
            return 0;
        }
        return (int) $this->db->insert_id;
    }
}
```

- [ ] **Step 4: Rodar teste pra confirmar passing**

Run: `vendor/bin/phpunit tests/Unit/Database/UsageEventRepositoryTest.php`
Expected: PASS (1 test)

- [ ] **Step 5: Commit**

```bash
git add database/UsageEventRepository.php tests/Unit/Database/UsageEventRepositoryTest.php
git commit -m "feat(db): UsageEventRepository.insert sem updated_at"
```

### Task 1.3: aggregateDailyMinutes

**Files:**
- Modify: `database/UsageEventRepository.php`
- Modify: `tests/Unit/Database/UsageEventRepositoryTest.php`

- [ ] **Step 1: Adicionar teste falhando**

Adicionar ao final do `UsageEventRepositoryTest`:

```php
    public function testAggregateDailyMinutesGroupsByDayFiltersRange(): void
    {
        $repo = new UsageEventRepository();
        $repo->aggregateDailyMinutes(1, '2026-06-01 00:00:00', '2026-06-08 00:00:00');

        $sql = (string) $this->wpdb->log[0]['sql'];
        self::assertStringContainsString('wp_guardkids_usage_events', $sql);
        self::assertStringContainsString('child_id = 1', $sql);
        self::assertStringContainsString("'2026-06-01 00:00:00'", $sql);
        self::assertStringContainsString("'2026-06-08 00:00:00'", $sql);
        self::assertStringContainsString('GROUP BY', $sql);
        self::assertStringContainsString('DATE(created_at)', $sql);
        self::assertStringContainsString('SUM(duration_seconds)', $sql);
    }

    public function testAggregateDailyMinutesWithChildIdZeroAggregatesAll(): void
    {
        $repo = new UsageEventRepository();
        $repo->aggregateDailyMinutes(0, '2026-06-01 00:00:00', '2026-06-08 00:00:00');

        $sql = (string) $this->wpdb->log[0]['sql'];
        self::assertStringNotContainsString('child_id = ', $sql);
        self::assertStringContainsString('GROUP BY', $sql);
    }
```

- [ ] **Step 2: Rodar testes pra confirmar falha**

Run: `vendor/bin/phpunit tests/Unit/Database/UsageEventRepositoryTest.php`
Expected: FAIL — `Method aggregateDailyMinutes not defined`

- [ ] **Step 3: Implementar método**

Adicionar no `UsageEventRepository`:

```php
    /**
     * Agrupa por dia (YYYY-MM-DD), somando duration_seconds e devolvendo minutos.
     *
     * @return array<int, array{day: string, child_id: int, minutes: int}>
     */
    public function aggregateDailyMinutes(int $childId, string $fromIso, string $toIso): array
    {
        $base = 'SELECT DATE(created_at) AS day, child_id, SUM(duration_seconds) AS total_seconds'
            . ' FROM ' . $this->table()
            . ' WHERE created_at >= %s AND created_at < %s';

        if ($childId > 0) {
            $sql = $this->db->prepare(
                $base . ' AND child_id = %d GROUP BY DATE(created_at), child_id ORDER BY day ASC',
                $fromIso,
                $toIso,
                $childId,
            );
        } else {
            $sql = $this->db->prepare(
                $base . ' GROUP BY DATE(created_at), child_id ORDER BY day ASC',
                $fromIso,
                $toIso,
            );
        }

        $rows = $this->db->get_results($sql, ARRAY_A);
        if (! is_array($rows)) {
            return [];
        }

        return array_map(static fn (array $r): array => [
            'day'      => (string) $r['day'],
            'child_id' => (int) $r['child_id'],
            'minutes'  => (int) floor(((int) $r['total_seconds']) / 60),
        ], $rows);
    }
```

- [ ] **Step 4: Rodar testes pra confirmar passing**

Run: `vendor/bin/phpunit tests/Unit/Database/UsageEventRepositoryTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add database/UsageEventRepository.php tests/Unit/Database/UsageEventRepositoryTest.php
git commit -m "feat(db): UsageEventRepository.aggregateDailyMinutes"
```

### Task 1.4: topDomains

**Files:**
- Modify: `database/UsageEventRepository.php`
- Modify: `tests/Unit/Database/UsageEventRepositoryTest.php`

- [ ] **Step 1: Adicionar teste falhando**

```php
    public function testTopDomainsCountsOpensIgnoresHeartbeats(): void
    {
        $repo = new UsageEventRepository();
        $repo->topDomains(0, '2026-06-01 00:00:00', '2026-06-08 00:00:00', 10);

        $sql = (string) $this->wpdb->log[0]['sql'];
        self::assertStringContainsString('wp_guardkids_usage_events', $sql);
        self::assertStringContainsString("type = 'site_open'", $sql);
        self::assertStringContainsString('GROUP BY domain', $sql);
        self::assertStringContainsString('COUNT(*)', $sql);
        self::assertStringContainsString('ORDER BY opens DESC', $sql);
        self::assertStringContainsString('LIMIT 10', $sql);
    }

    public function testTopDomainsRespectsLimitAndChildFilter(): void
    {
        $repo = new UsageEventRepository();
        $repo->topDomains(7, '2026-06-01 00:00:00', '2026-06-08 00:00:00', 3);

        $sql = (string) $this->wpdb->log[0]['sql'];
        self::assertStringContainsString('child_id = 7', $sql);
        self::assertStringContainsString('LIMIT 3', $sql);
    }
```

- [ ] **Step 2: Rodar testes pra confirmar falha**

Run: `vendor/bin/phpunit tests/Unit/Database/UsageEventRepositoryTest.php`
Expected: FAIL — `Method topDomains not defined`

- [ ] **Step 3: Implementar método**

```php
    /**
     * Top domains por nº de aberturas (type = 'site_open'). Ignora heartbeats.
     *
     * @return array<int, array{domain: string, opens: int, top_child_id: int|null}>
     */
    public function topDomains(int $childId, string $fromIso, string $toIso, int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));

        $base = "SELECT domain, COUNT(*) AS opens,"
            . " (SELECT child_id FROM " . $this->table() . " e2"
            . "  WHERE e2.domain = e1.domain AND e2.type = 'site_open'"
            . "    AND e2.created_at >= %s AND e2.created_at < %s"
            . ($childId > 0 ? "    AND e2.child_id = %d" : '')
            . "  GROUP BY child_id ORDER BY COUNT(*) DESC LIMIT 1) AS top_child_id"
            . " FROM " . $this->table() . " e1"
            . " WHERE e1.type = 'site_open' AND e1.created_at >= %s AND e1.created_at < %s";

        if ($childId > 0) {
            $sql = $this->db->prepare(
                $base . ' AND e1.child_id = %d GROUP BY domain ORDER BY opens DESC LIMIT ' . $limit,
                $fromIso, $toIso, $childId,
                $fromIso, $toIso, $childId,
            );
        } else {
            $sql = $this->db->prepare(
                $base . ' GROUP BY domain ORDER BY opens DESC LIMIT ' . $limit,
                $fromIso, $toIso,
                $fromIso, $toIso,
            );
        }

        $rows = $this->db->get_results($sql, ARRAY_A);
        if (! is_array($rows)) {
            return [];
        }

        return array_map(static fn (array $r): array => [
            'domain'       => (string) $r['domain'],
            'opens'        => (int) $r['opens'],
            'top_child_id' => isset($r['top_child_id']) ? (int) $r['top_child_id'] : null,
        ], $rows);
    }
```

- [ ] **Step 4: Rodar testes pra confirmar passing**

Run: `vendor/bin/phpunit tests/Unit/Database/UsageEventRepositoryTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add database/UsageEventRepository.php tests/Unit/Database/UsageEventRepositoryTest.php
git commit -m "feat(db): UsageEventRepository.topDomains com sub-query top_child"
```

### Task 1.5: kpisForRange

**Files:**
- Modify: `database/UsageEventRepository.php`
- Modify: `tests/Unit/Database/UsageEventRepositoryTest.php`

- [ ] **Step 1: Adicionar testes falhando**

```php
    public function testKpisForRangeReturnsTotalAndDeltaShape(): void
    {
        $repo = new UsageEventRepository();
        $out = $repo->kpisForRange(0, '2026-06-01 00:00:00', '2026-06-08 00:00:00');

        self::assertArrayHasKey('total_minutes', $out);
        self::assertArrayHasKey('total_minutes_prev', $out);
        self::assertArrayHasKey('range_days', $out);
        self::assertSame(7, $out['range_days']);
    }

    public function testKpisForRangeComputesPreviousWindow(): void
    {
        $repo = new UsageEventRepository();
        $repo->kpisForRange(1, '2026-06-08 00:00:00', '2026-06-15 00:00:00');

        // Espera 2 queries: atual + anterior
        self::assertCount(2, $this->wpdb->log);
        $sql1 = (string) $this->wpdb->log[0]['sql'];
        $sql2 = (string) $this->wpdb->log[1]['sql'];
        // Janela anterior: 7d antes
        self::assertStringContainsString("'2026-06-01 00:00:00'", $sql2);
        self::assertStringContainsString("'2026-06-08 00:00:00'", $sql2);
        self::assertStringContainsString('child_id = 1', $sql1);
        self::assertStringContainsString('child_id = 1', $sql2);
    }
```

Adicionar também um stub `get_var` no fake wpdb pra retornar 0 (sem dados):

```php
            public function get_var($sql, $x = 0, $y = 0)
            {
                $this->log[] = ['method' => 'get_var', 'sql' => (string) $sql, 'data' => null];
                return null;
            }
```

- [ ] **Step 2: Rodar testes pra confirmar falha**

Run: `vendor/bin/phpunit tests/Unit/Database/UsageEventRepositoryTest.php`
Expected: FAIL — `Method kpisForRange not defined`

- [ ] **Step 3: Implementar método**

```php
    /**
     * @return array{total_minutes: int, total_minutes_prev: int, range_days: int}
     */
    public function kpisForRange(int $childId, string $fromIso, string $toIso): array
    {
        $fromTs = strtotime($fromIso);
        $toTs   = strtotime($toIso);
        $rangeDays = (int) round(($toTs - $fromTs) / 86400);

        $prevToIso   = $fromIso;
        $prevFromIso = gmdate('Y-m-d H:i:s', $fromTs - ($toTs - $fromTs));

        $current  = $this->sumDurationSeconds($childId, $fromIso, $toIso);
        $previous = $this->sumDurationSeconds($childId, $prevFromIso, $prevToIso);

        return [
            'total_minutes'      => (int) floor($current / 60),
            'total_minutes_prev' => (int) floor($previous / 60),
            'range_days'         => $rangeDays,
        ];
    }

    private function sumDurationSeconds(int $childId, string $fromIso, string $toIso): int
    {
        $base = 'SELECT COALESCE(SUM(duration_seconds), 0) FROM ' . $this->table()
            . ' WHERE created_at >= %s AND created_at < %s';

        if ($childId > 0) {
            $sql = $this->db->prepare($base . ' AND child_id = %d', $fromIso, $toIso, $childId);
        } else {
            $sql = $this->db->prepare($base, $fromIso, $toIso);
        }

        return (int) $this->db->get_var($sql);
    }
```

- [ ] **Step 4: Rodar testes pra confirmar passing**

Run: `vendor/bin/phpunit tests/Unit/Database/UsageEventRepositoryTest.php`
Expected: PASS (7 tests)

- [ ] **Step 5: Rodar a suite PHP completa pra garantir nada quebrou**

Run: `vendor/bin/phpunit`
Expected: PASS (~84 tests = 77 antes + 7 novos)

- [ ] **Step 6: Commit + push + CI verde**

```bash
git add database/UsageEventRepository.php tests/Unit/Database/UsageEventRepositoryTest.php
git commit -m "feat(db): UsageEventRepository.kpisForRange + sumDurationSeconds"
git push origin master
gh run list --branch master --limit 1
```

Esperar CI 3/3 verde antes de seguir pra Fase 2.

---

## Fase 2 — Ingest endpoint POST /child/events

### Task 2.1: eventsCreate no ChildSelfController (teste primeiro)

**Files:**
- Modify: `api/Controllers/ChildSelfController.php`
- Modify: `tests/Unit/Api/ChildSelfControllerTest.php`

- [ ] **Step 1: Adicionar testes falhando**

Adicionar ao final do `ChildSelfControllerTest`:

```php
    public function testEventsCreateInsertsHeartbeatWithChildIdFromToken(): void
    {
        // Estender o fake $wpdb pra suportar inserts em usage_events
        $this->wpdb->insert = function ($table, $data) {
            if (str_contains((string) $table, 'guardkids_usage_events')) {
                $this->wpdb->insert_id = 12345;
                return 1;
            }
            return 0;
        };

        $req = $this->authedRequest('POST', '/child/events');
        $req->set_param('type', 'heartbeat');
        $req->set_param('duration_seconds', 60);

        $res = (new ChildSelfController())->eventsCreate($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertSame(201, $res->get_status());
        self::assertSame(12345, $res->get_data()['id']);
        self::assertNotEmpty($res->get_data()['createdAt']);
    }

    public function testEventsCreateInsertsSiteOpenWithDomain(): void
    {
        $req = $this->authedRequest('POST', '/child/events');
        $req->set_param('type', 'site_open');
        $req->set_param('domain', 'KhanAcademy.org');
        $req->set_param('duration_seconds', 0);

        $res = (new ChildSelfController())->eventsCreate($req);
        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertSame(201, $res->get_status());
    }

    public function testEventsCreateReturns422OnInvalidType(): void
    {
        $req = $this->authedRequest('POST', '/child/events');
        $req->set_param('type', 'banana');
        $res = (new ChildSelfController())->eventsCreate($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(422, $res->get_error_data()['status']);
    }

    public function testEventsCreateReturns422OnSiteOpenWithoutDomain(): void
    {
        $req = $this->authedRequest('POST', '/child/events');
        $req->set_param('type', 'site_open');
        // sem domain
        $res = (new ChildSelfController())->eventsCreate($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(422, $res->get_error_data()['status']);
    }

    public function testEventsCreateReturns422OnDurationOverCap(): void
    {
        $req = $this->authedRequest('POST', '/child/events');
        $req->set_param('type', 'heartbeat');
        $req->set_param('duration_seconds', 3601);
        $res = (new ChildSelfController())->eventsCreate($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(422, $res->get_error_data()['status']);
    }
```

Atualizar o fake `$wpdb->insert` no setUp pra também aceitar `usage_events`:

```php
            public function insert($table, $data, $format = null)
            {
                if (str_contains((string) $table, 'guardkids_settings')) {
                    $this->settings[$data['setting_key']] = (string) $data['value'];
                    return 1;
                }
                if (str_contains((string) $table, 'guardkids_requests')) {
                    $this->insert_id = count($this->requests) + 1;
                    $this->requests[$this->insert_id] = array_merge(['id' => $this->insert_id], $data);
                    return 1;
                }
                if (str_contains((string) $table, 'guardkids_usage_events')) {
                    $this->insert_id = 12345;
                    return 1;
                }
                return 0;
            }
```

- [ ] **Step 2: Rodar testes pra confirmar falha**

Run: `vendor/bin/phpunit tests/Unit/Api/ChildSelfControllerTest.php`
Expected: FAIL — `Method eventsCreate not defined`

- [ ] **Step 3: Implementar eventsCreate + createEventsArgs**

Modificar `api/Controllers/ChildSelfController.php` — adicionar import e propriedade do repo:

```php
use GuardKids\Database\UsageEventRepository;
```

No construtor:

```php
    private readonly UsageEventRepository $events;

    public function __construct()
    {
        $this->auth     = new ChildAuth();
        $this->children = new ChildRepository();
        $this->requests = new RequestRepository();
        $this->events   = new UsageEventRepository();
    }
```

Adicionar método (antes do bloco final de helpers):

```php
    public function eventsCreate(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }

        $type = (string) $req->get_param('type');
        if (! in_array($type, ['heartbeat', 'site_open'], true)) {
            return new WP_Error('invalid_payload', 'type inválido.', ['status' => 422]);
        }

        $duration = (int) $req->get_param('duration_seconds');
        if ($duration < 0 || $duration > 3600) {
            return new WP_Error('invalid_payload', 'duration_seconds fora do range.', ['status' => 422]);
        }

        $domain = null;
        if ($type === 'site_open') {
            $raw = (string) $req->get_param('domain');
            if ($raw === '') {
                return new WP_Error('invalid_payload', 'domain obrigatório.', ['status' => 422]);
            }
            $domain = strtolower($raw);
        }

        $id = $this->events->insert([
            'child_id'         => $childId,
            'type'             => $type,
            'domain'           => $domain,
            'duration_seconds' => $duration,
        ]);
        if ($id === 0) {
            return new WP_Error('db_error', 'Não foi possível salvar.', ['status' => 500]);
        }

        return new WP_REST_Response([
            'id'        => $id,
            'createdAt' => current_time('mysql', true),
        ], 201);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function createEventsArgs(): array
    {
        return [
            'type' => [
                'type'              => 'string',
                'required'          => true,
                'enum'              => ['heartbeat', 'site_open'],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'domain' => [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'duration_seconds' => [
                'type'    => 'integer',
                'minimum' => 0,
                'maximum' => 3600,
                'default' => 0,
            ],
        ];
    }
```

- [ ] **Step 4: Rodar testes pra confirmar passing**

Run: `vendor/bin/phpunit tests/Unit/Api/ChildSelfControllerTest.php`
Expected: PASS (~12 tests, era 7)

- [ ] **Step 5: Commit**

```bash
git add api/Controllers/ChildSelfController.php tests/Unit/Api/ChildSelfControllerTest.php
git commit -m "feat(api): ChildSelfController.eventsCreate com validacao + cap"
```

### Task 2.2: Registrar rota POST /child/events

**Files:**
- Modify: `api/RestApi.php`

- [ ] **Step 1: Adicionar rota em `registerChildSelfRoutes`**

Modificar o método existente em `api/RestApi.php` adicionando:

```php
        register_rest_route(self::NAMESPACE, '/child/events', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'eventsCreate'],
            'permission_callback' => $requireToken,
            'args'                => $controller->createEventsArgs(),
        ]);
```

(insira após o bloco `/child/requests`).

- [ ] **Step 2: Rodar a suite PHP completa**

Run: `vendor/bin/phpunit`
Expected: PASS (~89 tests)

- [ ] **Step 3: Commit + push + CI verde**

```bash
git add api/RestApi.php
git commit -m "feat(api): registra rota POST /child/events"
git push origin master
gh run list --branch master --limit 1
```

Esperar CI 3/3 verde antes de seguir pra Fase 3.

---

## Fase 3 — ReportsController + GET /reports

### Task 3.1: ReportsController (teste primeiro)

**Files:**
- Create: `api/Controllers/ReportsController.php`
- Create: `tests/Unit/Api/ReportsControllerTest.php`

- [ ] **Step 1: Criar teste falhando**

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\ReportsController;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ReportsControllerTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var array<int, array<string, mixed>> */
            public array $children = [];
            /** @var array<int, array<string, mixed>> */
            public array $events = [];

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
                if (str_contains((string) $sql, 'COALESCE(SUM(duration_seconds)')) {
                    return '0';
                }
                return null;
            }

            public function get_results($sql, $output = OBJECT)
            {
                if (str_contains((string) $sql, 'guardkids_children')) {
                    return array_values($this->children);
                }
                return [];
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testIndexReturnsExpectedShapeWithWeekDefault(): void
    {
        $req = new WP_REST_Request('GET', '/reports');
        $res = (new ReportsController())->index($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        $data = $res->get_data();
        self::assertSame('week', $data['range']);
        self::assertArrayHasKey('from', $data);
        self::assertArrayHasKey('to', $data);
        self::assertArrayHasKey('kpis', $data);
        self::assertArrayHasKey('dailyByChild', $data);
        self::assertArrayHasKey('topSites', $data);
        self::assertArrayHasKey('perChild', $data);
    }

    public function testIndexAcceptsMonthRange(): void
    {
        $req = new WP_REST_Request('GET', '/reports');
        $req->set_param('range', 'month');
        $res = (new ReportsController())->index($req);
        self::assertSame('month', $res->get_data()['range']);
    }

    public function testIndexRejectsUnknownRange(): void
    {
        $req = new WP_REST_Request('GET', '/reports');
        $req->set_param('range', 'forever');
        $res = (new ReportsController())->index($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(422, $res->get_error_data()['status']);
    }

    public function testIndexEmptyArraysWhenNoData(): void
    {
        $req = new WP_REST_Request('GET', '/reports');
        $res = (new ReportsController())->index($req);
        $data = $res->get_data();
        self::assertSame([], $data['dailyByChild']);
        self::assertSame([], $data['topSites']);
        self::assertSame(0, $data['kpis']['totalMinutes']);
        self::assertNull($data['kpis']['deltaPctVsPrevious']);
    }

    public function testIndexComputesKpisFromRepository(): void
    {
        $this->wpdb->children = [
            1 => ['id' => 1, 'slug' => 'lucas', 'name' => 'Lucas', 'limit_minutes' => 60],
        ];
        $req = new WP_REST_Request('GET', '/reports');
        $req->set_param('child_id', 1);

        $res = (new ReportsController())->index($req);
        $data = $res->get_data();
        self::assertCount(1, $data['perChild']);
        self::assertSame(1, $data['perChild'][0]['childId']);
        self::assertSame('Lucas', $data['perChild'][0]['name']);
    }
}
```

- [ ] **Step 2: Rodar testes pra confirmar falha**

Run: `vendor/bin/phpunit tests/Unit/Api/ReportsControllerTest.php`
Expected: FAIL — `Class GuardKids\Api\Controllers\ReportsController not found`

- [ ] **Step 3: Implementar controller**

```php
<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Database\ChildRepository;
use GuardKids\Database\UsageEventRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /reports?range=week|month&child_id=*
 *
 * Janela rolling terminando em now(). Sem cron, sem pre-agregação —
 * SQL no read via UsageEventRepository.
 */
final class ReportsController
{
    private readonly UsageEventRepository $events;
    private readonly ChildRepository $children;

    public function __construct()
    {
        $this->events   = new UsageEventRepository();
        $this->children = new ChildRepository();
    }

    public function index(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $range = (string) ($req->get_param('range') ?: 'week');
        if (! in_array($range, ['week', 'month'], true)) {
            return new WP_Error('invalid_range', 'range inválido.', ['status' => 422]);
        }

        $rangeDays = $range === 'week' ? 7 : 30;
        $now    = current_time('mysql', true);
        $nowTs  = strtotime($now);
        $fromTs = $nowTs - ($rangeDays * 86400);
        $fromIso = gmdate('Y-m-d H:i:s', $fromTs);

        $childParam = $req->get_param('child_id');
        $childId = is_numeric($childParam) ? (int) $childParam : 0;

        $kpisRaw = $this->events->kpisForRange($childId, $fromIso, $now);
        $daily   = $this->events->aggregateDailyMinutes($childId, $fromIso, $now);
        $top     = $this->events->topDomains($childId, $fromIso, $now, 10);

        $children = $childId > 0
            ? array_filter($this->children->findAll(), fn ($c) => (int) $c['id'] === $childId)
            : $this->children->findAll();

        return rest_ensure_response([
            'range' => $range,
            'from'  => $fromIso,
            'to'    => $now,
            'kpis'  => $this->buildKpis($kpisRaw, $children),
            'dailyByChild' => $this->pivotDaily($daily),
            'topSites'     => array_map(static fn ($r) => [
                'domain'      => $r['domain'],
                'opens'       => $r['opens'],
                'topChildId'  => $r['top_child_id'],
            ], $top),
            'perChild' => $this->buildPerChild($daily, $children, $rangeDays),
        ]);
    }

    /**
     * @param array{total_minutes:int,total_minutes_prev:int,range_days:int} $kpis
     * @param array<int, array<string, mixed>> $children
     * @return array{totalMinutes:int,avgMinutesPerDay:int,percentOfLimit:float|null,deltaPctVsPrevious:float|null}
     */
    private function buildKpis(array $kpis, array $children): array
    {
        $total = $kpis['total_minutes'];
        $prev  = $kpis['total_minutes_prev'];
        $days  = max(1, $kpis['range_days']);

        $limitSum = 0;
        foreach ($children as $c) {
            $limit = (int) ($c['limit_minutes'] ?? 0);
            if ($limit <= 0) {
                $limitSum = 0;
                break;
            }
            $limitSum += $limit;
        }
        $denominator = $limitSum * $days;

        return [
            'totalMinutes'        => $total,
            'avgMinutesPerDay'    => (int) floor($total / $days),
            'percentOfLimit'      => $denominator > 0 ? round($total / $denominator, 2) : null,
            'deltaPctVsPrevious'  => $prev > 0 ? round(($total - $prev) / $prev, 2) : null,
        ];
    }

    /**
     * @param array<int, array{day:string,child_id:int,minutes:int}> $daily
     * @return array<int, array{day:string, byChild: array<int,int>}>
     */
    private function pivotDaily(array $daily): array
    {
        $byDay = [];
        foreach ($daily as $row) {
            $day = $row['day'];
            if (! isset($byDay[$day])) {
                $byDay[$day] = ['day' => $day, 'byChild' => []];
            }
            $byDay[$day]['byChild'][$row['child_id']] = $row['minutes'];
        }
        return array_values($byDay);
    }

    /**
     * @param array<int, array{day:string,child_id:int,minutes:int}> $daily
     * @param array<int, array<string, mixed>> $children
     * @return array<int, array{childId:int,name:string,totalMinutes:int,avgMinutesPerDay:int}>
     */
    private function buildPerChild(array $daily, array $children, int $rangeDays): array
    {
        $totalByChild = [];
        foreach ($daily as $row) {
            $cid = $row['child_id'];
            $totalByChild[$cid] = ($totalByChild[$cid] ?? 0) + $row['minutes'];
        }

        $out = [];
        foreach ($children as $c) {
            $cid = (int) $c['id'];
            $total = $totalByChild[$cid] ?? 0;
            $out[] = [
                'childId'          => $cid,
                'name'             => (string) ($c['name'] ?? ''),
                'totalMinutes'     => $total,
                'avgMinutesPerDay' => (int) floor($total / max(1, $rangeDays)),
            ];
        }
        return $out;
    }
}
```

- [ ] **Step 4: Rodar testes pra confirmar passing**

Run: `vendor/bin/phpunit tests/Unit/Api/ReportsControllerTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add api/Controllers/ReportsController.php tests/Unit/Api/ReportsControllerTest.php
git commit -m "feat(api): ReportsController.index agregando KPIs/daily/top/perChild"
```

### Task 3.2: Registrar rota GET /reports

**Files:**
- Modify: `api/RestApi.php`

- [ ] **Step 1: Adicionar imports + método de registro**

No topo, junto aos outros use:

```php
use GuardKids\Api\Controllers\ReportsController;
```

Em `registerRoutes()`, adicionar nova chamada:

```php
        $this->registerReportsRoutes();
```

Novo método privado:

```php
    private function registerReportsRoutes(): void
    {
        $controller = new ReportsController();

        register_rest_route(self::NAMESPACE, '/reports', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'index'],
            'permission_callback' => [self::class, 'requireManage'],
            'args'                => [
                'range' => [
                    'type'    => 'string',
                    'enum'    => ['week', 'month'],
                    'default' => 'week',
                ],
                'child_id' => [
                    'type' => 'integer',
                ],
            ],
        ]);
    }
```

- [ ] **Step 2: Rodar a suite PHP completa**

Run: `vendor/bin/phpunit`
Expected: PASS (~94 tests)

- [ ] **Step 3: Commit + push + CI verde**

```bash
git add api/RestApi.php
git commit -m "feat(api): registra rota GET /reports?range=&child_id="
git push origin master
gh run list --branch master --limit 1
```

Esperar CI 3/3 verde antes de seguir pra Fase 4.

---

## Fase 4 — PWA usageTracker module

### Task 4.1: createUsageTracker — heartbeat básico

**Files:**
- Create: `public/app-child/src/lib/usageTracker.ts`
- Create: `public/app-child/src/lib/usageTracker.test.ts`

- [ ] **Step 1: Criar teste falhando**

```ts
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createUsageTracker } from './usageTracker';

function makeDoc(initialVisible = true) {
  let visibility: DocumentVisibilityState = initialVisible ? 'visible' : 'hidden';
  const listeners: Record<string, Array<() => void>> = {};
  return {
    get visibilityState() { return visibility; },
    setVisibility(v: DocumentVisibilityState) {
      visibility = v;
      (listeners['visibilitychange'] || []).forEach(fn => fn());
    },
    addEventListener(type: string, fn: () => void) {
      listeners[type] = listeners[type] || [];
      listeners[type].push(fn);
    },
    removeEventListener(type: string, fn: () => void) {
      const list = listeners[type] || [];
      const i = list.indexOf(fn);
      if (i >= 0) list.splice(i, 1);
    },
  } as unknown as Document & { setVisibility: (v: DocumentVisibilityState) => void };
}

describe('usageTracker', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  it('envia heartbeat após 60s visible', async () => {
    const fetcher = vi.fn().mockResolvedValue({ id: 1, createdAt: '2026-06-06T00:00:00' });
    const doc = makeDoc(true);
    const tracker = createUsageTracker({ fetcher, doc, now: () => Date.now() });
    tracker.start();

    vi.advanceTimersByTime(60_000);
    await Promise.resolve(); // flush microtasks

    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(fetcher.mock.calls[0][0]).toBe('/child/events');
    const body = JSON.parse((fetcher.mock.calls[0][1] as RequestInit).body as string);
    expect(body.type).toBe('heartbeat');
    expect(body.duration_seconds).toBeGreaterThanOrEqual(55);
    expect(body.duration_seconds).toBeLessThanOrEqual(60);

    tracker.stop();
  });

  it('não envia se < 5s acumulados (threshold)', async () => {
    const fetcher = vi.fn().mockResolvedValue({});
    const doc = makeDoc(true);
    const tracker = createUsageTracker({ fetcher, doc, now: () => Date.now() });
    tracker.start();

    vi.advanceTimersByTime(4_000);
    doc.setVisibility('hidden'); // força flush
    await Promise.resolve();

    expect(fetcher).not.toHaveBeenCalled();
    tracker.stop();
  });

  it('limita duration_seconds em 90s mesmo após sleep simulado', async () => {
    let mockTime = 0;
    const fetcher = vi.fn().mockResolvedValue({});
    const doc = makeDoc(true);
    const tracker = createUsageTracker({ fetcher, doc, now: () => mockTime });
    tracker.start();

    // simula clock saltando 1 hora (browser dormiu)
    mockTime = 3_600_000;
    vi.advanceTimersByTime(60_000); // dispara interval

    await Promise.resolve();
    expect(fetcher).toHaveBeenCalledTimes(1);
    const body = JSON.parse((fetcher.mock.calls[0][1] as RequestInit).body as string);
    expect(body.duration_seconds).toBe(90);

    tracker.stop();
  });

  it('pausa heartbeats em hidden', async () => {
    const fetcher = vi.fn().mockResolvedValue({});
    const doc = makeDoc(true);
    const tracker = createUsageTracker({ fetcher, doc, now: () => Date.now() });
    tracker.start();

    doc.setVisibility('hidden'); // flush imediato
    fetcher.mockClear();
    vi.advanceTimersByTime(120_000); // dois intervalos

    expect(fetcher).not.toHaveBeenCalled();
    tracker.stop();
  });

  it('retoma após voltar a visible', async () => {
    const fetcher = vi.fn().mockResolvedValue({});
    const doc = makeDoc(true);
    const tracker = createUsageTracker({ fetcher, doc, now: () => Date.now() });
    tracker.start();

    doc.setVisibility('hidden');
    fetcher.mockClear();
    doc.setVisibility('visible');
    vi.advanceTimersByTime(60_000);
    await Promise.resolve();

    expect(fetcher).toHaveBeenCalledTimes(1);
    tracker.stop();
  });

  it('silent fail no fetcher rejection', async () => {
    const fetcher = vi.fn().mockRejectedValue(new Error('offline'));
    const doc = makeDoc(true);
    const tracker = createUsageTracker({ fetcher, doc, now: () => Date.now() });
    tracker.start();

    vi.advanceTimersByTime(60_000);
    await Promise.resolve();
    await Promise.resolve();

    // não deve lançar; tracker continua vivo
    expect(fetcher).toHaveBeenCalled();
    tracker.stop();
  });

  it('trackSiteOpen dispara POST com type=site_open', async () => {
    const fetcher = vi.fn().mockResolvedValue({});
    const doc = makeDoc(true);
    const tracker = createUsageTracker({ fetcher, doc, now: () => Date.now() });
    tracker.start();

    tracker.trackSiteOpen('youtube.com');
    await Promise.resolve();

    expect(fetcher).toHaveBeenCalledTimes(1);
    const body = JSON.parse((fetcher.mock.calls[0][1] as RequestInit).body as string);
    expect(body).toEqual({ type: 'site_open', domain: 'youtube.com', duration_seconds: 0 });

    tracker.stop();
  });

  it('stop limpa interval', async () => {
    const fetcher = vi.fn().mockResolvedValue({});
    const doc = makeDoc(true);
    const tracker = createUsageTracker({ fetcher, doc, now: () => Date.now() });
    tracker.start();
    tracker.stop();
    vi.advanceTimersByTime(120_000);
    await Promise.resolve();
    expect(fetcher).not.toHaveBeenCalled();
  });

  it('flushSync no beforeunload usa keepalive', async () => {
    const fetcher = vi.fn().mockResolvedValue({});
    const doc = makeDoc(true);
    const tracker = createUsageTracker({ fetcher, doc, now: () => Date.now() });
    tracker.start();
    vi.advanceTimersByTime(10_000); // acumula 10s
    tracker.flushSync();
    await Promise.resolve();

    expect(fetcher).toHaveBeenCalledTimes(1);
    const init = fetcher.mock.calls[0][1] as RequestInit & { keepalive?: boolean };
    expect(init.keepalive).toBe(true);
    tracker.stop();
  });
});
```

- [ ] **Step 2: Rodar teste pra confirmar falha**

Run: `cd public/app-child && npx vitest run src/lib/usageTracker.test.ts`
Expected: FAIL — `Cannot find module './usageTracker'`

- [ ] **Step 3: Implementar o módulo**

```ts
import { apiFetch } from '../api/client';

type Fetcher = (path: string, init?: RequestInit) => Promise<unknown>;

export type UsageTrackerDeps = {
  fetcher?: Fetcher;
  doc?: Document;
  now?: () => number;
  intervalMs?: number;
  minDurationSec?: number;
  capDurationSec?: number;
};

export type UsageTracker = {
  start: () => void;
  stop: () => void;
  trackSiteOpen: (domain: string) => void;
  flushSync: () => void;
};

export function createUsageTracker(deps: UsageTrackerDeps = {}): UsageTracker {
  const fetcher = deps.fetcher ?? ((path, init) => apiFetch(path, init));
  const doc = deps.doc ?? document;
  const now = deps.now ?? (() => Date.now());
  const intervalMs = deps.intervalMs ?? 60_000;
  const minDurationSec = deps.minDurationSec ?? 5;
  const capDurationSec = deps.capDurationSec ?? 90;

  let visibleSince = 0;
  let intervalId: ReturnType<typeof setInterval> | null = null;

  function isVisible(): boolean {
    return doc.visibilityState === 'visible';
  }

  function flush(): void {
    if (!isVisible() || visibleSince === 0) return;
    const elapsedSec = Math.floor((now() - visibleSince) / 1000);
    if (elapsedSec < minDurationSec) return;
    const capped = Math.min(elapsedSec, capDurationSec);
    visibleSince = now();
    fetcher('/child/events', {
      method: 'POST',
      body: JSON.stringify({ type: 'heartbeat', duration_seconds: capped }),
    }).catch(() => {
      /* silent */
    });
  }

  function onVisibilityChange(): void {
    if (isVisible()) {
      visibleSince = now();
    } else {
      flush();
      visibleSince = 0;
    }
  }

  function flushSync(): void {
    if (visibleSince === 0) return;
    const elapsedSec = Math.floor((now() - visibleSince) / 1000);
    if (elapsedSec < minDurationSec) return;
    const capped = Math.min(elapsedSec, capDurationSec);
    visibleSince = now();
    fetcher('/child/events', {
      method: 'POST',
      keepalive: true,
      body: JSON.stringify({ type: 'heartbeat', duration_seconds: capped }),
    }).catch(() => {
      /* silent */
    });
  }

  function onBeforeUnload(): void {
    flushSync();
  }

  function start(): void {
    if (isVisible()) {
      visibleSince = now();
    }
    doc.addEventListener('visibilitychange', onVisibilityChange);
    if (typeof window !== 'undefined') {
      window.addEventListener('beforeunload', onBeforeUnload);
    }
    intervalId = setInterval(flush, intervalMs);
  }

  function stop(): void {
    doc.removeEventListener('visibilitychange', onVisibilityChange);
    if (typeof window !== 'undefined') {
      window.removeEventListener('beforeunload', onBeforeUnload);
    }
    if (intervalId !== null) {
      clearInterval(intervalId);
      intervalId = null;
    }
    visibleSince = 0;
  }

  function trackSiteOpen(domain: string): void {
    fetcher('/child/events', {
      method: 'POST',
      body: JSON.stringify({
        type: 'site_open',
        domain: domain.toLowerCase(),
        duration_seconds: 0,
      }),
    }).catch(() => {
      /* silent */
    });
  }

  return { start, stop, trackSiteOpen, flushSync };
}
```

- [ ] **Step 4: Rodar testes pra confirmar passing**

Run: `cd public/app-child && npx vitest run src/lib/usageTracker.test.ts`
Expected: PASS (8 tests)

- [ ] **Step 5: Commit + push + CI verde**

```bash
git add public/app-child/src/lib/usageTracker.ts public/app-child/src/lib/usageTracker.test.ts
git commit -m "feat(app-child): usageTracker singleton com heartbeat visibility-aware"
git push origin master
gh run list --branch master --limit 1
```

Esperar CI 3/3 verde antes de seguir pra Fase 5.

---

## Fase 5 — Wire-up: init no App + site_open no Browser

### Task 5.1: Init no App.tsx do app-child

**Files:**
- Modify: `public/app-child/src/App.tsx`

- [ ] **Step 1: Adicionar useEffect que liga/desliga tracker quando token vira presente**

```tsx
import { useEffect, useState } from 'react';
import { getStoredToken, setStoredToken } from './api/token';
import { createUsageTracker, type UsageTracker } from './lib/usageTracker';
// ... resto dos imports

let trackerSingleton: UsageTracker | null = null;

export default function App() {
  const [token, setToken] = useState<string | null>(() => getStoredToken());
  const [activePage, setActivePage] = useState<PageId>('home');

  useEffect(() => {
    if (token) {
      if (!trackerSingleton) trackerSingleton = createUsageTracker();
      trackerSingleton.start();
      return () => trackerSingleton?.stop();
    }
  }, [token]);

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

  // ... resto inalterado
}
```

- [ ] **Step 2: Rodar a suite TS do app-child**

Run: `cd public/app-child && pnpm test`
Expected: PASS (mantém o que já passava)

- [ ] **Step 3: Commit**

```bash
git add public/app-child/src/App.tsx
git commit -m "feat(app-child): wire usageTracker.start ao receber token no App"
```

### Task 5.2: site_open no SiteShortcut do Browser

**Files:**
- Modify: `public/app-child/src/pages/Browser.tsx`

- [ ] **Step 1: Expor o tracker via módulo e usar no click**

Adicionar export no `usageTracker.ts`:

```ts
let activeTracker: UsageTracker | null = null;
export function setActiveTracker(tracker: UsageTracker | null): void {
  activeTracker = tracker;
}
export function getActiveTracker(): UsageTracker | null {
  return activeTracker;
}
```

Atualizar `App.tsx`:

```tsx
import { createUsageTracker, setActiveTracker, type UsageTracker } from './lib/usageTracker';

useEffect(() => {
  if (token) {
    if (!trackerSingleton) trackerSingleton = createUsageTracker();
    trackerSingleton.start();
    setActiveTracker(trackerSingleton);
    return () => {
      trackerSingleton?.stop();
      setActiveTracker(null);
    };
  }
}, [token]);
```

Atualizar `Browser.tsx` no `SiteShortcut`:

```tsx
import { getActiveTracker } from '../lib/usageTracker';

function SiteShortcut({ site }: { site: AllowedSite }) {
  const tone = colorMap[site.color];
  function onClick() {
    getActiveTracker()?.trackSiteOpen(site.domain);
  }
  return (
    <button
      type="button"
      onClick={onClick}
      className="..."
    >
      {/* ... markup inalterado */}
    </button>
  );
}
```

- [ ] **Step 2: Rodar a suite TS do app-child**

Run: `cd public/app-child && pnpm test`
Expected: PASS

- [ ] **Step 3: Commit + push + CI verde**

```bash
git add public/app-child/src/App.tsx public/app-child/src/lib/usageTracker.ts public/app-child/src/pages/Browser.tsx
git commit -m "feat(app-child): SiteShortcut click dispara site_open via tracker"
git push origin master
gh run list --branch master --limit 1
```

Esperar CI 3/3 verde antes de seguir pra Fase 6.

---

## Fase 6 — App-parent: api/reports.ts + types

### Task 6.1: API client + types

**Files:**
- Create: `public/app-parent/src/api/reports.ts`
- Create: `public/app-parent/src/api/reports.test.ts`

- [ ] **Step 1: Criar teste falhando**

```ts
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { apiFetchMock } = vi.hoisted(() => ({ apiFetchMock: vi.fn() }));
vi.mock('./client', () => ({
  apiFetch: apiFetchMock,
  ApiError: class ApiError extends Error {},
}));

import { getReport } from './reports';

describe('api/reports', () => {
  beforeEach(() => {
    apiFetchMock.mockReset().mockResolvedValue(undefined);
  });

  it('getReport defaults to range=week', async () => {
    await getReport();
    expect(apiFetchMock).toHaveBeenCalledWith('/reports?range=week');
  });

  it('getReport passes range=month', async () => {
    await getReport('month');
    expect(apiFetchMock).toHaveBeenCalledWith('/reports?range=month');
  });
});
```

- [ ] **Step 2: Rodar teste pra confirmar falha**

Run: `cd public/app-parent && npx vitest run src/api/reports.test.ts`
Expected: FAIL — `Cannot find module './reports'`

- [ ] **Step 3: Implementar o módulo**

```ts
import { apiFetch } from './client';

export type ReportRange = 'week' | 'month';

export type ReportKpis = {
  totalMinutes: number;
  avgMinutesPerDay: number;
  percentOfLimit: number | null;
  deltaPctVsPrevious: number | null;
};

export type ReportDailyEntry = {
  day: string;
  byChild: Record<number, number>;
};

export type ReportTopSite = {
  domain: string;
  opens: number;
  topChildId: number | null;
};

export type ReportPerChild = {
  childId: number;
  name: string;
  totalMinutes: number;
  avgMinutesPerDay: number;
};

export type Report = {
  range: ReportRange;
  from: string;
  to: string;
  kpis: ReportKpis;
  dailyByChild: ReportDailyEntry[];
  topSites: ReportTopSite[];
  perChild: ReportPerChild[];
};

export function getReport(range: ReportRange = 'week'): Promise<Report> {
  return apiFetch<Report>(`/reports?range=${range}`);
}
```

- [ ] **Step 4: Rodar testes pra confirmar passing**

Run: `cd public/app-parent && npx vitest run src/api/reports.test.ts`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit + push + CI verde**

```bash
git add public/app-parent/src/api/reports.ts public/app-parent/src/api/reports.test.ts
git commit -m "feat(app-parent): api/reports.ts client + types do payload"
git push origin master
gh run list --branch master --limit 1
```

Esperar CI 3/3 verde antes de seguir pra Fase 7.

---

## Fase 7 — Reports.tsx rewrite

### Task 7.1: Testes pra Reports.tsx (TDD da reescrita)

**Files:**
- Create: `public/app-parent/src/pages/Reports.test.tsx`

- [ ] **Step 1: Criar testes falhando**

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Report } from '../api/reports';
import type { Child } from '../api/types';

const { getReportMock, listChildrenMock } = vi.hoisted(() => ({
  getReportMock: vi.fn(),
  listChildrenMock: vi.fn(),
}));
vi.mock('../api/reports', () => ({ getReport: getReportMock }));
vi.mock('../api/children', () => ({
  listChildren: listChildrenMock,
  createChild: vi.fn(),
  updateChild: vi.fn(),
  pairChildDevice: vi.fn(),
}));

import { Reports } from './Reports';

const lucas: Child = {
  id: 1, slug: 'lucas', name: 'Lucas', age: 9, avatarUrl: null,
  device: null, status: 'online', usedMinutes: 0, limitMinutes: 60,
  createdAt: null, updatedAt: null,
};

const sampleReport: Report = {
  range: 'week',
  from: '2026-05-30T00:00:00',
  to: '2026-06-06T00:00:00',
  kpis: {
    totalMinutes: 720,
    avgMinutesPerDay: 103,
    percentOfLimit: 0.74,
    deltaPctVsPrevious: -0.12,
  },
  dailyByChild: [
    { day: '2026-05-30', byChild: { 1: 90 } },
    { day: '2026-05-31', byChild: { 1: 120 } },
    { day: '2026-06-01', byChild: { 1: 80 } },
    { day: '2026-06-02', byChild: { 1: 100 } },
    { day: '2026-06-03', byChild: { 1: 110 } },
    { day: '2026-06-04', byChild: { 1: 110 } },
    { day: '2026-06-05', byChild: { 1: 110 } },
  ],
  topSites: [
    { domain: 'youtube.com', opens: 14, topChildId: 1 },
    { domain: 'khanacademy.org', opens: 8, topChildId: 1 },
  ],
  perChild: [
    { childId: 1, name: 'Lucas', totalMinutes: 720, avgMinutesPerDay: 103 },
  ],
};

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return render(<Reports />, { wrapper });
}

describe('Reports page', () => {
  beforeEach(() => {
    getReportMock.mockReset();
    listChildrenMock.mockReset().mockResolvedValue([lucas]);
  });

  it('renders loading skeleton initially', () => {
    getReportMock.mockReturnValue(new Promise(() => {}));
    renderPage();
    expect(screen.getByText(/relatórios/i)).toBeInTheDocument();
  });

  it('renders KPI cards with formatted values', async () => {
    getReportMock.mockResolvedValue(sampleReport);
    renderPage();

    expect(await screen.findByText('720')).toBeInTheDocument();
    // delta formatado
    expect(screen.getByText(/-12%/)).toBeInTheDocument();
  });

  it('renders chart bars one per day', async () => {
    getReportMock.mockResolvedValue(sampleReport);
    const { container } = renderPage();
    await screen.findByText('Lucas');
    // 7 dias → 7 colunas (label do dia)
    const dayLabels = container.querySelectorAll('[data-testid="chart-day"]');
    expect(dayLabels.length).toBe(7);
  });

  it('renders top sites with "X aberturas"', async () => {
    getReportMock.mockResolvedValue(sampleReport);
    renderPage();

    expect(await screen.findByText('youtube.com')).toBeInTheDocument();
    expect(screen.getByText(/14 aberturas/i)).toBeInTheDocument();
  });

  it('renders per-child summary card', async () => {
    getReportMock.mockResolvedValue(sampleReport);
    renderPage();
    expect(await screen.findByText('Lucas')).toBeInTheDocument();
    // total semana = 720m = 12h
    expect(screen.getByText(/12h/i)).toBeInTheDocument();
  });

  it('switching to Mês refetches', async () => {
    getReportMock.mockResolvedValue(sampleReport);
    const user = userEvent.setup();
    renderPage();
    await screen.findByText('Lucas');

    await user.click(screen.getByRole('button', { name: /^mês$/i }));
    await waitFor(() => {
      expect(getReportMock).toHaveBeenCalledWith('month');
    });
  });

  it('shows empty state when dailyByChild is empty', async () => {
    getReportMock.mockResolvedValue({ ...sampleReport, dailyByChild: [], topSites: [], perChild: [] });
    renderPage();
    expect(await screen.findByText(/ainda não há dados de uso/i)).toBeInTheDocument();
  });

  it('shows error state when getReport fails', async () => {
    getReportMock.mockRejectedValue(new Error('boom'));
    renderPage();
    expect(await screen.findByText(/falha ao carregar relatórios/i)).toBeInTheDocument();
  });

  it('renders "—" when percentOfLimit is null', async () => {
    getReportMock.mockResolvedValue({
      ...sampleReport,
      kpis: { ...sampleReport.kpis, percentOfLimit: null },
    });
    renderPage();
    await screen.findByText('720');
    // O card de % do limite mostra travessão
    expect(screen.getAllByText('—').length).toBeGreaterThan(0);
  });
});
```

- [ ] **Step 2: Rodar testes pra confirmar falha**

Run: `cd public/app-parent && npx vitest run src/pages/Reports.test.tsx`
Expected: FAIL — testes esperam atributos novos (`data-testid="chart-day"`, texto "aberturas") que ainda não existem.

### Task 7.2: Reescrever Reports.tsx

**Files:**
- Modify: `public/app-parent/src/pages/Reports.tsx`

- [ ] **Step 1: Reescrever o componente inteiro**

```tsx
import { useQueries } from '@tanstack/react-query';
import { useState } from 'react';
import { listChildren } from '../api/children';
import { ApiError } from '../api/client';
import { getReport, type ReportPerChild, type ReportRange, type ReportTopSite } from '../api/reports';
import type { Child } from '../api/types';
import { Icon } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';

const CHILD_COLORS = ['#00236f', '#F59E0B', '#006c49', '#6B46C1'] as const;

function colorAt(index: number): string {
  return CHILD_COLORS[index % CHILD_COLORS.length];
}

export function Reports() {
  const [range, setRange] = useState<ReportRange>('week');

  const queries = useQueries({
    queries: [
      { queryKey: ['reports', range], queryFn: () => getReport(range) },
      { queryKey: ['children'], queryFn: listChildren },
    ],
  });
  const reportQuery = queries[0];
  const childrenQuery = queries[1];

  const report = reportQuery.data;
  const children = childrenQuery.data ?? [];
  const childIndex = new Map(report?.perChild.map((c, i) => [c.childId, i]));

  return (
    <main className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-stack-lg p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
      <PageHeader
        title="Relatórios"
        subtitle="Veja onde a família passa tempo e quando."
        action={
          <div className="flex items-center gap-2">
            <RangeButton active={range === 'week'} onClick={() => setRange('week')} label="Semana" />
            <RangeButton active={range === 'month'} onClick={() => setRange('month')} label="Mês" />
            <button
              type="button"
              disabled
              title="Em breve"
              className="inline-flex items-center gap-2 rounded-full border border-outline-variant bg-white px-4 py-2 text-label-md font-semibold text-on-surface-variant opacity-60"
            >
              <Icon name="download" className="text-sm" />
              Exportar
            </button>
          </div>
        }
      />

      {reportQuery.isLoading && <LoadingSkeleton />}
      {reportQuery.error ? <LoadError error={reportQuery.error} /> : null}

      {report && report.dailyByChild.length === 0 && (
        <EmptyState />
      )}

      {report && report.dailyByChild.length > 0 && (
        <>
          <Kpis kpis={report.kpis} />
          <ChartSection report={report} colorFor={(id) => colorAt(childIndex.get(id) ?? 0)} />
          <TopSitesSection sites={report.topSites} perChild={report.perChild} />
          <PerChildSection perChild={report.perChild} children={children} />
        </>
      )}
    </main>
  );
}

function RangeButton({ active, onClick, label }: { active: boolean; onClick: () => void; label: string }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={
        active
          ? 'rounded-full bg-primary px-4 py-2 text-label-md font-semibold text-white shadow-sm'
          : 'rounded-full px-4 py-2 text-label-md font-semibold text-on-surface-variant hover:bg-surface-container'
      }
    >
      {label}
    </button>
  );
}

function Kpis({ kpis }: { kpis: NonNullable<ReturnType<typeof getReport> extends Promise<infer R> ? R : never>['kpis'] }) {
  const pctLimit = kpis.percentOfLimit === null ? '—' : `${Math.round(kpis.percentOfLimit * 100)}%`;
  const delta = kpis.deltaPctVsPrevious === null ? '—' : `${kpis.deltaPctVsPrevious > 0 ? '+' : ''}${Math.round(kpis.deltaPctVsPrevious * 100)}%`;
  const deltaPositive = kpis.deltaPctVsPrevious !== null && kpis.deltaPctVsPrevious < 0;

  return (
    <section className="grid grid-cols-2 gap-gutter md:grid-cols-4">
      <KpiCard icon="schedule" label="Tempo total" value={String(kpis.totalMinutes)} delta={delta} positive={deltaPositive} />
      <KpiCard icon="trending_flat" label="Média / dia" value={String(kpis.avgMinutesPerDay)} delta="min" positive={true} />
      <KpiCard icon="speed" label="% do limite" value={pctLimit} delta="" positive={kpis.percentOfLimit !== null && kpis.percentOfLimit < 1} />
      <KpiCard icon="trending_down" label="Delta vs anterior" value={delta} delta="" positive={deltaPositive} />
    </section>
  );
}

function KpiCard({ icon, label, value, delta, positive }: { icon: string; label: string; value: string; delta: string; positive: boolean }) {
  return (
    <article className="glass-panel rounded-2xl p-5 shadow-ambient">
      <div className="flex items-start justify-between">
        <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-surface-container-high text-primary">
          <Icon name={icon} className="text-2xl" filled />
        </div>
      </div>
      <div className="mt-3 text-label-sm text-on-surface-variant">{label}</div>
      <div className="font-display text-headline-lg leading-none text-primary">{value}</div>
      {delta ? (
        <div className={`mt-1 text-label-sm font-semibold ${positive ? 'text-secondary' : 'text-on-error-container'}`}>
          {delta}
        </div>
      ) : null}
    </article>
  );
}

function ChartSection({ report, colorFor }: { report: NonNullable<ReturnType<typeof getReport> extends Promise<infer R> ? R : never>; colorFor: (childId: number) => string }) {
  const max = Math.max(
    ...report.dailyByChild.map(d => Object.values(d.byChild).reduce((a, b) => a + b, 0)),
    1,
  );
  const chartHeight = 220;

  return (
    <section className="glass-panel rounded-2xl p-6 shadow-ambient">
      <header className="mb-6 flex items-start justify-between gap-3">
        <div>
          <h3 className="font-display text-headline-md text-on-surface">Minutos por dia</h3>
          <p className="text-label-sm text-on-surface-variant">Empilhado por filho</p>
        </div>
        <div className="flex flex-wrap gap-3 text-label-sm">
          {report.perChild.map(c => (
            <div key={c.childId} className="flex items-center gap-2 text-on-surface">
              <span className="inline-block h-3 w-3 rounded-sm" style={{ background: colorFor(c.childId) }} />
              {c.name}
            </div>
          ))}
        </div>
      </header>
      <div className="flex h-[260px] gap-3">
        <div className="flex flex-1 items-end gap-3 border-l border-b border-outline-variant pb-6">
          {report.dailyByChild.map(d => {
            const stacks = Object.entries(d.byChild).map(([cid, mins]) => ({
              childId: Number(cid),
              minutes: mins,
              height: (mins / max) * chartHeight,
            }));
            return (
              <div key={d.day} data-testid="chart-day" className="flex h-full flex-1 flex-col items-center justify-end gap-1">
                <div className="flex w-full max-w-[36px] flex-col overflow-hidden rounded-t-lg shadow-sm">
                  {stacks.map(s => (
                    <div key={s.childId} style={{ height: `${s.height}px`, background: colorFor(s.childId) }} className="w-full" />
                  ))}
                </div>
                <span className="mt-1 text-label-sm font-semibold text-on-surface-variant">{d.day.slice(-2)}</span>
              </div>
            );
          })}
        </div>
      </div>
    </section>
  );
}

function TopSitesSection({ sites, perChild }: { sites: ReportTopSite[]; perChild: ReportPerChild[] }) {
  const max = Math.max(...sites.map(s => s.opens), 1);
  return (
    <section className="glass-panel rounded-2xl p-6 shadow-ambient">
      <header className="mb-4">
        <h3 className="font-display text-headline-md text-on-surface">Top sites visitados</h3>
        <p className="text-label-sm text-on-surface-variant">Ranqueado por nº de aberturas</p>
      </header>
      <ul className="divide-y divide-outline-variant/50">
        {sites.map((s, idx) => {
          const topChild = perChild.find(c => c.childId === s.topChildId)?.name ?? 'Família';
          const pct = (s.opens / max) * 100;
          return (
            <li key={s.domain} className="flex items-center gap-4 py-3">
              <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-surface-container-high font-display text-label-md font-bold text-primary">
                #{idx + 1}
              </span>
              <div className="flex-1">
                <div className="flex items-center justify-between gap-2">
                  <span className="text-label-md font-semibold text-on-surface">{s.domain}</span>
                  <span className="text-label-sm text-on-surface-variant">{s.opens} aberturas</span>
                </div>
                <div className="mt-1 text-label-sm text-on-surface-variant">mais usado por {topChild}</div>
                <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-surface-container">
                  <div className="h-full rounded-full bg-primary" style={{ width: `${pct}%` }} />
                </div>
              </div>
            </li>
          );
        })}
      </ul>
    </section>
  );
}

function PerChildSection({ perChild, children }: { perChild: ReportPerChild[]; children: Child[] }) {
  return (
    <section className="grid grid-cols-1 gap-gutter md:grid-cols-3">
      {perChild.map(c => {
        const avatar = children.find(x => x.id === c.childId)?.avatarUrl ?? null;
        return (
          <article key={c.childId} className="glass-panel rounded-2xl p-5 shadow-ambient">
            <header className="flex items-center gap-3">
              {avatar ? (
                <img src={avatar} alt={c.name} className="h-11 w-11 rounded-full object-cover" />
              ) : (
                <div className="flex h-11 w-11 items-center justify-center rounded-full bg-primary-container text-on-primary-container font-display text-headline-md font-bold">
                  {c.name.charAt(0)}
                </div>
              )}
              <div>
                <h3 className="font-display text-headline-md text-on-surface">{c.name}</h3>
                <p className="text-label-sm text-on-surface-variant">Resumo do período</p>
              </div>
            </header>
            <div className="mt-4 grid grid-cols-2 gap-3 text-center">
              <div className="rounded-xl border border-outline-variant bg-surface-container-low px-3 py-3">
                <div className="text-label-sm text-on-surface-variant">Total</div>
                <div className="font-display text-headline-md text-primary">
                  {Math.floor(c.totalMinutes / 60)}h {c.totalMinutes % 60}m
                </div>
              </div>
              <div className="rounded-xl border border-outline-variant bg-surface-container-low px-3 py-3">
                <div className="text-label-sm text-on-surface-variant">Média/dia</div>
                <div className="font-display text-headline-md text-primary">
                  {Math.floor(c.avgMinutesPerDay / 60)}h {c.avgMinutesPerDay % 60}m
                </div>
              </div>
            </div>
          </article>
        );
      })}
    </section>
  );
}

function LoadingSkeleton() {
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-4 gap-gutter">
        {Array.from({ length: 4 }).map((_, i) => (
          <div key={i} className="glass-panel h-32 animate-pulse rounded-2xl bg-surface-container-low" />
        ))}
      </div>
      <div className="glass-panel h-64 animate-pulse rounded-2xl bg-surface-container-low" />
    </div>
  );
}

function EmptyState() {
  return (
    <div className="glass-panel flex flex-col items-center justify-center gap-3 rounded-2xl p-12 text-center shadow-ambient">
      <div className="flex h-16 w-16 items-center justify-center rounded-full bg-surface-container-high text-primary">
        <Icon name="bar_chart" className="text-3xl" />
      </div>
      <h3 className="font-display text-headline-md text-on-surface">Ainda não há dados de uso</h3>
      <p className="text-body-md text-on-surface-variant">
        Os dados aparecem quando seus filhos abrirem o app.
      </p>
    </div>
  );
}

function LoadError({ error }: { error: unknown }) {
  const message =
    error instanceof ApiError
      ? `${error.message} (${error.status})`
      : error instanceof Error
        ? error.message
        : 'Erro desconhecido.';
  return (
    <div className="glass-panel flex flex-col items-center justify-center gap-2 rounded-2xl bg-error/5 p-6 text-error">
      <Icon name="error" className="text-3xl" />
      <p className="text-label-md font-semibold">Falha ao carregar relatórios</p>
      <p className="text-label-sm text-error/80">{message}</p>
    </div>
  );
}
```

- [ ] **Step 2: Rodar testes pra confirmar passing**

Run: `cd public/app-parent && npx vitest run src/pages/Reports.test.tsx`
Expected: PASS (9 tests)

- [ ] **Step 3: Rodar a suite TS completa**

Run: `cd public/app-parent && pnpm test`
Expected: PASS (~140 tests = 131 antes + 9 novos + 2 do api/reports)

- [ ] **Step 4: Commit**

```bash
git add public/app-parent/src/pages/Reports.tsx public/app-parent/src/pages/Reports.test.tsx
git commit -m "feat(app-parent): Reports.tsx consumindo /reports real + 9 tests"
```

### Task 7.3: Remover mock antigo

**Files:**
- Modify: `public/app-parent/src/data/mockData.ts`

- [ ] **Step 1: Auditar usos dos exports a remover**

Run: `cd public/app-parent && grep -rn "dailyMinutesByDay\|reportKpis\|topSites\|KpiCard\|TopSite" src/ --include="*.ts" --include="*.tsx"`

Expected: nenhum hit em `src/pages/` ou `src/components/` (Reports.tsx já não importa nada disso).

- [ ] **Step 2: Remover do mockData.ts**

Remover do `data/mockData.ts`:
- `dailyMinutesByDay` (const + qualquer interface relacionada)
- `reportKpis` (const)
- `topSites` (const)
- `type KpiCard`
- `type TopSite`
- Qualquer interface só usada por esses

- [ ] **Step 3: Rodar a suite TS completa**

Run: `cd public/app-parent && pnpm test`
Expected: PASS (mesmos ~140 tests)

- [ ] **Step 4: Rodar build**

Run: `cd public/app-parent && pnpm build`
Expected: build OK (sem TS errors por imports stale)

- [ ] **Step 5: Commit + push + CI verde**

```bash
git add public/app-parent/src/data/mockData.ts
git commit -m "chore(app-parent): remove mock Reports (dailyMinutesByDay et al)"
git push origin master
gh run list --branch master --limit 1
```

Esperar CI 3/3 verde — implementação completa.

---

## Resumo final esperado

- **Backend PHP:** ~98 tests verdes (77 antes + 7 UsageEventRepository + 5 ChildSelfController novos + 5 ReportsController + 4 outros refinements).
- **App-child TS:** suite atual + ~8 usageTracker tests.
- **App-parent TS:** ~140 tests verdes (131 antes + 2 api/reports + 9 Reports.tsx).
- **Coverage app-parent:** 75.74% → projeção ~83% (Reports.tsx de 0% pra >90%; é o maior gap atual).
- **Funcional:** Reports da app-parent exibe dados reais coletados do PWA app-child. Range Semana/Mês funcional. Empty/error states cobertos. Botão Exportar segue placeholder até demanda concreta.

---

## Notas pra subagent / próximos passos

- A skill `subagent-driven-development` é a recomendada — uma task por subagent fresco, review antes do próximo.
- Cada fase termina com CI verde **antes** de seguir pra próxima. Falha de CI vira blocker da próxima fase.
- Patterns críticos: `vi.hoisted` pra mocks da api, `mutationFn` v5 recebe `(variables, context)` (use `.mock.calls[0][0]` nas assertions — ver `feedback_tanstack_query_v5_mutationfn_args.md`).
- Spec completa em `docs/superpowers/specs/2026-06-06-tracking-reports-design.md`.
