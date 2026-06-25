# Auto-logout por inatividade — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Auto-logout por inatividade no painel-pais: ocioso pelo tempo configurado → aviso com contagem → redireciona pro logout do WordPress.

**Architecture:** Quase 100% frontend. Hook `useIdleTimeout` (timers + atividade + sync entre abas) → `IdleWarningDialog` → `AutoLogoutGuard` (lê settings, dispara o logout). Config reusa o settings bag (`/settings`). Backend só expõe `logoutUrl` no `window.guardkidsApi` (que também conserta o "Sair" morto do SideNav). Sem migração.

**Tech Stack:** React + TS + TanStack Query + Vitest (app-parent); PHP 8.2 (1 linha no ParentApp).

**Spec:** `docs/superpowers/specs/2026-06-25-auto-logout-design.md`
**Branch:** `feat/auto-logout` (já criada; spec já commitado nela)

## Ambiente de teste
Front (app-parent): `cd public/app-parent && pnpm vitest run`. Build: `pnpm build`.
PHP (gate final): PHP 8.2 do LocalWP —
```bash
PHP82="/c/Users/mysho/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe"
EXTDIR="C:/Users/mysho/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/ext"
"$PHP82" -d extension_dir="$EXTDIR" -d extension=mbstring -d extension=sodium vendor/bin/phpunit --testsuite unit
```

## Estrutura de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `includes/Ui/ParentApp.php` (modificar) | injeta `logoutUrl` no `window.guardkidsApi` |
| `public/app-parent/src/vite-env.d.ts` (modificar) | adiciona `logoutUrl?: string` ao tipo |
| `public/app-parent/src/hooks/useIdleTimeout.ts` (criar) | timers de inatividade + atividade + sync entre abas |
| `public/app-parent/src/hooks/useIdleTimeout.test.ts` (criar) | testes com fake timers |
| `public/app-parent/src/components/IdleWarningDialog.tsx` (criar) | modal de aviso com contagem |
| `public/app-parent/src/components/IdleWarningDialog.test.tsx` (criar) | |
| `public/app-parent/src/components/AutoLogoutGuard.tsx` (criar) | lê settings, usa hook+dialog, redireciona |
| `public/app-parent/src/components/AutoLogoutGuard.test.tsx` (criar) | |
| `public/app-parent/src/App.tsx` (modificar) | monta `<AutoLogoutGuard />` |
| `public/app-parent/src/pages/Settings.tsx` (modificar) | toggle real + select de presets |
| `public/app-parent/src/pages/Settings.test.tsx` (modificar) | ajustar p/ o toggle real |
| `public/app-parent/src/components/SideNav.tsx` (modificar) | "Sair" usa `logoutUrl` |
| `guardkids.php` (modificar) | bump v1.13.0 |

---

### Task 1: Backend — `logoutUrl` no guardkidsApi + tipo

**Files:**
- Modify: `includes/Ui/ParentApp.php` (~linha 118, o bloco `window.guardkidsApi`)
- Modify: `public/app-parent/src/vite-env.d.ts`

- [ ] **Step 1: Injetar `logoutUrl` no ParentApp**

Em `includes/Ui/ParentApp.php`, localize:
```php
        echo '  <script>window.guardkidsApi = ' . wp_json_encode([
            'nonce' => $nonce,
            'root'  => $root,
        ]) . ';</script>' . "\n";
```
Substitua por (adicionando a chave `logoutUrl`):
```php
        $logoutUrl = wp_logout_url(home_url('/painel-pais'));
        echo '  <script>window.guardkidsApi = ' . wp_json_encode([
            'nonce'     => $nonce,
            'root'      => $root,
            'logoutUrl' => $logoutUrl,
        ]) . ';</script>' . "\n";
```

- [ ] **Step 2: Adicionar `logoutUrl` ao tipo**

Em `public/app-parent/src/vite-env.d.ts`, dentro de `interface Window { guardkidsApi?: { ... } }`:
```ts
interface Window {
  guardkidsApi?: {
    nonce: string;
    root: string;
    logoutUrl?: string;
  };
}
```

- [ ] **Step 3: Build do front pra garantir tsc limpo**

Run: `cd public/app-parent && pnpm build`
Expected: build OK (tsc clean).

- [ ] **Step 4: Commit**

```bash
git add includes/Ui/ParentApp.php public/app-parent/src/vite-env.d.ts
git commit -m "feat(auto-logout): expõe logoutUrl no guardkidsApi"
```

