# Hardening do token do Companion — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Blindar o ciclo de vida do session token do Companion: expiração por janela deslizante (30d, renovada no sync), kill-switch de admin, rate-limit nos endpoints públicos e GC dos pairing tokens — sem mudar o contrato consumido pelo app Android.

**Architecture:** Migration v11 adiciona `session_expires_at` no device. O `CompanionDeviceRepository` renova o expiry no `touchSync` e expõe `revokeSession`; o `CompanionController` grava o expiry no enroll, rejeita expirado no `authenticateSession`, ganha `revoke()` + rate-limit + GC do pairing. Front ganha o botão "Revogar".

**Tech Stack:** PHP 8.2 / WP (`$wpdb`, migrations, `RateLimiter`, `Purger`), PHPUnit (wpdb-fake style do `CompanionControllerTest`), React/TS + Vitest.

**Spec:** `docs/superpowers/specs/2026-06-25-companion-token-hardening-design.md`
**Branch:** `feat/companion-token-hardening` (já criada; spec já commitado nela)

## Ambiente de teste
PHP (LocalWP 8.2):
```bash
PHP82="/c/Users/mysho/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe"
EXTDIR="C:/Users/mysho/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/ext"
RUN_PHP() { "$PHP82" -d extension_dir="$EXTDIR" -d extension=mbstring -d extension=sodium "$@"; }
```
Front: `cd public/app-parent && pnpm vitest run` / `pnpm build`.

## Estrutura de arquivos

| Arquivo | Mudança |
|---|---|
| `database/migrations/011_companion_session_expiry.php` (criar) | coluna `session_expires_at` |
| `guardkids.php` (modificar) | `GUARDKIDS_DB_VERSION` 10→11 (Task 1); Version 1.15.0 (Task 7) |
| `database/CompanionDeviceRepository.php` (modificar) | `SESSION_TTL_DAYS`, `expiryFromNow()`, `touchSync` renova, `revokeSession()` |
| `api/Controllers/CompanionController.php` (modificar) | enroll grava expiry; `authenticateSession` checa expiry; rate-limit; `revoke()`; GC do pairing |
| `api/RestApi.php` (modificar) | rota `POST /companion/revoke` |
| `includes/Maintenance/Purger.php` (modificar) | `purgeExpiredPairingTokens()` + `run()` |
| `tests/Unit/Api/CompanionControllerTest.php` (modificar) | testes de expiry/revoke/rate-limit/GC + reset de transients |
| `tests/Unit/Maintenance/PurgerTest.php` (modificar/criar) | teste do sweep de pairing |
| `public/app-parent/src/api/companion.ts` (modificar) | `revokeCompanion()` |
| `public/app-parent/src/components/CompanionStatusCard.tsx` (modificar) | botão "Revogar dispositivo" |
| `public/app-parent/src/components/CompanionStatusCard.test.tsx` (criar) | teste do botão |

---

### Task 1: Migration 011 + bump DB v11

**Files:**
- Create: `database/migrations/011_companion_session_expiry.php`
- Modify: `guardkids.php:22`

- [ ] **Step 1: Criar a migration** (segue o formato das 003/006/009/010 — closure com `ALTER` via `$wpdb->query`)

```php
<?php

declare(strict_types=1);

/**
 * Migration 011 — expiração do token de sessão do Companion (janela deslizante).
 *
 * enroll/sync gravam `session_expires_at = now + 30d`; authenticateSession
 * rejeita expirado. NULL = device legado (aceito até a próxima sync gravar o
 * expiry — ninguém é deslogado no deploy). ALTER via query direto (dbDelta não
 * aplica ALTER de forma confiável — mesmo padrão das 003/006/009/010).
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $table = $wpdb->prefix . 'guardkids_companion_devices';

    $wpdb->query("ALTER TABLE {$table}
        ADD COLUMN session_expires_at DATETIME NULL AFTER session_token_hash;");
};
```

- [ ] **Step 2: Bump `GUARDKIDS_DB_VERSION`** em `guardkids.php` (regra: migration nova exige bump no mesmo commit — senão `maybeRunMigrations` skipa)

Trocar:
```php
define('GUARDKIDS_DB_VERSION', 10);
```
por:
```php
define('GUARDKIDS_DB_VERSION', 11);
```

