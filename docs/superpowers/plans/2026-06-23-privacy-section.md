# Privacidade (Export / Limpar histórico / Excluir conta) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tirar do mock as 3 ActionRows da seção "Privacidade" em `Settings.tsx`, ligando-as a 3 endpoints REST reais (export JSON, limpar histórico, excluir dados da família).

**Architecture:** Controller fino (`PrivacyController`) delegando para 2 serviços testáveis (`PrivacyExporter`, `PrivacyEraser`) e para o `Purger` existente (estendido com `purgeOldDecidedRequests`). Frontend: novo `api/privacy.ts` + `DeleteAccountDialog` + wiring em `Settings.tsx`. TDD em cada unidade.

**Tech Stack:** PHP 8.2 + `$wpdb`, PHPUnit (anonymous `wpdb`), namespace REST `guardkids/v1`; React 19 + Vite + TanStack Query + Vitest.

**Spec:** `docs/superpowers/specs/2026-06-23-privacy-section-design.md`

---

## File Structure

**Backend (criar):**
- `includes/Privacy/PrivacyExporter.php` — `collect(): array` agrega as 11 tabelas (omite tokens de `settings`).
- `includes/Privacy/PrivacyEraser.php` — `wipeAll(): array` apaga 9 tabelas + `settings`, preserva guardians.
- `api/Controllers/PrivacyController.php` — `export`/`clearHistory`/`deleteAll`.

**Backend (modificar):**
- `includes/Maintenance/Purger.php` — novo `purgeOldDecidedRequests(int): int` + const `DECIDED_REQUESTS_DAYS`.
- `api/RestApi.php` — `registerPrivacyRoutes()` + chamada em `registerRoutes()` + `use`.

**Backend (testes):**
- `tests/Unit/Maintenance/PurgerTest.php` (modificar — add testes).
- `tests/Unit/Privacy/PrivacyExporterTest.php` (criar).
- `tests/Unit/Privacy/PrivacyEraserTest.php` (criar).
- `tests/Unit/Api/PrivacyControllerTest.php` (criar).

**Frontend (criar):**
- `public/app-parent/src/api/privacy.ts`
- `public/app-parent/src/api/privacy.test.ts`
- `public/app-parent/src/components/DeleteAccountDialog.tsx`
- `public/app-parent/src/components/DeleteAccountDialog.test.tsx`

**Frontend (modificar):**
- `public/app-parent/src/pages/Settings.tsx` — wiring + `ActionRow` ganha `onClick`/`pending`, remove `comingSoon` da Privacidade.
- `public/app-parent/src/pages/Settings.test.tsx` — add testes de privacidade.

**Comandos de teste:**
- PHPUnit: `"/c/Users/mysho/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win32/php.exe" -d extension=mbstring -d extension=openssl -d extension=sodium vendor/bin/phpunit --testsuite unit --filter <Nome>` (PHP 8.2 do LocalWP; se reclamar de `extension_dir`, adicione `-d extension_dir="<...>/ext"`).
- Vitest: `cd public/app-parent && npx vitest run <arquivo>`.

---

## Task 1: Purger — purgeOldDecidedRequests

**Files:**
- Modify: `includes/Maintenance/Purger.php`
- Test: `tests/Unit/Maintenance/PurgerTest.php`

- [ ] **Step 1: Write the failing test**

Adicione ao final de `PurgerTest.php`, antes do último `}` da classe:

```php
    public function testPurgeOldDecidedRequestsTargetsRequestsAndPreservesPending(): void
    {
        $deleted = (new Purger($this->wpdb))->purgeOldDecidedRequests(90);

        self::assertSame(7, $deleted);
        self::assertCount(1, $this->wpdb->queries);
        self::assertStringContainsString('wp_guardkids_requests', $this->wpdb->queries[0]);
        self::assertStringContainsString('decided_at IS NOT NULL', $this->wpdb->queries[0]);
        self::assertStringContainsString('decided_at <', $this->wpdb->queries[0]);
    }

    public function testRunDoesNotTouchRequests(): void
    {
        (new Purger($this->wpdb))->run();

        foreach ($this->wpdb->queries as $sql) {
            self::assertStringNotContainsString('guardkids_requests', $sql);
        }
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `"/c/Users/mysho/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win32/php.exe" -d extension=mbstring -d extension=openssl -d extension=sodium vendor/bin/phpunit --testsuite unit --filter PurgerTest`
Expected: FAIL — `Call to undefined method ... purgeOldDecidedRequests()`.

- [ ] **Step 3: Write minimal implementation**

Em `includes/Maintenance/Purger.php`, adicione a constante junto às existentes:

```php
    public const USAGE_EVENTS_DAYS      = 90;
    public const LOCATIONS_DAYS         = 30;
    public const DECIDED_REQUESTS_DAYS  = 90;