---

### Task 2: Hook `useIdleTimeout`

**Files:**
- Create: `public/app-parent/src/hooks/useIdleTimeout.ts`
- Test: `public/app-parent/src/hooks/useIdleTimeout.test.ts`

- [ ] **Step 1: Escrever o teste que falha**

```ts
import { renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useIdleTimeout } from './useIdleTimeout';

describe('useIdleTimeout', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => {
    vi.runOnlyPendingTimers();
    vi.useRealTimers();
    localStorage.clear();
  });

  it('dispara onWarn antes do timeout e onTimeout no fim', () => {
    const onWarn = vi.fn();
    const onTimeout = vi.fn();
    renderHook(() =>
      useIdleTimeout({ enabled: true, minutes: 1, warningSeconds: 30, onWarn, onTimeout }),
    );

    vi.advanceTimersByTime(29_000);
    expect(onWarn).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1_000); // 30s → warn (60-30)
    expect(onWarn).toHaveBeenCalledTimes(1);
    expect(onTimeout).not.toHaveBeenCalled();
    vi.advanceTimersByTime(30_000); // 60s → timeout
    expect(onTimeout).toHaveBeenCalledTimes(1);
  });

  it('reset() reinicia a contagem', () => {
    const onWarn = vi.fn();
    const onTimeout = vi.fn();
    const { result } = renderHook(() =>
      useIdleTimeout({ enabled: true, minutes: 1, warningSeconds: 30, onWarn, onTimeout }),
    );

    vi.advanceTimersByTime(50_000);
    result.current.reset();
    vi.advanceTimersByTime(29_000);
    expect(onWarn).not.toHaveBeenCalled(); // recomeçou em 50s
    vi.advanceTimersByTime(1_000);
    expect(onWarn).toHaveBeenCalledTimes(1);
  });

  it('não arma nada quando enabled=false', () => {
    const onWarn = vi.fn();
    const onTimeout = vi.fn();
    renderHook(() =>
      useIdleTimeout({ enabled: false, minutes: 1, warningSeconds: 30, onWarn, onTimeout }),
    );
    vi.advanceTimersByTime(120_000);
    expect(onWarn).not.toHaveBeenCalled();
    expect(onTimeout).not.toHaveBeenCalled();
  });

  it('atividade do usuário (evento) reseta os timers', () => {
    const onWarn = vi.fn();
    const onTimeout = vi.fn();
    renderHook(() =>
      useIdleTimeout({ enabled: true, minutes: 1, warningSeconds: 30, onWarn, onTimeout }),
    );
    vi.advanceTimersByTime(40_000);
    window.dispatchEvent(new Event('keydown'));
    vi.advanceTimersByTime(29_000);
    expect(onWarn).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1_000);
    expect(onWarn).toHaveBeenCalledTimes(1);
  });
});
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `cd public/app-parent && pnpm vitest run useIdleTimeout`
Expected: FAIL (módulo `./useIdleTimeout` não existe).

- [ ] **Step 3: Implementar `useIdleTimeout.ts`**

```ts
import { useCallback, useEffect, useRef } from 'react';

const ACTIVITY_EVENTS = ['mousemove', 'keydown', 'touchstart', 'scroll', 'click'] as const;
const STORAGE_KEY = 'gk_last_activity';
const THROTTLE_MS = 1_000;

type Options = {
  enabled: boolean;
  minutes: number;
  warningSeconds?: number;
  onWarn: () => void;
  onTimeout: () => void;
  /** Chamado quando há atividade enquanto o aviso está visível (pra dispensá-lo). */
  onActivityWhileWarned?: () => void;
};

/**
 * Auto-logout por inatividade. Arma timers de aviso + timeout, escuta atividade
 * (throttled) e sincroniza o "último uso" entre abas via localStorage. Tudo
 * client-side; ver caveat no spec.
 */