- [ ] **Step 3: Suíte unit segue verde** (a migration roda na integração; aqui só garante que nada quebrou)

Run: `RUN_PHP vendor/bin/phpunit --testsuite unit`
Expected: OK (todos). As linhas "migration falhou" mid-run são casos de teste intencionais.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/011_companion_session_expiry.php guardkids.php
git commit -m "feat(companion): migration 011 session_expires_at + bump DB v11"
```

---

### Task 2: Expiração por janela deslizante (repo + controller)

**Files:**
- Modify: `database/CompanionDeviceRepository.php`
- Modify: `api/Controllers/CompanionController.php`
- Test: `tests/Unit/Api/CompanionControllerTest.php`

- [ ] **Step 1: Escrever os testes que falham** (adicionar ao `CompanionControllerTest`, estilo wpdb-fake já existente)

```php
    public function testSyncRejectsExpiredSession(): void
    {
        $token = str_repeat('e', 64);
        $this->seedDevice([
            'device_uuid'        => 'uuid-exp',
            'session_token_hash' => hash('sha256', $token),
            'session_expires_at' => gmdate('Y-m-d H:i:s', time() - 60), // expirado
            'status'             => 'active',
        ]);

        $res = (new CompanionController())->sync($this->request('/companion/sync', $token));

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('companion_auth_required', $res->get_error_code());
        self::assertSame(401, $res->get_error_data()['status']);
    }

    public function testSyncRenewsExpiryWindow(): void
    {
        $token = str_repeat('f', 64);
        $device = $this->seedDevice([
            'device_uuid'        => 'uuid-renew',
            'session_token_hash' => hash('sha256', $token),
            'session_expires_at' => gmdate('Y-m-d H:i:s', time() + 86400), // 1 dia
            'status'             => 'active',
        ]);

        (new CompanionController())->sync($this->request('/companion/sync', $token));

        $renewed = $this->wpdb->devices[$device['id']]['session_expires_at'];
        self::assertGreaterThan(time() + 20 * 86400, strtotime($renewed . ' UTC')); // ~30d à frente
    }