```

E o método novo (depois de `purgeOldLocations`, antes de `purgeBefore`):

```php
    /**
     * Apaga pedidos já decididos (approve/deny) mais antigos que a janela.
     * Pendentes têm `decided_at` NULL e são preservados. NÃO entra no run()
     * do cron — é exclusivo da ação manual "Limpar histórico".
     *
     * @return int linhas removidas (0 quando wpdb falha).
     */
    public function purgeOldDecidedRequests(int $daysOld): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $daysOld * 86400);
        $sql = $this->db->prepare(
            'DELETE FROM ' . $this->db->prefix . 'guardkids_requests'
            . ' WHERE decided_at IS NOT NULL AND decided_at < %s',
            $cutoff,
        );
        $result = $this->db->query($sql);
        return is_numeric($result) ? (int) $result : 0;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `... vendor/bin/phpunit --testsuite unit --filter PurgerTest`
Expected: PASS (todos os testes do Purger, incl. os 2 novos).

- [ ] **Step 5: Commit**

```bash
git add includes/Maintenance/Purger.php tests/Unit/Maintenance/PurgerTest.php
git commit -m "feat(privacy): Purger.purgeOldDecidedRequests para limpar histórico"
```

---

## Task 2: PrivacyExporter

**Files:**
- Create: `includes/Privacy/PrivacyExporter.php`
- Test: `tests/Unit/Privacy/PrivacyExporterTest.php`

- [ ] **Step 1: Write the failing test**

Crie `tests/Unit/Privacy/PrivacyExporterTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Privacy;

use GuardKids\Privacy\PrivacyExporter;
use PHPUnit\Framework\TestCase;

final class PrivacyExporterTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var array<int, string> */
            public array $queries = [];

            public function __construct()
            {
            }

            public function get_results($query = null, $output = ARRAY_A)
            {
                $this->queries[] = (string) $query;
                if (str_contains((string) $query, 'guardkids_children')) {
                    return [['id' => 1, 'name' => 'Lucas']];
                }
                if (str_contains((string) $query, 'guardkids_settings')) {
                    return [
                        ['setting_key' => 'location_enabled', 'value' => 'true'],
                        ['setting_key' => 'child_token:abc', 'value' => '"hash"'],
                    ];
                }
                return [];
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testCollectIncludesAllTablesWithMeta(): void
    {
        $out = (new PrivacyExporter($this->wpdb))->collect();

        self::assertArrayHasKey('exported_at', $out);
        self::assertArrayHasKey('tables', $out);
        self::assertSame([['id' => 1, 'name' => 'Lucas']], $out['tables']['children']);
        self::assertArrayHasKey('guardians', $out['tables']);
        self::assertArrayHasKey('companion_devices', $out['tables']);
    }

    public function testCollectOmitsTokenKeysFromSettings(): void
    {
        $out = (new PrivacyExporter($this->wpdb))->collect();
        $keys = array_column($out['tables']['settings'], 'setting_key');

        self::assertContains('location_enabled', $keys);
        self::assertNotContains('child_token:abc', $keys);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `... vendor/bin/phpunit --testsuite unit --filter PrivacyExporterTest`
Expected: FAIL — classe `PrivacyExporter` não encontrada.

- [ ] **Step 3: Write minimal implementation**

Crie `includes/Privacy/PrivacyExporter.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Privacy;

/**
 * Agrega todas as tabelas do plugin num array exportável (download JSON).
 * Omite as keys reservadas de token de `settings` (`child_token:*`,
 * `companion_token:*`) — mesmo critério do SettingsController.
 */
final class PrivacyExporter
{
    private const TABLES = [
        'children', 'categories', 'requests', 'sites', 'settings',
        'usage_events', 'locations', 'safe_zones', 'guardians',
        'guardian_invites', 'companion_devices',
    ];

    private readonly \wpdb $db;

    public function __construct(?\wpdb $db = null)
    {
        if ($db === null) {
            global $wpdb;
            $db = $wpdb;
        }
        $this->db = $db;
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $tables = [];
        foreach (self::TABLES as $suffix) {
            $rows = $this->db->get_results(
                'SELECT * FROM ' . $this->db->prefix . 'guardkids_' . $suffix,
                ARRAY_A,
            );
            $rows = is_array($rows) ? $rows : [];
            if ($suffix === 'settings') {
                $rows = array_values(array_filter(
                    $rows,
                    static fn (array $r): bool => ! str_contains((string) ($r['setting_key'] ?? ''), ':'),
                ));
            }
            $tables[$suffix] = $rows;
        }

        return [
            'exported_at' => gmdate('c'),
            'site_url'    => home_url(),
            'version'     => defined('GUARDKIDS_VERSION') ? GUARDKIDS_VERSION : 'unknown',
            'tables'      => $tables,
        ];
    }
}
```

Nota: `tests/bootstrap.php` já stuba `home_url()` (usado por outros testes). Se o teste falhar com `home_url() undefined`, adicione um stub no topo do arquivo de teste: `if (!function_exists('home_url')) { function home_url() { return 'http://test.local'; } }`.

- [ ] **Step 4: Run test to verify it passes**

Run: `... vendor/bin/phpunit --testsuite unit --filter PrivacyExporterTest`
Expected: PASS (2 testes).

- [ ] **Step 5: Commit**

```bash
git add includes/Privacy/PrivacyExporter.php tests/Unit/Privacy/PrivacyExporterTest.php
git commit -m "feat(privacy): PrivacyExporter agrega tabelas e omite tokens"
```

---

## Task 3: PrivacyEraser

**Files:**
- Create: `includes/Privacy/PrivacyEraser.php`
- Test: `tests/Unit/Privacy/PrivacyEraserTest.php`

- [ ] **Step 1: Write the failing test**

Crie `tests/Unit/Privacy/PrivacyEraserTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Privacy;