export function useIdleTimeout({
  enabled,
  minutes,
  warningSeconds = 30,
  onWarn,
  onTimeout,
  onActivityWhileWarned,
}: Options): { reset: () => void } {
  const warnTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const logoutTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const warnedRef = useRef(false);
  const lastWriteRef = useRef(0);

  // Refs pros callbacks pra não re-armar o efeito a cada render.
  const cbs = useRef({ onWarn, onTimeout, onActivityWhileWarned });
  cbs.current = { onWarn, onTimeout, onActivityWhileWarned };

  const clearTimers = useCallback(() => {
    if (warnTimer.current) clearTimeout(warnTimer.current);
    if (logoutTimer.current) clearTimeout(logoutTimer.current);
    warnTimer.current = null;
    logoutTimer.current = null;
  }, []);

  const schedule = useCallback(() => {
    clearTimers();
    warnedRef.current = false;
    if (!enabled) return;
    const totalMs = minutes * 60 * 1000;
    const warnMs = Math.max(0, totalMs - warningSeconds * 1000);
    warnTimer.current = setTimeout(() => {
      warnedRef.current = true;
      cbs.current.onWarn();
    }, warnMs);
    logoutTimer.current = setTimeout(() => {
      cbs.current.onTimeout();
    }, totalMs);
  }, [enabled, minutes, warningSeconds, clearTimers]);

  const reset = useCallback(() => {
    if (warnedRef.current) cbs.current.onActivityWhileWarned?.();
    schedule();
  }, [schedule]);

  useEffect(() => {
    if (!enabled) {
      clearTimers();
      return;
    }
    const onActivity = () => {
      const now = Date.now();
      if (now - lastWriteRef.current < THROTTLE_MS) return;
      lastWriteRef.current = now;
      try {
        localStorage.setItem(STORAGE_KEY, String(now));
      } catch {
        // localStorage indisponível — segue só com timers locais.
      }
      reset();
    };
    const onStorage = (e: StorageEvent) => {
      if (e.key === STORAGE_KEY) reset();
    };

    ACTIVITY_EVENTS.forEach((evt) => window.addEventListener(evt, onActivity, { passive: true }));
    window.addEventListener('storage', onStorage);
    schedule();

    return () => {
      ACTIVITY_EVENTS.forEach((evt) => window.removeEventListener(evt, onActivity));
      window.removeEventListener('storage', onStorage);
      clearTimers();
    };
  }, [enabled, minutes, warningSeconds, schedule, reset, clearTimers]);

  return { reset };
}
```

- [ ] **Step 4: Rodar e verificar que passa (4 testes)**

Run: `cd public/app-parent && pnpm vitest run useIdleTimeout`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add public/app-parent/src/hooks/useIdleTimeout.ts public/app-parent/src/hooks/useIdleTimeout.test.ts
git commit -m "feat(auto-logout): hook useIdleTimeout (timers + atividade + sync entre abas)"
```

---

### Task 3: `IdleWarningDialog`

**Files:**
- Create: `public/app-parent/src/components/IdleWarningDialog.tsx`
- Test: `public/app-parent/src/components/IdleWarningDialog.test.tsx`

- [ ] **Step 1: Escrever o teste que falha**

```tsx
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { IdleWarningDialog } from './IdleWarningDialog';

describe('IdleWarningDialog', () => {
  it('mostra a contagem e os botões', () => {
    render(<IdleWarningDialog secondsLeft={30} onStay={() => {}} onLogout={() => {}} />);
    expect(screen.getByText(/30s/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /continuar logado/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /sair agora/i })).toBeInTheDocument();
  });

  it('chama onStay e onLogout', async () => {
    const onStay = vi.fn();
    const onLogout = vi.fn();
    const user = userEvent.setup();
    render(<IdleWarningDialog secondsLeft={10} onStay={onStay} onLogout={onLogout} />);
    await user.click(screen.getByRole('button', { name: /continuar logado/i }));
    await user.click(screen.getByRole('button', { name: /sair agora/i }));
    expect(onStay).toHaveBeenCalledTimes(1);
    expect(onLogout).toHaveBeenCalledTimes(1);
  });
});
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `cd public/app-parent && pnpm vitest run IdleWarningDialog`
Expected: FAIL (módulo não existe).

- [ ] **Step 3: Implementar `IdleWarningDialog.tsx`**

Reuse as classes/estilo de um dialog existente (ex.: `PinDialog.tsx`/`DeleteAccountDialog.tsx`) — overlay + card Material-3. Markup mínimo:

```tsx
type Props = {
  secondsLeft: number;
  onStay: () => void;
  onLogout: () => void;
};