```

- [ ] **Step 2: Rodar e verificar que falham**

Run: `RUN_PHP vendor/bin/phpunit --filter 'testSyncRejectsExpiredSession|testSyncRenewsExpiryWindow'`
Expected: FAIL (a expiração ainda não é checada/renovada).

- [ ] **Step 3: Repo — TTL, expiryFromNow, touchSync renova** (em `CompanionDeviceRepository`)

Adicionar a const logo após a declaração da classe (`final class ... extends Repository {`):
```php
    /** Janela deslizante do token de sessão (renovada a cada sync). */
    public const SESSION_TTL_DAYS = 30;
```
Adicionar o helper (antes do fecho da classe):
```php
    /** Timestamp UTC (mysql) de expiração do token de sessão a partir de agora. */
    public function expiryFromNow(): string
    {
        return gmdate('Y-m-d H:i:s', time() + self::SESSION_TTL_DAYS * 86400);
    }
```
Trocar o `touchSync` para renovar o expiry:
```php
    public function touchSync(int $id, array $patch = []): bool
    {
        $patch['last_sync'] = current_time('mysql', true);
        $patch['session_expires_at'] = $this->expiryFromNow();
        return $this->update($id, $patch);
    }
```

- [ ] **Step 4: Controller — enroll grava expiry + authenticateSession rejeita expirado**

No `enroll()`, trocar o update do device por (adiciona `session_expires_at`):
```php
        $this->devices->update((int) $device['id'], [
            'session_token_hash' => $sessionHash,
            'session_expires_at' => $this->devices->expiryFromNow(),
            'status'             => 'active',
        ]);
```
No `authenticateSession()`, após achar o device pelo hash, inserir o check de expiry antes do `return $device;`:
```php
        $device = $this->devices->findBySessionTokenHash(hash('sha256', $token));
        if ($device === null) {
            return new WP_Error('companion_auth_required', 'Sessão inválida. Refaça o pareamento.', ['status' => 401]);
        }

        $expiresAt = $device['session_expires_at'] ?? null;
        if (is_string($expiresAt) && $expiresAt !== '' && strtotime($expiresAt . ' UTC') < time()) {
            return new WP_Error('companion_auth_required', 'Sessão expirada. Refaça o pareamento.', ['status' => 401]);
        }

        return $device;
```

- [ ] **Step 5: Rodar e verificar que passam** (+ o teste legado `testSyncAcceptsValidSessionTokenWithoutExpiry` continua passando — valida o caminho NULL=legado)

Run: `RUN_PHP vendor/bin/phpunit --filter CompanionControllerTest`
Expected: OK (todos, incl. os 2 novos e o legado de expiry-NULL).

- [ ] **Step 6: Commit**

```bash
git add database/CompanionDeviceRepository.php api/Controllers/CompanionController.php tests/Unit/Api/CompanionControllerTest.php
git commit -m "feat(companion): expiração por janela deslizante do session token"
```

---

### Task 3: Kill-switch (revogação por admin)

**Files:**
- Modify: `database/CompanionDeviceRepository.php`
- Modify: `api/Controllers/CompanionController.php`
- Modify: `api/RestApi.php`
- Test: `tests/Unit/Api/CompanionControllerTest.php`

- [ ] **Step 1: Escrever o teste que falha**

```php
    public function testRevokeClearsSessionAndStatus(): void
    {
        $token = str_repeat('1', 64);
        $device = $this->seedDevice([
            'child_id'           => 7,
            'device_uuid'        => 'uuid-rev',
            'session_token_hash' => hash('sha256', $token),
            'session_expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
            'status'             => 'active',
        ]);

        $req = new WP_REST_Request('POST', '/companion/revoke');
        $req->set_param('child_id', 7);
        $res = (new CompanionController())->revoke($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertTrue($res->get_data()['revoked']);
        self::assertNull($this->wpdb->devices[$device['id']]['session_token_hash']);
        self::assertSame('revoked', $this->wpdb->devices[$device['id']]['status']);

        // o token revogado para de autenticar
        $after = (new CompanionController())->sync($this->request('/companion/sync', $token));
        self::assertInstanceOf(WP_Error::class, $after);
    }

    public function testRevokeWithoutPairedDeviceReturns404(): void
    {
        $req = new WP_REST_Request('POST', '/companion/revoke');
        $req->set_param('child_id', 999);
        $res = (new CompanionController())->revoke($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(404, $res->get_error_data()['status']);
    }
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `RUN_PHP vendor/bin/phpunit --filter 'testRevoke'`
Expected: FAIL (método `revoke` não existe).

- [ ] **Step 3: Repo — `revokeSession`** (em `CompanionDeviceRepository`, antes do fecho da classe)

```php
    /** Revoga a sessão do device sem re-parear: zera hash+expiry, marca revoked. */
    public function revokeSession(int $id): bool
    {
        return $this->update($id, [
            'session_token_hash' => null,
            'session_expires_at' => null,
            'status'             => 'revoked',
        ]);
    }
```

- [ ] **Step 4: Controller — `revoke()` + `revokeArgs()`** (em `CompanionController`, junto às outras ações)

```php
    public function revoke(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = (int) $req->get_param('child_id');
        if ($childId <= 0) {
            return new WP_Error('invalid_payload', 'child_id obrigatório.', ['status' => 422]);
        }
        $device = $this->devices->findByChildId($childId);
        if ($device === null) {
            return new WP_Error('not_found', 'Nenhum dispositivo pareado.', ['status' => 404]);
        }
        $this->devices->revokeSession((int) $device['id']);
        return rest_ensure_response(['revoked' => true]);
    }

    public function revokeArgs(): array
    {
        return [
            'child_id' => ['type' => 'integer', 'required' => true, 'minimum' => 1],
        ];
    }
```

- [ ] **Step 5: Registrar a rota em `api/RestApi.php`** (dentro de `registerCompanionRoutes()`, junto às outras rotas do companion)

```php
        register_rest_route(self::NAMESPACE, '/companion/revoke', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'revoke'],
            'permission_callback' => [self::class, 'requireAdmin'],
            'args'                => $controller->revokeArgs(),
        ]);
```

- [ ] **Step 6: Rodar a suíte unit inteira**

Run: `RUN_PHP vendor/bin/phpunit --testsuite unit`
Expected: OK (todos).

- [ ] **Step 7: Commit**

```bash
git add database/CompanionDeviceRepository.php api/Controllers/CompanionController.php api/RestApi.php tests/Unit/Api/CompanionControllerTest.php
git commit -m "feat(companion): kill-switch de revogação por admin"
```

---

### Task 4: Rate-limit nos endpoints públicos

**Files:**
- Modify: `api/Controllers/CompanionController.php`
- Test: `tests/Unit/Api/CompanionControllerTest.php`

- [ ] **Step 1: Isolar transients no setUp do teste + escrever o teste que falha**

No `CompanionControllerTest::setUp()`, no fim (após `$GLOBALS['wpdb'] = $this->wpdb;`), adicionar:
```php
        $GLOBALS['gk_transients'] = [];
```
Novo teste:
```php
    public function testSyncIsRateLimited(): void
    {
        $token = str_repeat('2', 64);
        $device = $this->seedDevice([
            'device_uuid'        => 'uuid-rl',
            'session_token_hash' => hash('sha256', $token),
            'session_expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
            'status'             => 'active',
        ]);
        // pré-enche o bucket no limite (default 60) → próxima chamada estoura
        $GLOBALS['gk_transients']['gk_rate:companion_sync:' . $device['id']] = 60;

        $res = (new CompanionController())->sync($this->request('/companion/sync', $token));

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(429, $res->get_error_data()['status']);
    }
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `RUN_PHP vendor/bin/phpunit --filter testSyncIsRateLimited`
Expected: FAIL (sem rate-limit, devolve 200).

- [ ] **Step 3: Controller — helper + aplicar em sync/heartbeat/enroll**

Adicionar o import no topo do arquivo (junto aos outros `use`):
```php
use GuardKids\Security\RateLimiter;
```
Adicionar o helper (na seção de helpers privados):
```php
    private function rateLimited(string $endpoint, int $deviceId): ?WP_Error
    {
        if (! (new RateLimiter())->allow($endpoint, $deviceId)) {
            return new WP_Error('too_many', 'Muitas requisições. Tente novamente em instantes.', ['status' => 429]);
        }
        return null;
    }
```
Em `sync()`, logo após o `authenticateSession` (antes de `extractDevicePatch`):
```php
        if (($limited = $this->rateLimited('companion_sync', (int) $device['id'])) !== null) {
            return $limited;
        }
```
Em `heartbeat()`, logo após o `authenticateSession`:
```php
        if (($limited = $this->rateLimited('companion_heartbeat', (int) $device['id'])) !== null) {
            return $limited;
        }
```
Em `enroll()`, logo após o `[$device, $pairingKey] = $pairing;`:
```php
        if (($limited = $this->rateLimited('companion_enroll', (int) $device['id'])) !== null) {
            return $limited;
        }
```

- [ ] **Step 4: Rodar e verificar que passa + suíte inteira**

Run: `RUN_PHP vendor/bin/phpunit --filter CompanionControllerTest`
Expected: OK (todos — os testes que chamam sync 1x ficam bem abaixo de 60).

- [ ] **Step 5: Commit**

```bash
git add api/Controllers/CompanionController.php tests/Unit/Api/CompanionControllerTest.php
git commit -m "feat(companion): rate-limit em enroll/sync/heartbeat"
```

---

### Task 5: GC dos pairing tokens expirados

**Files:**
- Modify: `api/Controllers/CompanionController.php`
- Modify: `includes/Maintenance/Purger.php`
- Test: `tests/Unit/Api/CompanionControllerTest.php`
- Test: `tests/Unit/Maintenance/PurgerTest.php`

- [ ] **Step 1: Teste do delete no authenticatePairing (no CompanionControllerTest)**

```php
    public function testEnrollDeletesExpiredPairingToken(): void
    {
        $token = str_repeat('3', 64);
        $key = 'companion_token:' . hash('sha256', $token);
        $this->wpdb->settings[$key] = (string) wp_json_encode([
            'childId'    => 5,
            'deviceUuid' => 'uuid-pair',
            'createdAt'  => gmdate('c', time() - 3600),
            'expiresAt'  => gmdate('c', time() - 60), // expirado
        ]);
        $this->seedDevice(['device_uuid' => 'uuid-pair']);

        $res = (new CompanionController())->enroll($this->request('/companion/enroll', $token));

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(401, $res->get_error_data()['status']);
        self::assertContains($key, $this->wpdb->deletedKeys);
    }
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `RUN_PHP vendor/bin/phpunit --filter testEnrollDeletesExpiredPairingToken`
Expected: FAIL (a key expirada não é deletada hoje).

- [ ] **Step 3: Controller — deletar no ramo de expiry do authenticatePairing**

Em `authenticatePairing()`, trocar o bloco de expiry por (adiciona o `deleteByKey`):
```php
        $expiresAt = isset($data['expiresAt']) && is_string($data['expiresAt'])
            ? strtotime($data['expiresAt'])
            : false;
        if ($expiresAt === false || $expiresAt < time()) {
            $this->settings->deleteByKey($key);
            return new WP_Error('companion_auth_required', 'Token de pareamento expirado.', ['status' => 401]);
        }
```

- [ ] **Step 4: Purger — sweep periódico** (em `includes/Maintenance/Purger.php`)

Adicionar a const (junto às outras consts):
```php
    public const PAIRING_TOKEN_PREFIX = 'companion_token:';
```
No `run()`, adicionar a chamada:
```php
    public function run(): void
    {
        $this->purgeOldUsageEvents(self::USAGE_EVENTS_DAYS);
        $this->purgeOldLocations(self::LOCATIONS_DAYS);
        $this->purgeExpiredPairingTokens();
    }
```
Adicionar o método:
```php
    /**
     * Remove pairing tokens (companion_token:*) com expiresAt vencido. O delete
     * on-demand cobre tokens reapresentados; este sweep limpa os abandonados.
     *
     * @return int linhas removidas
     */
    public function purgeExpiredPairingTokens(): int
    {
        $table = $this->db->prefix . 'guardkids_settings';
        $rows = $this->db->get_results(
            "SELECT setting_key, value FROM {$table} WHERE setting_key LIKE '" . self::PAIRING_TOKEN_PREFIX . "%'",
            ARRAY_A,
        );
        if (! is_array($rows)) {
            return 0;
        }
        $now = time();
        $removed = 0;
        foreach ($rows as $row) {
            $data = json_decode((string) ($row['value'] ?? ''), true);
            $exp = is_array($data) && isset($data['expiresAt']) ? strtotime((string) $data['expiresAt']) : 0;
            if ($exp === false || $exp < $now) {
                $this->db->delete($table, ['setting_key' => $row['setting_key']]);
                $removed++;
            }
        }
        return $removed;
    }
```

- [ ] **Step 5: Teste do Purger** (adicionar ao `tests/Unit/Maintenance/PurgerTest.php`; se o wpdb-fake de lá não cobrir `get_results`/`delete` em settings, replicar o mínimo)

```php
    public function testPurgeExpiredPairingTokens(): void
    {
        $wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var list<array<string, string>> */
            public array $rows = [];
            /** @var list<string> */
            public array $deleted = [];
            public function __construct() {}
            public function get_results($sql, $output = ARRAY_A)
            {
                return $this->rows;
            }
            public function delete($table, $where, $where_format = null)
            {
                $this->deleted[] = (string) $where['setting_key'];
                return 1;
            }
        };
        $wpdb->rows = [
            ['setting_key' => 'companion_token:aaa', 'value' => (string) json_encode(['expiresAt' => gmdate('c', time() - 60)])],
            ['setting_key' => 'companion_token:bbb', 'value' => (string) json_encode(['expiresAt' => gmdate('c', time() + 600)])],
        ];

        $removed = (new \GuardKids\Maintenance\Purger($wpdb))->purgeExpiredPairingTokens();

        self::assertSame(1, $removed);
        self::assertSame(['companion_token:aaa'], $wpdb->deleted);
    }
```

- [ ] **Step 6: Rodar e verificar que passam + suíte inteira**

Run: `RUN_PHP vendor/bin/phpunit --testsuite unit`
Expected: OK (todos, incl. os novos).

- [ ] **Step 7: Commit**

```bash
git add api/Controllers/CompanionController.php includes/Maintenance/Purger.php tests/Unit/Api/CompanionControllerTest.php tests/Unit/Maintenance/PurgerTest.php
git commit -m "feat(companion): GC dos pairing tokens expirados (on-demand + Purger)"
```

---

### Task 6: Frontend — revogar no painel

**Files:**
- Modify: `public/app-parent/src/api/companion.ts`
- Modify: `public/app-parent/src/components/CompanionStatusCard.tsx`
- Test: `public/app-parent/src/components/CompanionStatusCard.test.tsx`

- [ ] **Step 1: Client — `revokeCompanion`** (adicionar ao fim de `companion.ts`)

```ts
export function revokeCompanion(childId: number): Promise<{ revoked: boolean }> {
  return apiFetch<{ revoked: boolean }>('/companion/revoke', {
    method: 'POST',
    body: JSON.stringify({ child_id: childId }),
  });
}
```

- [ ] **Step 2: Teste do componente que falha** (`CompanionStatusCard.test.tsx`)

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CompanionStatusCard } from './CompanionStatusCard';
import * as api from '../api/companion';

function wrap(ui: ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

const pairedStatus = {
  paired: true, status: 'active', deviceUuid: 'u1', deviceName: 'Moto',
  androidVersion: '13', companionVersion: '1.0', deviceOwnerEnabled: true,
  accessibilityEnabled: true, deviceAdminEnabled: true, playStoreEnabled: false,
  lastSync: null,
} as const;

describe('CompanionStatusCard', () => {
  beforeEach(() => vi.restoreAllMocks());

  it('mostra "Revogar" quando pareado e chama a API ao confirmar', async () => {
    vi.spyOn(api, 'getCompanionStatus').mockResolvedValue({ ...pairedStatus });
    const revoke = vi.spyOn(api, 'revokeCompanion').mockResolvedValue({ revoked: true });
    vi.spyOn(window, 'confirm').mockReturnValue(true);

    wrap(<CompanionStatusCard childId={7} childName="Lucas" />);
    fireEvent.click(await screen.findByRole('button', { name: /revogar/i }));
    await waitFor(() => expect(revoke).toHaveBeenCalledWith(7));
  });

  it('não mostra "Revogar" quando não pareado', async () => {
    vi.spyOn(api, 'getCompanionStatus').mockResolvedValue({ ...pairedStatus, paired: false, status: 'unpaired' });
    wrap(<CompanionStatusCard childId={7} childName="Lucas" />);
    await screen.findByText(/status do companion/i);
    expect(screen.queryByRole('button', { name: /revogar/i })).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 3: Rodar e verificar que falha**

Run: `cd public/app-parent && pnpm vitest run CompanionStatusCard`
Expected: FAIL (sem botão Revogar).

- [ ] **Step 4: Componente — botão Revogar** (em `CompanionStatusCard.tsx`)

Trocar os imports do topo:
```tsx
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { getCompanionStatus, revokeCompanion, type CompanionStatus } from '../api/companion';
```
No corpo de `CompanionStatusCard`, após o `useQuery`, adicionar a mutation:
```tsx
  const qc = useQueryClient();
  const revoke = useMutation({
    mutationFn: () => revokeCompanion(childId),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['companion', 'status', childId] }),
  });
```
Dentro do JSX, depois do bloco `{data && !isLoading && <StatusGrid data={data} />}`, adicionar:
```tsx
      {data?.paired && !isLoading && (
        <button
          type="button"
          className="mt-4 rounded-lg border border-error/40 bg-error/10 px-4 py-2 text-label-lg text-error"
          disabled={revoke.isPending}
          onClick={() => {
            if (window.confirm('Revogar este dispositivo? Ele precisará ser pareado de novo para voltar a sincronizar.')) {
              revoke.mutate();
            }
          }}
        >
          {revoke.isPending ? 'Revogando…' : 'Revogar dispositivo'}
        </button>
      )}
```

- [ ] **Step 5: Rodar e verificar que passa (2 testes) + suíte inteira + build**

Run: `cd public/app-parent && pnpm vitest run CompanionStatusCard`
Expected: PASS (2).
Run: `cd public/app-parent && pnpm vitest run`
Expected: PASS (todos).
Run: `cd public/app-parent && pnpm build`
Expected: build OK (tsc clean).

- [ ] **Step 6: Commit**

```bash
git add public/app-parent/src/api/companion.ts public/app-parent/src/components/CompanionStatusCard.tsx public/app-parent/src/components/CompanionStatusCard.test.tsx
git commit -m "feat(companion): botão Revogar dispositivo no painel"
```

---

### Task 7: Bump v1.15.0 + gate + PR + deploy

**Files:**
- Modify: `guardkids.php:5` e `guardkids.php:21`

- [ ] **Step 1: Bump da versão do plugin** (o DB já foi pra 11 na Task 1)

Header: `* Version:           1.15.0` · Constante: `define('GUARDKIDS_VERSION', '1.15.0');`.

- [ ] **Step 2: Commit**

```bash
git add guardkids.php
git commit -m "chore(release): bump v1.15.0 (hardening do token do Companion)"
```

- [ ] **Step 3: Gate completo**

```bash
RUN_PHP vendor/bin/phpunit --testsuite unit      # OK (todos, incl. companion novos)
cd public/app-parent && pnpm vitest run          # PASS
cd public/app-child && pnpm vitest run           # PASS
```

- [ ] **Step 4: Push + PR (CI roda a migration real na integração)**

```bash
git push -u origin feat/companion-token-hardening
gh pr create --base master --head feat/companion-token-hardening \
  --title "feat(security): hardening do token do Companion (v1.15.0)" \
  --body "Implementa docs/superpowers/specs/2026-06-25-companion-token-hardening-design.md: expiração por janela deslizante + kill-switch do session token (transparente pro app Android), rate-limit nos endpoints públicos, GC dos pairing tokens. Migration v11. Suítes verdes."
```

- [ ] **Step 5: CI verde** — `gh pr checks <n> --watch`. A **PHPUnit Integration (MySQL real)** roda a migration 011 de verdade — confirmar verde. Só seguir com 100% verde.

- [ ] **Step 6: Merge + zip + release + deploy** — merge (CI verde, sem `--admin`) → `pnpm build` (app-parent) → `scripts/build-release-zip.php` (guardkids-wp-1.15.0.zip) → tag v1.15.0 + release → deploy via SSH. **TEM MIGRATION:** após o deploy, conferir `wp option get guardkids_db_version` = **11** (o fallback `maybeRunMigrations` roda no primeiro request). Smoke: revogar um device no painel → próxima sync dele cai em 401.

---

## Self-Review

**1. Cobertura do spec:**
- Migration v11 `session_expires_at` + bump → Task 1. ✅
- Janela deslizante (enroll grava, authenticateSession rejeita, touchSync renova) → Task 2. ✅
- Kill-switch (repo `revokeSession` + controller `revoke` + rota requireAdmin) → Task 3. ✅
- Rate-limit enroll/sync/heartbeat → Task 4. ✅
- GC pairing (on-demand no authenticatePairing + Purger sweep) → Task 5. ✅
- Frontend (`revokeCompanion` + botão no CompanionStatusCard) → Task 6. ✅
- Device legado NULL aceito → Task 2 (check só rejeita string não-vazia < now); validado pelo teste legado existente. ✅
- Transparente pro app Android (sem mudança de contrato) → nenhuma rota/resposta consumida pelo app muda; só enroll passa a gravar expiry e sync renova. ✅
- Migration verificada na integração + bump v1.15.0 → Tasks 1, 5, 7. ✅
- Gate de suíte completa antes de prod → Task 7. ✅

**2. Placeholders:** nenhum "TBD/TODO"; todo step tem código/comando. As notas ("se o wpdb-fake do PurgerTest não cobrir...") são instruções reais de integração.

**3. Consistência de tipos/assinaturas:** `expiryFromNow(): string` / `revokeSession(int): bool` / `touchSync` usados igual entre repo, controller e testes; `revoke()`/`revokeArgs()` no controller batem com a rota; `session_expires_at` (coluna) idêntico em migration/repo/controller/testes; `rateLimited(string,int): ?WP_Error` consistente nas 3 chamadas; `revokeCompanion(childId)` idêntico entre `companion.ts`, componente e teste.