use GuardKids\Privacy\PrivacyEraser;
use PHPUnit\Framework\TestCase;

final class PrivacyEraserTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var array<int, string> */
            public array $queries = [];

            public function __construct()
            {
            }

            public function query($sql)
            {
                $this->queries[] = (string) $sql;
                return 3;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testWipeAllDeletesFamilyTables(): void
    {
        $summary = (new PrivacyEraser($this->wpdb))->wipeAll();

        self::assertSame(3, $summary['children']);
        self::assertSame(3, $summary['settings']);
        self::assertArrayHasKey('companion_devices', $summary);
    }

    public function testWipeAllPreservesGuardians(): void
    {
        (new PrivacyEraser($this->wpdb))->wipeAll();

        foreach ($this->wpdb->queries as $sql) {
            self::assertStringNotContainsString('guardkids_guardians', $sql);
            self::assertStringNotContainsString('guardkids_guardian_invites', $sql);
        }
    }

    public function testWipeAllIssuesDeleteForEachTable(): void
    {
        (new PrivacyEraser($this->wpdb))->wipeAll();

        self::assertCount(9, $this->wpdb->queries);
        foreach ($this->wpdb->queries as $sql) {
            self::assertStringStartsWith('DELETE FROM', $sql);
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `... vendor/bin/phpunit --testsuite unit --filter PrivacyEraserTest`
Expected: FAIL — classe `PrivacyEraser` não encontrada.

- [ ] **Step 3: Write minimal implementation**

Crie `includes/Privacy/PrivacyEraser.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Privacy;

/**
 * Apaga os dados da família (ação "Excluir conta"). Preserva `guardians`,
 * `guardian_invites`, usuários WP e a licença (`wp_options.guardkids_license`).
 * O plugin continua ativo e pronto pra recomeçar do zero.
 */
final class PrivacyEraser
{
    /** Tabelas zeradas. `guardians`/`guardian_invites` ficam de fora de propósito. */
    private const TABLES = [
        'children', 'categories', 'requests', 'sites',
        'usage_events', 'locations', 'safe_zones', 'companion_devices',
        'settings',
    ];

    private readonly \wpdb $db;

    public function __construct(?\wpdb $db = null)
    {
        if ($db === null) {
            global $wpdb;
            $db = $wpdb;
        }
        $this->db = $db;
    }

    /**
     * @return array<string, int> linhas removidas por tabela.
     */
    public function wipeAll(): array
    {
        $summary = [];
        foreach (self::TABLES as $suffix) {
            $table  = $this->db->prefix . 'guardkids_' . $suffix;
            $result = $this->db->query('DELETE FROM ' . $table);
            $summary[$suffix] = is_numeric($result) ? (int) $result : 0;
        }
        return $summary;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `... vendor/bin/phpunit --testsuite unit --filter PrivacyEraserTest`
Expected: PASS (3 testes).

- [ ] **Step 5: Commit**

```bash
git add includes/Privacy/PrivacyEraser.php tests/Unit/Privacy/PrivacyEraserTest.php
git commit -m "feat(privacy): PrivacyEraser zera dados da família e preserva guardians"
```

---

## Task 4: PrivacyController

**Files:**
- Create: `api/Controllers/PrivacyController.php`
- Test: `tests/Unit/Api/PrivacyControllerTest.php`

- [ ] **Step 1: Write the failing test**

Crie `tests/Unit/Api/PrivacyControllerTest.php`. Usa fakes dos 3 serviços via construtor (DI):

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\PrivacyController;
use GuardKids\Maintenance\Purger;
use GuardKids\Privacy\PrivacyEraser;
use GuardKids\Privacy\PrivacyExporter;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

final class PrivacyControllerTest extends TestCase
{
    private function exporter(array $payload): PrivacyExporter
    {
        return new class ($payload) extends PrivacyExporter {
            public function __construct(private array $payload)
            {
            }
            public function collect(): array
            {
                return $this->payload;
            }
        };
    }

    public function testExportReturnsCollectedPayload(): void
    {
        $controller = new PrivacyController(
            $this->exporter(['tables' => ['children' => []]]),
            new PrivacyEraser(new \wpdb()),
            new Purger(new \wpdb()),
        );

        $res = $controller->export();

        self::assertSame(['tables' => ['children' => []]], $res->get_data());
    }

    public function testDeleteAllRejectsWrongConfirm(): void
    {
        $controller = new PrivacyController(
            $this->exporter([]),
            new PrivacyEraser(new \wpdb()),
            new Purger(new \wpdb()),
        );
        $req = new WP_REST_Request();
        $req->set_json_params(['confirm' => 'nope']);

        $res = $controller->deleteAll($req);

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(400, $res->get_error_data()['status']);
    }

    public function testDeleteAllWipesWhenConfirmed(): void
    {
        $eraser = new class (new \wpdb()) extends PrivacyEraser {
            public bool $called = false;
            public function wipeAll(): array
            {
                $this->called = true;
                return ['children' => 2];
            }
        };
        $controller = new PrivacyController($this->exporter([]), $eraser, new Purger(new \wpdb()));
        $req = new WP_REST_Request();
        $req->set_json_params(['confirm' => 'EXCLUIR']);

        $res = $controller->deleteAll($req);

        self::assertTrue($eraser->called);
        self::assertSame(['tables' => ['children' => 2]], $res->get_data());
    }
}
```

Nota: `new \wpdb()` aqui depende do stub de `wpdb` em `tests/bootstrap.php` (já existe — PurgerTest usa `extends \wpdb`). Se o construtor real do stub exigir args, troque por uma subclasse anônima vazia como nos testes anteriores.

- [ ] **Step 2: Run test to verify it fails**

Run: `... vendor/bin/phpunit --testsuite unit --filter PrivacyControllerTest`
Expected: FAIL — classe `PrivacyController` não encontrada.

- [ ] **Step 3: Write minimal implementation**

Crie `api/Controllers/PrivacyController.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Maintenance\Purger;
use GuardKids\Privacy\PrivacyEraser;
use GuardKids\Privacy\PrivacyExporter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Seção Privacidade: exportar dados, limpar histórico antigo e excluir
 * os dados da família. Todas as rotas exigem admin (manage_options).
 */
final class PrivacyController
{
    private readonly PrivacyExporter $exporter;
    private readonly PrivacyEraser $eraser;
    private readonly Purger $purger;

    public function __construct(
        ?PrivacyExporter $exporter = null,
        ?PrivacyEraser $eraser = null,
        ?Purger $purger = null,
    ) {
        $this->exporter = $exporter ?? new PrivacyExporter();
        $this->eraser   = $eraser ?? new PrivacyEraser();
        $this->purger   = $purger ?? new Purger();
    }

    public function export(): WP_REST_Response
    {
        return rest_ensure_response($this->exporter->collect());
    }

    public function clearHistory(): WP_REST_Response
    {
        return rest_ensure_response([
            'usage_events' => $this->purger->purgeOldUsageEvents(Purger::USAGE_EVENTS_DAYS),
            'locations'    => $this->purger->purgeOldLocations(Purger::LOCATIONS_DAYS),
            'requests'     => $this->purger->purgeOldDecidedRequests(Purger::DECIDED_REQUESTS_DAYS),
        ]);
    }

    public function deleteAll(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $params  = $req->get_json_params();
        $confirm = is_array($params) ? ($params['confirm'] ?? null) : null;
        if ($confirm !== 'EXCLUIR') {
            return new WP_Error('invalid_confirm', 'Confirmação inválida.', ['status' => 400]);
        }
        return rest_ensure_response(['tables' => $this->eraser->wipeAll()]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `... vendor/bin/phpunit --testsuite unit --filter PrivacyControllerTest`
Expected: PASS (3 testes).

- [ ] **Step 5: Commit**

```bash
git add api/Controllers/PrivacyController.php tests/Unit/Api/PrivacyControllerTest.php
git commit -m "feat(privacy): PrivacyController (export/clear-history/delete-all)"
```

---

## Task 5: Registrar rotas REST

**Files:**
- Modify: `api/RestApi.php`

- [ ] **Step 1: Adicionar o import**

No bloco de `use` (junto aos outros Controllers, ordem alfabética perto de `MeController`):

```php
use GuardKids\Api\Controllers\PrivacyController;
```

- [ ] **Step 2: Chamar o registro**

Em `registerRoutes()`, adicione antes de `$this->registerCompanionRoutes();`:

```php
        $this->registerPrivacyRoutes();
```

- [ ] **Step 3: Adicionar o método de registro**

Adicione (ex.: logo antes de `registerCompanionRoutes()`):

```php
    private function registerPrivacyRoutes(): void
    {
        $controller = new PrivacyController();

        register_rest_route(self::NAMESPACE, '/privacy/export', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'export'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);

        register_rest_route(self::NAMESPACE, '/privacy/clear-history', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'clearHistory'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);

        register_rest_route(self::NAMESPACE, '/privacy/delete-all', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'deleteAll'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);
    }
```

- [ ] **Step 4: Verificar (suite PHP completa, sem regressão)**

Run: `... vendor/bin/phpunit --testsuite unit`
Expected: PASS — suite verde (inclui os novos testes).

- [ ] **Step 5: Commit**

```bash
git add api/RestApi.php
git commit -m "feat(privacy): registra rotas /privacy/* no namespace guardkids/v1"
```

---

## Task 6: api/privacy.ts (frontend)

**Files:**
- Create: `public/app-parent/src/api/privacy.ts`
- Test: `public/app-parent/src/api/privacy.test.ts`

- [ ] **Step 1: Write the failing test**

Crie `public/app-parent/src/api/privacy.test.ts`:

```ts
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { apiFetchMock } = vi.hoisted(() => ({ apiFetchMock: vi.fn() }));
vi.mock('./client', () => ({
  apiFetch: apiFetchMock,
  ApiError: class ApiError extends Error {},
}));

import { clearHistory, deleteAllData, exportData } from './privacy';

describe('api/privacy', () => {
  beforeEach(() => {
    apiFetchMock.mockReset().mockResolvedValue(undefined);
  });

  it('exportData GETs /privacy/export', async () => {
    await exportData();
    expect(apiFetchMock).toHaveBeenCalledWith('/privacy/export');
  });

  it('clearHistory POSTs /privacy/clear-history', async () => {
    await clearHistory();
    expect(apiFetchMock).toHaveBeenCalledWith('/privacy/clear-history', { method: 'POST' });
  });

  it('deleteAllData POSTs /privacy/delete-all with confirm body', async () => {
    await deleteAllData('EXCLUIR');
    expect(apiFetchMock).toHaveBeenCalledWith('/privacy/delete-all', {
      method: 'POST',
      body: JSON.stringify({ confirm: 'EXCLUIR' }),
    });
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd public/app-parent && npx vitest run src/api/privacy.test.ts`
Expected: FAIL — `./privacy` não existe.

- [ ] **Step 3: Write minimal implementation**

Crie `public/app-parent/src/api/privacy.ts`:

```ts
import { apiFetch } from './client';

export type ExportData = {
  exported_at: string;
  site_url: string;
  version: string;
  tables: Record<string, unknown[]>;
};

export type ClearHistoryResult = {
  usage_events: number;
  locations: number;
  requests: number;
};

export type DeleteAllResult = {
  tables: Record<string, number>;
};

export function exportData(): Promise<ExportData> {
  return apiFetch<ExportData>('/privacy/export');
}

export function clearHistory(): Promise<ClearHistoryResult> {
  return apiFetch<ClearHistoryResult>('/privacy/clear-history', { method: 'POST' });
}

export function deleteAllData(confirm: string): Promise<DeleteAllResult> {
  return apiFetch<DeleteAllResult>('/privacy/delete-all', {
    method: 'POST',
    body: JSON.stringify({ confirm }),
  });
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd public/app-parent && npx vitest run src/api/privacy.test.ts`
Expected: PASS (3 testes).

- [ ] **Step 5: Commit**

```bash
git add public/app-parent/src/api/privacy.ts public/app-parent/src/api/privacy.test.ts
git commit -m "feat(privacy): api/privacy.ts (export/clear-history/delete-all)"
```

---

## Task 7: DeleteAccountDialog

**Files:**
- Create: `public/app-parent/src/components/DeleteAccountDialog.tsx`
- Test: `public/app-parent/src/components/DeleteAccountDialog.test.tsx`

- [ ] **Step 1: Write the failing test**

Crie `public/app-parent/src/components/DeleteAccountDialog.test.tsx`:

```tsx
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { DeleteAccountDialog } from './DeleteAccountDialog';

describe('DeleteAccountDialog', () => {
  it('keeps confirm disabled until EXCLUIR is typed', async () => {
    const onConfirm = vi.fn();
    const user = userEvent.setup();
    render(
      <DeleteAccountDialog open onClose={() => {}} onConfirm={onConfirm} pending={false} />,
    );

    const btn = screen.getByRole('button', { name: /excluir tudo/i });
    expect(btn).toBeDisabled();

    await user.type(screen.getByLabelText(/digite/i), 'EXCLUIR');
    expect(btn).toBeEnabled();

    await user.click(btn);
    expect(onConfirm).toHaveBeenCalledTimes(1);
  });

  it('does not render when closed', () => {
    render(
      <DeleteAccountDialog open={false} onClose={() => {}} onConfirm={() => {}} pending={false} />,
    );
    expect(screen.queryByLabelText(/digite/i)).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd public/app-parent && npx vitest run src/components/DeleteAccountDialog.test.tsx`
Expected: FAIL — `./DeleteAccountDialog` não existe.

- [ ] **Step 3: Write minimal implementation**

Crie `public/app-parent/src/components/DeleteAccountDialog.tsx`:

```tsx
import { useState } from 'react';
import { Icon } from './Icon';

type Props = {
  open: boolean;
  onClose: () => void;
  onConfirm: () => void;
  pending: boolean;
  error?: string | null;
};

const CONFIRM_WORD = 'EXCLUIR';

export function DeleteAccountDialog({ open, onClose, onConfirm, pending, error }: Props) {
  const [value, setValue] = useState('');
  if (!open) return null;

  const armed = value === CONFIRM_WORD && !pending;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-on-surface/40 p-4">
      <div role="dialog" aria-modal="true" className="w-full max-w-md rounded-2xl bg-surface p-6 shadow-ambient">
        <div className="mb-3 flex items-center gap-2 text-error">
          <Icon name="warning" className="text-2xl" filled />
          <h3 className="font-display text-headline-md">Excluir conta e todos os dados</h3>
        </div>
        <p className="mb-4 text-label-md text-on-surface-variant">
          Isso remove permanentemente filhos, pedidos, regras, histórico e configurações.
          Guardiões e a licença são mantidos. Esta ação não pode ser desfeita.
        </p>
        <label className="block text-label-sm font-semibold text-on-surface">
          Digite <span className="font-mono text-error">{CONFIRM_WORD}</span> para confirmar
          <input
            type="text"
            value={value}
            onChange={(e) => setValue(e.target.value)}
            className="mt-1 w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2 text-on-surface focus:outline-none focus:ring-2 focus:ring-error"
          />
        </label>
        {error ? (
          <p role="alert" className="mt-2 text-label-sm text-error">
            Falha ao excluir: {error}
          </p>
        ) : null}
        <div className="mt-5 flex justify-end gap-2">
          <button
            type="button"
            onClick={onClose}
            disabled={pending}
            className="rounded-lg border border-outline-variant bg-surface-container px-4 py-2 text-label-md font-semibold text-on-surface disabled:opacity-60"
          >
            Cancelar
          </button>
          <button
            type="button"
            onClick={onConfirm}
            disabled={!armed}
            className="rounded-lg bg-error px-4 py-2 text-label-md font-semibold text-white disabled:opacity-50"
          >
            {pending ? 'Excluindo…' : 'Excluir tudo'}
          </button>
        </div>
      </div>
    </div>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd public/app-parent && npx vitest run src/components/DeleteAccountDialog.test.tsx`
Expected: PASS (2 testes).

- [ ] **Step 5: Commit**

```bash
git add public/app-parent/src/components/DeleteAccountDialog.tsx public/app-parent/src/components/DeleteAccountDialog.test.tsx
git commit -m "feat(privacy): DeleteAccountDialog com confirmação digite-EXCLUIR"
```

---

## Task 8: Wire Settings.tsx + tornar ActionRow clicável

**Files:**
- Modify: `public/app-parent/src/pages/Settings.tsx`
- Test: `public/app-parent/src/pages/Settings.test.tsx`

- [ ] **Step 1: Write the failing tests**

Adicione os mocks de privacy ao topo de `Settings.test.tsx`, logo após o bloco `vi.mock('../api/guardians', ...)` (linha ~39):

```tsx
const { exportDataMock, clearHistoryMock, deleteAllDataMock } = vi.hoisted(() => ({
  exportDataMock: vi.fn(),
  clearHistoryMock: vi.fn(),
  deleteAllDataMock: vi.fn(),
}));
vi.mock('../api/privacy', () => ({
  exportData: exportDataMock,
  clearHistory: clearHistoryMock,
  deleteAllData: deleteAllDataMock,
}));
```

Adicione os resets dentro do `beforeEach` existente (junto aos outros `.mockReset()`):

```tsx
    exportDataMock.mockReset().mockResolvedValue({
      exported_at: 'x', site_url: 'x', version: '1', tables: {},
    });
    clearHistoryMock.mockReset().mockResolvedValue({ usage_events: 1, locations: 2, requests: 3 });
    deleteAllDataMock.mockReset().mockResolvedValue({ tables: { children: 1 } });
```

Adicione um novo bloco de testes antes do `}` final do `describe`:

```tsx
  it('exports data when "Solicitar" is clicked', async () => {
    listSettingsMock.mockResolvedValue({});
    listGuardiansMock.mockResolvedValue([]);
    const createUrl = vi.fn(() => 'blob:x');
    const revokeUrl = vi.fn();
    vi.stubGlobal('URL', { ...URL, createObjectURL: createUrl, revokeObjectURL: revokeUrl });
    const clickSpy = vi
      .spyOn(HTMLAnchorElement.prototype, 'click')
      .mockImplementation(() => {});
    const user = userEvent.setup();
    render(<Settings />, { wrapper });

    await user.click(await screen.findByRole('button', { name: /solicitar/i }));

    await waitFor(() => expect(exportDataMock).toHaveBeenCalled());
    expect(clickSpy).toHaveBeenCalled();
    clickSpy.mockRestore();
    vi.unstubAllGlobals();
  });

  it('clears history after confirm', async () => {
    listSettingsMock.mockResolvedValue({});
    listGuardiansMock.mockResolvedValue([]);
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const user = userEvent.setup();
    render(<Settings />, { wrapper });

    await user.click(await screen.findByRole('button', { name: /^limpar$/i }));

    expect(confirmSpy).toHaveBeenCalled();
    await waitFor(() => expect(clearHistoryMock).toHaveBeenCalled());
    confirmSpy.mockRestore();
  });

  it('deletes account through the confirm dialog', async () => {
    listSettingsMock.mockResolvedValue({});
    listGuardiansMock.mockResolvedValue([]);
    const user = userEvent.setup();
    render(<Settings />, { wrapper });

    await user.click(await screen.findByRole('button', { name: /^excluir$/i }));
    await user.type(screen.getByLabelText(/digite/i), 'EXCLUIR');
    await user.click(screen.getByRole('button', { name: /excluir tudo/i }));

    await waitFor(() => expect(deleteAllDataMock).toHaveBeenCalledWith('EXCLUIR'));
  });
```

Nota: `wrapper` já existe no arquivo (usado pelos testes atuais). Se os testes existentes usam `renderPage()` em vez de `wrapper`, use o mesmo helper deles.

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd public/app-parent && npx vitest run src/pages/Settings.test.tsx`
Expected: FAIL — botões `Solicitar/Limpar/Excluir` estão `disabled` (mock atual) e o dialog não existe.

- [ ] **Step 3: Implement — imports + mutations + handlers**

Em `Settings.tsx`, adicione aos imports:

```tsx
import { clearHistory, deleteAllData, exportData } from '../api/privacy';
import { DeleteAccountDialog } from '../components/DeleteAccountDialog';
```

Dentro de `Settings()`, depois do `mutation` existente (linha ~27), adicione:

```tsx
  const [deleteOpen, setDeleteOpen] = useState(false);

  const exportMutation = useMutation({
    mutationFn: exportData,
    onSuccess: (data) => {
      const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `guardkids-export-${new Date().toISOString().slice(0, 10)}.json`;
      a.click();
      URL.revokeObjectURL(url);
    },
  });

  const clearMutation = useMutation({ mutationFn: clearHistory });

  const deleteMutation = useMutation({
    mutationFn: () => deleteAllData('EXCLUIR'),
    onSuccess: () => {
      setDeleteOpen(false);
      queryClient.invalidateQueries();
    },
  });

  const handleClearHistory = () => {
    const ok = window.confirm(
      'Limpar histórico antigo? Eventos e pedidos com mais de 90 dias (e localizações com mais de 30 dias) serão removidos permanentemente.',
    );
    if (ok) clearMutation.mutate();
  };
```

- [ ] **Step 4: Implement — seção Privacidade**

Substitua o bloco `<Section ... title="Privacidade" ... comingSoon>...</Section>` (linhas ~193-220) por:

```tsx
      <Section
        icon="policy"
        iconTone="primary"
        title="Privacidade"
        subtitle="Seu controle sobre os dados da família"
      >
        <ActionRow
          icon="download"
          title="Exportar todos os dados"
          description="Baixa um JSON com tudo: filhos, pedidos, histórico, regras e configurações."
          actionLabel="Solicitar"
          onClick={() => exportMutation.mutate()}
          pending={exportMutation.isPending}
        />
        {exportMutation.error ? <MutationError error={exportMutation.error} /> : null}
        <ActionRow
          icon="cleaning_services"
          title="Limpar histórico"
          description="Remove eventos e pedidos com mais de 90 dias e localizações com mais de 30 dias."
          actionLabel="Limpar"
          tone="warn"
          onClick={handleClearHistory}
          pending={clearMutation.isPending}
        />
        {clearMutation.data ? (
          <p className="text-label-sm text-on-surface-variant">
            Removidos: {clearMutation.data.usage_events} eventos, {clearMutation.data.locations}{' '}
            localizações, {clearMutation.data.requests} pedidos.
          </p>
        ) : null}
        {clearMutation.error ? <MutationError error={clearMutation.error} /> : null}
        <ActionRow
          icon="delete_forever"
          title="Excluir conta e todos os dados"
          description="Apaga os dados da família. Mantém guardiões e licença. Ação irreversível."
          actionLabel="Excluir"
          tone="danger"
          onClick={() => setDeleteOpen(true)}
          pending={deleteMutation.isPending}
        />
      </Section>
```

E adicione o dialog antes do `<InviteGuardianDialog ... />` no final do `return`:

```tsx
      <DeleteAccountDialog
        open={deleteOpen}
        onClose={() => setDeleteOpen(false)}
        onConfirm={() => deleteMutation.mutate()}
        pending={deleteMutation.isPending}
        error={
          deleteMutation.error instanceof ApiError
            ? `${deleteMutation.error.message} (${deleteMutation.error.status})`
            : deleteMutation.error instanceof Error
              ? deleteMutation.error.message
              : null
        }
      />
```

- [ ] **Step 5: Implement — ActionRow clicável**

Substitua a função `ActionRow` (linhas ~653-692) por:

```tsx
function ActionRow({
  icon,
  title,
  description,
  actionLabel,
  tone,
  onClick,
  pending,
}: {
  icon: string;
  title: string;
  description: string;
  actionLabel: string;
  tone?: 'warn' | 'danger';
  onClick?: () => void;
  pending?: boolean;
}) {
  return (
    <div className="flex items-start gap-3 rounded-xl border border-outline-variant bg-surface-container-low p-4">
      <div
        className={`flex h-10 w-10 items-center justify-center rounded-xl ${
          tone === 'danger'
            ? 'bg-error-container/60 text-error'
            : tone === 'warn'
              ? 'bg-tertiary-fixed-dim text-on-tertiary-fixed-variant'
              : 'bg-surface-container-high text-primary'
        }`}
      >
        <Icon name={icon} className="text-xl" filled />
      </div>
      <div className="flex-1">
        <h4 className="text-label-md font-bold text-on-surface">{title}</h4>
        <p className="mt-0.5 text-label-sm text-on-surface-variant">{description}</p>
      </div>
      <button
        type="button"
        onClick={onClick}
        disabled={pending}
        className={`shrink-0 rounded-lg border px-4 py-2 text-label-md font-semibold disabled:opacity-60 ${
          tone === 'danger'
            ? 'border-error/40 bg-error/10 text-error hover:bg-error/20'
            : tone === 'warn'
              ? 'border-outline-variant bg-surface-container text-on-surface hover:bg-surface-variant'
              : 'border-outline-variant bg-surface-container text-on-surface hover:bg-surface-variant'
        }`}
      >
        {pending ? '…' : actionLabel}
      </button>
    </div>
  );
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd public/app-parent && npx vitest run src/pages/Settings.test.tsx`
Expected: PASS (testes antigos + 3 novos).

- [ ] **Step 7: Typecheck + build**

Run: `cd public/app-parent && npx tsc --noEmit`
Expected: `0 erros`.

- [ ] **Step 8: Commit**

```bash
git add public/app-parent/src/pages/Settings.tsx public/app-parent/src/pages/Settings.test.tsx
git commit -m "feat(privacy): liga export/limpar/excluir na seção Privacidade"
```

---

## Task 9: Verificação final + build

**Files:** nenhum novo.

- [ ] **Step 1: Suite PHP completa**

Run: `... vendor/bin/phpunit --testsuite unit`
Expected: PASS — verde, sem regressão.

- [ ] **Step 2: Suite Vitest completa do app-parent**

Run: `cd public/app-parent && npx vitest run`
Expected: PASS — verde.

- [ ] **Step 3: Build de produção (gera bundle novo)**

Run: `cd public/app-parent && npm run build`
Expected: build OK, sem erros de tsc.

- [ ] **Step 4: Smoke manual (LocalWP)**

Logado em `https://guardkids-wp.local/painel-pais/` → Configurações → seção Privacidade (sem mais "Em breve"):
- "Solicitar" baixa um `.json`.
- "Limpar" pede confirmação e mostra as contagens.
- "Excluir" abre o modal; botão só habilita ao digitar `EXCLUIR`; ao confirmar, a UI volta ao estado zero (guardiões permanecem).

- [ ] **Step 5: (no release) bump de versão**

O bump de versão (ex.: `1.9.0`, feature minor) + tag + zip + deploy ficam para o momento do release, fora deste plano de implementação. `DB_VERSION` **não muda** (não há migração nesta frente).

---

## Self-Review (autor do plano)

- **Cobertura do spec:** export (T2/T4/T6), omissão de tokens (T2), limpar histórico reusando Purger + requests decididos (T1/T4/T6), excluir preservando guardians/licença (T3/T4), confirmação digite-EXCLUIR (T7), `{confirm}` no backend (T4), sem premium gate (rotas só `requireAdmin`, T5), remover `comingSoon` + wiring (T8). Todos os requisitos têm task.
- **Sem placeholders:** todo step com código tem o código completo.
- **Consistência de tipos:** `PrivacyExporter::collect()`, `PrivacyEraser::wipeAll()`, `Purger::purgeOldDecidedRequests()`, `Purger::DECIDED_REQUESTS_DAYS`, e as funções `exportData/clearHistory/deleteAllData` têm a mesma assinatura entre tasks e consumidores. `ActionRow` ganha `onClick?`/`pending?` consistentes com o uso em T8.