export function IdleWarningDialog({ secondsLeft, onStay, onLogout }: Props) {
  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
      role="alertdialog"
      aria-modal="true"
      aria-label="Aviso de inatividade"
    >
      <div className="w-full max-w-sm rounded-xl bg-surface-container-low p-6 shadow-xl">
        <h2 className="font-display text-headline-sm text-on-surface">Você ainda está aí?</h2>
        <p className="mt-2 text-body-md text-on-surface-variant">
          Por segurança, vamos desconectar em <strong>{secondsLeft}s</strong> por inatividade.
        </p>
        <div className="mt-5 flex flex-wrap justify-end gap-2">
          <button
            type="button"
            onClick={onLogout}
            className="rounded-lg border border-outline-variant px-4 py-2 text-label-lg text-on-surface-variant hover:bg-surface-container"
          >
            Sair agora
          </button>
          <button
            type="button"
            onClick={onStay}
            className="rounded-lg bg-primary px-4 py-2 text-label-lg text-white hover:bg-primary-container"
          >
            Continuar logado
          </button>
        </div>
      </div>
    </div>
  );
}
```

> Ajuste as classes utilitárias pras realmente usadas no app (confira `PinDialog.tsx`/`DeleteAccountDialog.tsx`).

- [ ] **Step 4: Rodar e verificar que passa (2 testes)**

Run: `cd public/app-parent && pnpm vitest run IdleWarningDialog`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add public/app-parent/src/components/IdleWarningDialog.tsx public/app-parent/src/components/IdleWarningDialog.test.tsx
git commit -m "feat(auto-logout): IdleWarningDialog (aviso com contagem)"
```

---

### Task 4: `AutoLogoutGuard` + montar no App

**Files:**
- Create: `public/app-parent/src/components/AutoLogoutGuard.tsx`
- Test: `public/app-parent/src/components/AutoLogoutGuard.test.tsx`
- Modify: `public/app-parent/src/App.tsx`

- [ ] **Step 1: Escrever o teste que falha**

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AutoLogoutGuard } from './AutoLogoutGuard';
import * as settingsApi from '../api/settings';

function wrap(ui: ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

describe('AutoLogoutGuard', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    (window as { guardkidsApi?: unknown }).guardkidsApi = {
      nonce: 'x',
      root: '/',
      logoutUrl: 'https://site.test/logout',
    };
  });
  afterEach(() => vi.useRealTimers());

  it('não faz nada quando auto_logout está desligado', async () => {
    vi.spyOn(settingsApi, 'listSettings').mockResolvedValue({ 'security.auto_logout': false });
    wrap(<AutoLogoutGuard />);
    // dá tempo de resolver a query; o dialog nunca aparece
    await waitFor(() => expect(settingsApi.listSettings).toHaveBeenCalled());
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
  });

  it('mostra o aviso quando ocioso e some ao "Continuar logado"', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.spyOn(settingsApi, 'listSettings').mockResolvedValue({
      'security.auto_logout': true,
      'security.auto_logout_minutes': 1,
    });
    const user = userEvent.setup();
    wrap(<AutoLogoutGuard />);
    await vi.waitFor(() => expect(settingsApi.listSettings).toHaveBeenCalled());

    vi.advanceTimersByTime(30_000); // warn em 60-30
    expect(await screen.findByRole('alertdialog')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: /continuar logado/i }));
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `cd public/app-parent && pnpm vitest run AutoLogoutGuard`
Expected: FAIL (módulo não existe).

- [ ] **Step 3: Implementar `AutoLogoutGuard.tsx`**

```tsx
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { listSettings } from '../api/settings';
import { useIdleTimeout } from '../hooks/useIdleTimeout';
import { IdleWarningDialog } from './IdleWarningDialog';

const WARNING_SECONDS = 30;
const DEFAULT_MINUTES = 15;

export function AutoLogoutGuard() {
  const { data } = useQuery({ queryKey: ['settings'], queryFn: listSettings });
  const enabled = data?.['security.auto_logout'] === true;
  const minutes = Number(data?.['security.auto_logout_minutes']) || DEFAULT_MINUTES;

  const [warning, setWarning] = useState(false);

  const doLogout = () => {
    const url = window.guardkidsApi?.logoutUrl ?? '/wp-login.php?action=logout';
    window.location.assign(url);
  };

  const { reset } = useIdleTimeout({
    enabled,
    minutes,
    warningSeconds: WARNING_SECONDS,
    onWarn: () => setWarning(true),
    onTimeout: doLogout,
    onActivityWhileWarned: () => setWarning(false),
  });

  if (!warning) return null;

  return (
    <IdleWarningDialog
      secondsLeft={WARNING_SECONDS}
      onStay={() => {
        setWarning(false);
        reset();
      }}
      onLogout={doLogout}
    />
  );
}
```

- [ ] **Step 4: Montar no `App.tsx`**

Importar e renderizar dentro do container raiz (junto aos navs):
```tsx
import { AutoLogoutGuard } from './components/AutoLogoutGuard';
// ...
  return (
    <div className="flex min-h-screen flex-col bg-background text-on-background md:flex-row">
      <AutoLogoutGuard />
      <TopNav />
      <SideNav activePage={activePage} onNavigate={setActivePage} />
      <PageRenderer page={activePage} />
      <BottomNav activePage={activePage} onNavigate={setActivePage} />
    </div>
  );
```

- [ ] **Step 5: Rodar e verificar que passa (2 testes)**

Run: `cd public/app-parent && pnpm vitest run AutoLogoutGuard`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add public/app-parent/src/components/AutoLogoutGuard.tsx public/app-parent/src/components/AutoLogoutGuard.test.tsx public/app-parent/src/App.tsx
git commit -m "feat(auto-logout): AutoLogoutGuard montado na raiz do painel"
```

---

### Task 5: Settings UI (toggle real + presets) + SideNav logout

**Files:**
- Modify: `public/app-parent/src/pages/Settings.tsx`
- Modify: `public/app-parent/src/pages/Settings.test.tsx`
- Modify: `public/app-parent/src/components/SideNav.tsx`

- [ ] **Step 1: Trocar o toggle travado por toggle real + select**

Em `Settings.tsx`, dentro da seção Segurança, encontre o `SettingToggleRow` de `security.auto_logout` (atualmente `locked`). Remova o `locked` e, logo abaixo, adicione o select de minutos quando ligado. O `get`/`set`/`mutation`/`bag` já existem no componente (linhas ~89-94). Substitua o bloco do toggle por:

```tsx
        <SettingToggleRow
          settingsKey="security.auto_logout"
          title="Logout automático por inatividade"
          description="Desconecta o painel após um tempo parado, por segurança."
          fallback={false}
          loading={settingsQuery.isLoading}
          saving={mutation.isPending}
          get={get}
          set={set}
        />
        {get('security.auto_logout', false) ? (
          <div className="flex items-center justify-between gap-4 rounded-xl border border-outline-variant bg-surface-container-low p-4">
            <label htmlFor="auto-logout-minutes" className="text-body-md text-on-surface">
              Tempo de inatividade
            </label>
            <select
              id="auto-logout-minutes"
              className="rounded-lg border border-outline-variant bg-surface px-3 py-2 text-body-md text-on-surface"
              value={Number(bag['security.auto_logout_minutes']) || 15}
              disabled={mutation.isPending}
              onChange={(e) =>
                mutation.mutate({ 'security.auto_logout_minutes': Number(e.target.value) })
              }
            >
              <option value={5}>5 minutos</option>
              <option value={15}>15 minutos</option>
              <option value={30}>30 minutos</option>
              <option value={60}>60 minutos</option>
            </select>
          </div>
        ) : null}
```

> Ajuste as classes do `<select>`/wrapper pras já usadas no app se houver um componente de select padrão.

- [ ] **Step 2: Consertar o "Sair" do SideNav**

Em `public/app-parent/src/components/SideNav.tsx`, o link de logout hoje é `href="#logout"`. Troque por um handler que usa a `logoutUrl`:

```tsx
          <a
            href={window.guardkidsApi?.logoutUrl ?? '/wp-login.php?action=logout'}
            className="flex items-center gap-3 py-2 text-on-surface-variant transition-colors hover:text-on-surface"
          >
            <Icon name="logout" className="text-lg" />
            <span className="text-label-sm">Sair</span>
          </a>
```

- [ ] **Step 3: Atualizar `Settings.test.tsx`**

O teste hoje referencia o toggle por "Logout automático em 7 dias" (título antigo) — alguns testes usam `security.auto_logout` como fixture (ver os testes "uses fallback values" e "overrides fallback with server value"). Atualize o **título** nessas asserções de `'Logout automático em 7 dias'` para `'Logout automático por inatividade'` (o `settingsKey` continua `security.auto_logout`, então os fixtures por chave não mudam). Não altere a intenção dos testes.

- [ ] **Step 4: Rodar a suíte vitest inteira**

Run: `cd public/app-parent && pnpm vitest run`
Expected: PASS (todos). Se algum teste do Settings quebrar por causa do título, ajuste só o texto esperado.

- [ ] **Step 5: Build**

Run: `cd public/app-parent && pnpm build`
Expected: build OK (tsc clean).

- [ ] **Step 6: Commit**

```bash
git add public/app-parent/src/pages/Settings.tsx public/app-parent/src/pages/Settings.test.tsx public/app-parent/src/components/SideNav.tsx
git commit -m "feat(auto-logout): toggle real + presets no Settings e conserta Sair do SideNav"
```

---

### Task 6: Bump v1.13.0

**Files:**
- Modify: `guardkids.php:5` e `guardkids.php:21`

- [ ] **Step 1: Bump**

Header: `* Version:           1.13.0` · Constante: `define('GUARDKIDS_VERSION', '1.13.0');`. `GUARDKIDS_DB_VERSION` permanece 10.

- [ ] **Step 2: Commit**

```bash
git add guardkids.php
git commit -m "chore(release): bump v1.13.0 (auto-logout)"
```

---

### Task 7: Gate de testes completos + PR + deploy

> Memória `feedback-full-tests-before-prod`: suíte completa verde antes de prod.

- [ ] **Step 1: vitest app-parent** — `cd public/app-parent && pnpm vitest run` → PASS (todos, incl. os novos).
- [ ] **Step 2: vitest app-child** — `cd public/app-child && pnpm vitest run` → PASS (garante que nada quebrou).
- [ ] **Step 3: PHP unit** — comando do topo (`--testsuite unit`) → OK (331; nada de PHP mudou além do ParentApp render).
- [ ] **Step 4: Smoke manual** no LocalWP: ligar auto-logout (5 min) → deixar ocioso → aviso aparece ~4m30s → "Continuar logado" reseta → deixar zerar → redireciona pro login → conferir que o "Sair" do menu agora desloga.
- [ ] **Step 5: Push + PR**
```bash
git push -u origin feat/auto-logout
gh pr create --base master --head feat/auto-logout \
  --title "feat(security): auto-logout por inatividade no painel-pais (v1.13.0)" \
  --body "Implementa docs/superpowers/specs/2026-06-25-auto-logout-design.md. Timeout por inatividade (client-side), presets 5/15/30/60, aviso com contagem, logout via logoutUrl (conserta o Sair do SideNav). Sem migração. Suítes verdes."
```
- [ ] **Step 6: CI verde** — `gh pr checks <n> --watch`. Só seguir pro deploy com 100% verde.
- [ ] **Step 7: Build zip + deploy + tag/release** — `pnpm build` (app-parent) → `scripts/build-release-zip.php` (guardkids-wp-1.13.0.zip) → merge → tag v1.13.0 → release com zip → deploy via `tools/deploy/README.md` (sem migração) → smoke em prod.

---

## Self-Review

**1. Cobertura do spec:**
- logoutUrl no guardkidsApi + conserta Sair → Tasks 1, 5. ✅
- Config via settings bag (auto_logout + auto_logout_minutes) → Tasks 4 (leitura), 5 (escrita). ✅
- `useIdleTimeout` (timers, atividade, sync entre abas, enabled) → Task 2. ✅
- `IdleWarningDialog` (contagem + botões) → Task 3. ✅
- `AutoLogoutGuard` (lê settings, hook, dialog, redirect) + montado na raiz → Task 4. ✅
- Presets 5/15/30/60 default 15 → Tasks 4 (default), 5 (opções). ✅
- Caveat client-side → documentado no spec; nada server-side implementado. ✅
- Só painel-pais → mudanças só em app-parent + ParentApp. ✅
- Sem migração / bump v1.13.0 → Task 6. ✅
- Gate de suíte completa antes de prod → Task 7. ✅
- Testes (hook fake timers, dialog, guard) → Tasks 2-4. ✅

**2. Placeholders:** nenhum "TBD/TODO"; todo step tem código/comando. As notas de "ajustar classes utilitárias" são instruções de integração (reusar estilos existentes), não placeholders de lógica.

**3. Consistência de tipos/assinaturas:** `useIdleTimeout({enabled,minutes,warningSeconds,onWarn,onTimeout,onActivityWhileWarned}) → {reset}` usado igual no `AutoLogoutGuard`; `IdleWarningDialog({secondsLeft,onStay,onLogout})` consistente entre teste e uso; `window.guardkidsApi.logoutUrl` tipado na Task 1 e consumido nas Tasks 4/5; chaves `security.auto_logout`/`security.auto_logout_minutes` idênticas entre leitura (Guard) e escrita (Settings).
