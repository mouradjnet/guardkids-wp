# Onboarding de Primeiro Acesso — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Um checklist não-bloqueante no topo do Dashboard do app-parent que guia o pai a criar o primeiro filho e parear um dispositivo, sumindo sozinho ao completar.

**Architecture:** Backend expõe `paired: boolean` por filho (existe token de pareamento ⟺ pareado). Frontend adiciona um componente `OnboardingChecklist` que lê a query `['children']` já existente, deriva 2 passos e reusa `AddChildDialog`/`PairDeviceDialog`. Tudo derivado do estado real — sem flag de dispensa.

**Tech Stack:** PHP 8.2 (PHPUnit unit suite), React 19 + TypeScript + Vitest + TanStack Query.

**Spec:** `docs/superpowers/specs/2026-07-18-onboarding-primeiro-acesso-design.md`

**Comandos de teste:**
- PHP: `"/c/Users/mysho/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe" -d extension_dir="/c/Users/mysho/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/ext" -d extension=mbstring -d extension=openssl -d extension=sodium -d extension=zip -d extension=fileinfo vendor/bin/phpunit --testsuite unit`
- Frontend: `cd public/app-parent && rtk proxy pnpm exec vitest run <arquivo>` (o hook do RTK mastiga o output do vitest; `rtk proxy` contorna)
- tsc: `cd public/app-parent && pnpm exec tsc -b`

---

## File Structure

- **Modify** `database/SettingsRepository.php` — novo `valuesByPrefix()`.
- **Modify** `includes/Auth/ChildAuth.php` — novo `pairedChildIds()`.
- **Modify** `api/Controllers/ChildController.php` — `toJson()` ganha 2º arg `$pairedIds` + campo `paired`; `index()`/`show()`/`create()`/`update()`/`patch` resolvem os ids.
- **Modify** `public/app-parent/src/api/types.ts` — `Child.paired: boolean`.
- **Create** `public/app-parent/src/components/OnboardingChecklist.tsx` — o componente.
- **Create** `public/app-parent/src/components/OnboardingChecklist.test.tsx` — testes do componente.
- **Modify** `public/app-parent/src/pages/Dashboard.tsx` — monta o checklist no topo.
- **Modify** `public/app-parent/src/pages/Dashboard.test.tsx` — asserta a montagem.
- **Test** `tests/Unit/Database/SettingsRepositoryTest.php`, `tests/Unit/Auth/ChildAuthTest.php`, `tests/Unit/Api/ChildControllerTest.php`.

---

## Task 1: Backend — `SettingsRepository::valuesByPrefix`

**Files:**
- Modify: `database/SettingsRepository.php` (adicionar método após `all()`)
- Test: `tests/Unit/Database/SettingsRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

Adicionar ao final da classe em `tests/Unit/Database/SettingsRepositoryTest.php` (dentro do `class`, e o stub anônimo do `setUp` precisa honrar o LIKE — ver Step 3 do stub). Primeiro, no stub anônimo do `setUp()`, substituir o método `get_results` existente por este (que filtra por prefixo lido do SQL) e adicionar `esc_like`:

```php
public function esc_like($text)
{
    return $text;
}

public function get_results($sql, $output = OBJECT)
{
    $this->log[] = ['method' => 'get_results', 'args' => [$sql]];
    $out = [];
    // valuesByPrefix: SELECT setting_key, value FROM ... WHERE setting_key LIKE 'PREFIX%'
    if (preg_match("/setting_key LIKE '([^%']+)%'/", (string) $sql, $m) === 1) {
        foreach ($this->rows as $key => $value) {
            if (str_starts_with($key, $m[1])) {
                $out[] = ['setting_key' => $key, 'value' => $value];
            }
        }
        return $out;
    }
    foreach ($this->rows as $key => $value) {
        $out[] = ['setting_key' => $key, 'value' => $value];
    }
    return $out;
}
```

Depois adicionar o teste:

```php
public function test_valuesByPrefix_filters_and_decodes(): void
{
    $repo = new SettingsRepository();
    $repo->set('child_token:aaa', ['childId' => 1]);
    $repo->set('child_token:bbb', ['childId' => 2]);
    $repo->set('upgrade_url', 'https://x');

    $out = $repo->valuesByPrefix('child_token:');

    $this->assertArrayHasKey('child_token:aaa', $out);
    $this->assertArrayHasKey('child_token:bbb', $out);
    $this->assertArrayNotHasKey('upgrade_url', $out);
    $this->assertSame(['childId' => 1], $out['child_token:aaa']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run (comando PHP do header) com `--filter test_valuesByPrefix_filters_and_decodes`.
Expected: FAIL — `Call to undefined method GuardKids\Database\SettingsRepository::valuesByPrefix()`.

- [ ] **Step 3: Write minimal implementation**

Em `database/SettingsRepository.php`, adicionar após o método `all()`:

```php
    /**
     * Devolve as settings cujo `setting_key` começa com `$prefix`, já
     * desserializadas. Evita carregar todas as settings via all().
     *
     * @return array<string, mixed> [setting_key => value_desserializado]
     */
    public function valuesByPrefix(string $prefix): array
    {
        $like = $this->db->esc_like($prefix) . '%';
        $sql  = $this->db->prepare(
            'SELECT setting_key, value FROM ' . $this->table() . ' WHERE setting_key LIKE %s',
            $like,
        );
        $rows = $this->db->get_results($sql, ARRAY_A);
        if (! is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) ($row['value'] ?? ''), true);
            $out[(string) $row['setting_key']] = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }
        return $out;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run com `--filter test_valuesByPrefix_filters_and_decodes`.
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/SettingsRepository.php tests/Unit/Database/SettingsRepositoryTest.php
git commit -m "feat(settings): valuesByPrefix pra ler settings por prefixo de chave"
```

---

## Task 2: Backend — `ChildAuth::pairedChildIds`

**Files:**
- Modify: `includes/Auth/ChildAuth.php` (adicionar método público)
- Test: `tests/Unit/Auth/ChildAuthTest.php`

- [ ] **Step 1: Write the failing test**

No stub anônimo do `setUp()` de `tests/Unit/Auth/ChildAuthTest.php`, substituir `get_results` (hoje `return [];`) por um que honra o prefixo, e adicionar `esc_like`:

```php
public function esc_like($text)
{
    return $text;
}

public function get_results($sql, $output = OBJECT)
{
    $out = [];
    if (preg_match("/setting_key LIKE '([^%']+)%'/", (string) $sql, $m) === 1) {
        foreach ($this->store as $key => $value) {
            if (str_starts_with($key, $m[1])) {
                $out[] = ['setting_key' => $key, 'value' => $value];
            }
        }
    }
    return $out;
}
```

Depois adicionar o teste:

```php
public function test_pairedChildIds_returns_distinct_ids_from_tokens(): void
{
    $auth = new ChildAuth();
    $auth->issueToken(1, 'tablet');
    $auth->issueToken(1, 'celular'); // mesmo filho, 2 tokens
    $auth->issueToken(2, null);

    $ids = $auth->pairedChildIds();
    sort($ids);

    $this->assertSame([1, 2], $ids);
}

public function test_pairedChildIds_empty_without_tokens(): void
{
    $this->assertSame([], (new ChildAuth())->pairedChildIds());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run com `--filter pairedChildIds`.
Expected: FAIL — `Call to undefined method GuardKids\Auth\ChildAuth::pairedChildIds()`.

- [ ] **Step 3: Write minimal implementation**

Em `includes/Auth/ChildAuth.php`, adicionar como método público (após `issueToken`):

```php
    /**
     * IDs distintos dos filhos que têm ao menos um token de pareamento.
     * Fonte da verdade de "pareado" (existe token ⟺ pareado).
     *
     * @return list<int>
     */
    public function pairedChildIds(): array
    {
        $ids = [];
        foreach ($this->settings->valuesByPrefix(self::KEY_PREFIX) as $payload) {
            if (is_array($payload) && isset($payload['childId'])) {
                $ids[(int) $payload['childId']] = true;
            }
        }
        return array_keys($ids);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run com `--filter pairedChildIds`.
Expected: PASS (2 testes).

- [ ] **Step 5: Commit**

```bash
git add includes/Auth/ChildAuth.php tests/Unit/Auth/ChildAuthTest.php
git commit -m "feat(auth): ChildAuth::pairedChildIds — filhos com token de pareamento"
```

---

## Task 3: Backend — `ChildController` serializa `paired`

**Files:**
- Modify: `api/Controllers/ChildController.php` (toJson + call sites)
- Test: `tests/Unit/Api/ChildControllerTest.php`

- [ ] **Step 1: Write the failing test**

No stub anônimo do `setUp()` de `tests/Unit/Api/ChildControllerTest.php`, o `get_results` atual (`return array_values($this->rows);`) precisa distinguir a query de tokens da de filhos. Substituir por:

```php
public function esc_like($text)
{
    return $text;
}

public function get_results($sql, $output = OBJECT)
{
    $this->log[] = ['method' => 'get_results', 'args' => [$sql]];
    // valuesByPrefix dos tokens: WHERE setting_key LIKE 'child_token:%'
    if (str_contains((string) $sql, 'child_token:')) {
        $out = [];
        foreach ($this->tokenRows as $key => $value) {
            $out[] = ['setting_key' => $key, 'value' => $value];
        }
        return $out;
    }
    return array_values($this->rows);
}
```

E adicionar a propriedade `public array $tokenRows = [];` junto das outras props do stub.

Depois adicionar o teste:

```php
public function test_index_includes_paired_flag_per_child(): void
{
    $this->wpdb->rows = [
        1 => ['id' => 1, 'slug' => 'lucas', 'name' => 'Lucas', 'status' => 'offline'],
        2 => ['id' => 2, 'slug' => 'ana', 'name' => 'Ana', 'status' => 'offline'],
    ];
    // filho 1 tem token (pareado), filho 2 não
    $this->wpdb->tokenRows = [
        'child_token:hash1' => json_encode(['childId' => 1]),
    ];

    $data = (new ChildController())->index()->get_data();
    $byId = [];
    foreach ($data as $c) {
        $byId[$c['id']] = $c;
    }

    $this->assertTrue($byId[1]['paired']);
    $this->assertFalse($byId[2]['paired']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run com `--filter test_index_includes_paired_flag_per_child`.
Expected: FAIL — `Undefined array key "paired"` (o toJson ainda não inclui o campo).

- [ ] **Step 3: Write minimal implementation**

Em `api/Controllers/ChildController.php`:

(a) Adicionar `use GuardKids\Auth\ChildAuth;` no topo (junto dos outros `use`).

(b) Adicionar método privado (perto de `toJson`):

```php
    /** @return list<int> */
    private function pairedIds(): array
    {
        return (new ChildAuth())->pairedChildIds();
    }
```

(c) Trocar a assinatura de `toJson` pra receber os ids e incluir o campo `paired`:

```php
    /**
     * @param array<string, mixed> $row
     * @param list<int> $pairedIds
     * @return array<string, mixed>
     */
    private function toJson(array $row, array $pairedIds = []): array
    {
        return [
            'id'           => (int) ($row['id'] ?? 0),
            // ... (manter todos os campos existentes iguais) ...
            'paired'       => in_array((int) ($row['id'] ?? 0), $pairedIds, true),
            'createdAt'    => $row['created_at'] ?? null,
            'updatedAt'    => $row['updated_at'] ?? null,
        ];
    }
```

> Inserir a linha `'paired' => ...` no array de retorno do `toJson` (ex.: logo antes de `'createdAt'`). Manter TODOS os outros campos inalterados.

(d) Atualizar os 5 call sites do `toJson`:

- `index()` (L70-72) — resolver uma vez:
```php
    public function index(): WP_REST_Response
    {
        $pairedIds = $this->pairedIds();
        return rest_ensure_response(
            array_map(fn (array $r): array => $this->toJson($r, $pairedIds), $this->repo->findAll('name')),
        );
    }
```
- `show()` (~L81): `return rest_ensure_response($this->toJson($row, $this->pairedIds()));`
- `create()` (~L128): `return new WP_REST_Response($this->toJson($created ?? [], $this->pairedIds()), 201);`
- `update()` (~L200): `return rest_ensure_response($this->toJson($this->repo->findById($id) ?? [], $this->pairedIds()));`
- `setStatus`/patch (~L255): `return rest_ensure_response($this->toJson($this->repo->findById($id) ?? [], $this->pairedIds()));`

- [ ] **Step 4: Run test to verify it passes**

Run com `--filter test_index_includes_paired_flag_per_child`.
Expected: PASS.

- [ ] **Step 5: Run the whole PHP unit suite (nenhuma regressão)**

Run o comando PHP completo do header (`--testsuite unit`).
Expected: `OK (598 tests, ...)` (594 baseline + 1 da Task 1 + 2 da Task 2 + 1 desta task) — os testes existentes que asseram o shape do filho continuam passando (só ganharam a chave `paired`; nenhum deles usa `assertSame` no array inteiro do filho — se algum usar, adicionar `'paired' => false` no esperado dele).

- [ ] **Step 6: Commit**

```bash
git add api/Controllers/ChildController.php tests/Unit/Api/ChildControllerTest.php
git commit -m "feat(children): serializa 'paired' por filho (existe token de pareamento)"
```

---

## Task 4: Frontend — tipo `Child.paired` + componente `OnboardingChecklist`

**Files:**
- Modify: `public/app-parent/src/api/types.ts` (adicionar `paired`)
- Create: `public/app-parent/src/components/OnboardingChecklist.tsx`
- Test: `public/app-parent/src/components/OnboardingChecklist.test.tsx`

- [ ] **Step 1: Adicionar o campo ao tipo**

Em `public/app-parent/src/api/types.ts`, na interface/type `Child`, adicionar após `device`:

```ts
  paired: boolean;
```

- [ ] **Step 2: Write the failing test**

Create `public/app-parent/src/components/OnboardingChecklist.test.tsx`:

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Child } from '../api/types';

const { listChildrenMock } = vi.hoisted(() => ({ listChildrenMock: vi.fn() }));
vi.mock('../api/children', () => ({ listChildren: listChildrenMock }));

// Diálogos viram markers pra não disparar API deles.
vi.mock('./AddChildDialog', () => ({
  AddChildDialog: ({ open }: { open: boolean }) =>
    open ? <div data-testid="add-child-dialog" /> : null,
}));
vi.mock('./PairDeviceDialog', () => ({
  PairDeviceDialog: ({ open, childName }: { open: boolean; childName: string }) =>
    open ? <div data-testid="pair-dialog">{childName}</div> : null,
}));

import { OnboardingChecklist } from './OnboardingChecklist';

const child = (over: Partial<Child>): Child => ({
  id: 1, slug: 'lucas', name: 'Lucas', age: 9, avatarUrl: null, device: null,
  paired: false, status: 'offline', usedMinutes: 0, limitMinutes: 60,
  dailyLimitEnabled: false, bedtimeEnabled: false, bedtimeStart: null, bedtimeEnd: null,
  allowedWeekdays: 'YYYYYYY', createdAt: null, updatedAt: null, ...over,
});

function renderWith(children: Child[]) {
  listChildrenMock.mockResolvedValue(children);
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children: c }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>{c}</QueryClientProvider>
  );
  return render(<OnboardingChecklist />, { wrapper });
}

describe('OnboardingChecklist', () => {
  beforeEach(() => listChildrenMock.mockReset());

  it('0 filhos: passo 1 com CTA, passo 2 travado', async () => {
    renderWith([]);
    expect(await screen.findByRole('button', { name: /adicionar filho/i })).toBeInTheDocument();
    expect(screen.getByText(/adicione um filho primeiro/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /parear dispositivo/i })).not.toBeInTheDocument();
  });

  it('1 filho não pareado: passo 1 feito, passo 2 com CTA', async () => {
    renderWith([child({ paired: false })]);
    expect(await screen.findByRole('button', { name: /parear dispositivo/i })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /adicionar filho/i })).not.toBeInTheDocument();
  });

  it('filho pareado: não renderiza nada', async () => {
    const { container } = renderWith([child({ paired: true })]);
    // deixa a query ['children'] resolver antes de checar a ausência
    await new Promise((r) => setTimeout(r, 80));
    expect(container.querySelector('[aria-label="Primeiros passos"]')).toBeNull();
  });

  it('CTA do passo 1 abre o AddChildDialog', async () => {
    const user = userEvent.setup();
    renderWith([]);
    await user.click(await screen.findByRole('button', { name: /adicionar filho/i }));
    expect(screen.getByTestId('add-child-dialog')).toBeInTheDocument();
  });

  it('CTA do passo 2 abre o PairDeviceDialog do primeiro filho não pareado', async () => {
    const user = userEvent.setup();
    renderWith([child({ id: 5, name: 'Ana', paired: false })]);
    await user.click(await screen.findByRole('button', { name: /parear dispositivo/i }));
    expect(screen.getByTestId('pair-dialog')).toHaveTextContent('Ana');
  });
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd public/app-parent && rtk proxy pnpm exec vitest run src/components/OnboardingChecklist.test.tsx`
Expected: FAIL — `Failed to resolve import "./OnboardingChecklist"`.

- [ ] **Step 4: Write the component**

Create `public/app-parent/src/components/OnboardingChecklist.tsx`:

```tsx
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { listChildren } from '../api/children';
import { AddChildDialog } from './AddChildDialog';
import { PairDeviceDialog } from './PairDeviceDialog';
import { Icon } from './Icon';

type OpenDialog = 'none' | 'child' | 'pair';

export function OnboardingChecklist() {
  const { data } = useQuery({ queryKey: ['children'], queryFn: listChildren });
  const [dialog, setDialog] = useState<OpenDialog>('none');

  if (!data) return null;
  const hasChild = data.length > 0;
  const hasPaired = data.some((c) => c.paired);
  if (hasChild && hasPaired) return null;

  const firstUnpaired = data.find((c) => !c.paired) ?? null;
  const done = (hasChild ? 1 : 0) + (hasPaired ? 1 : 0);

  return (
    <section
      aria-label="Primeiros passos"
      className="glass-panel rounded-2xl p-6 shadow-ambient"
    >
      <h2 className="font-display text-headline-md text-primary">Bem-vindo ao GuardKids 👋</h2>
      <p className="mt-1 text-label-md text-on-surface-variant">
        Configure sua família em 2 passos — {done} de 2 concluídos.
      </p>
      <ol className="mt-4 space-y-3">
        <StepRow
          done={hasChild}
          title="Adicione seu primeiro filho"
          ctaLabel="Adicionar filho"
          onCta={() => setDialog('child')}
        />
        <StepRow
          done={hasPaired}
          title="Pareie um dispositivo"
          ctaLabel="Parear dispositivo"
          locked={!hasChild}
          lockedHint="Adicione um filho primeiro"
          onCta={() => setDialog('pair')}
        />
      </ol>

      <AddChildDialog open={dialog === 'child'} onClose={() => setDialog('none')} />
      {firstUnpaired && (
        <PairDeviceDialog
          open={dialog === 'pair'}
          onClose={() => setDialog('none')}
          childId={firstUnpaired.id}
          childName={firstUnpaired.name}
        />
      )}
    </section>
  );
}

function StepRow({
  done,
  title,
  ctaLabel,
  onCta,
  locked = false,
  lockedHint,
}: {
  done: boolean;
  title: string;
  ctaLabel: string;
  onCta: () => void;
  locked?: boolean;
  lockedHint?: string;
}) {
  return (
    <li className="flex items-center gap-3">
      <Icon
        name={done ? 'check_circle' : 'radio_button_unchecked'}
        filled={done}
        className={done ? 'text-secondary' : 'text-on-surface-variant'}
      />
      <span
        className={`flex-1 text-label-md ${
          done ? 'text-on-surface-variant line-through' : 'text-on-surface'
        }`}
      >
        {title}
      </span>
      {!done &&
        (locked ? (
          <span className="text-label-sm text-on-surface-variant">{lockedHint}</span>
        ) : (
          <button
            type="button"
            onClick={onCta}
            className="rounded-lg bg-primary px-3 py-1.5 text-label-sm font-semibold text-white hover:bg-primary-container"
          >
            {ctaLabel}
          </button>
        ))}
    </li>
  );
}
```

> **Confirmar props reais antes de codar:** `AddChildDialog({ open, onClose, child? })` e `PairDeviceDialog({ childId, childName, open, onClose })` — já verificados no spec. O `<Icon>` aceita `name`, `filled`, `className` (ver usos existentes).

- [ ] **Step 5: Run test to verify it passes**

Run: `cd public/app-parent && rtk proxy pnpm exec vitest run src/components/OnboardingChecklist.test.tsx`
Expected: PASS (5 testes). Se o teste "não renderiza nada" ficar frágil, simplificar pra: após `await new Promise(r=>setTimeout(r,50))`, `expect(container.querySelector('[aria-label="Primeiros passos"]')).toBeNull()`.

- [ ] **Step 6: tsc**

Run: `cd public/app-parent && pnpm exec tsc -b`
Expected: `No errors found` (o novo campo `paired` no tipo `Child` pode quebrar fixtures de OUTROS testes que montam `Child` literal sem `paired` — se `tsc` acusar, adicionar `paired: false` nessas fixtures; corrigir todas antes de seguir).

- [ ] **Step 7: Commit**

```bash
git add public/app-parent/src/api/types.ts public/app-parent/src/components/OnboardingChecklist.tsx public/app-parent/src/components/OnboardingChecklist.test.tsx
git commit -m "feat(onboarding): componente OnboardingChecklist + Child.paired"
```

---

## Task 5: Frontend — montar o checklist no Dashboard

**Files:**
- Modify: `public/app-parent/src/pages/Dashboard.tsx`
- Test: `public/app-parent/src/pages/Dashboard.test.tsx`

- [ ] **Step 1: Write the failing test**

Em `public/app-parent/src/pages/Dashboard.test.tsx`, mockar o componente como marker (no topo, junto dos outros mocks) e adicionar o teste:

```tsx
vi.mock('../components/OnboardingChecklist', () => ({
  OnboardingChecklist: () => <div data-testid="onboarding-checklist" />,
}));
```

```tsx
it('monta o OnboardingChecklist no topo', async () => {
  // usar o mesmo setup dos outros testes do Dashboard (listChildren/listRequests mockados)
  // ... render do Dashboard ...
  expect(await screen.findByTestId('onboarding-checklist')).toBeInTheDocument();
});
```

> Reusar o helper de render e os mocks de `listChildren`/`listRequests` já existentes no arquivo. O marker aparece independentemente do estado (a lógica de esconder vive DENTRO do componente, coberta na Task 4).

- [ ] **Step 2: Run test to verify it fails**

Run: `cd public/app-parent && rtk proxy pnpm exec vitest run src/pages/Dashboard.test.tsx`
Expected: FAIL — `Unable to find an element by: [data-testid="onboarding-checklist"]`.

- [ ] **Step 3: Montar no Dashboard**

Em `public/app-parent/src/pages/Dashboard.tsx`:

(a) Adicionar o import:
```tsx
import { OnboardingChecklist } from '../components/OnboardingChecklist';
```

(b) Renderizar como primeiro filho do `<main>`, antes do `<HeroDashboard />`:
```tsx
    <main className="...">
      <OnboardingChecklist />
      <HeroDashboard />
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd public/app-parent && rtk proxy pnpm exec vitest run src/pages/Dashboard.test.tsx`
Expected: PASS.

- [ ] **Step 5: Suíte completa do app-parent + tsc**

Run: `cd public/app-parent && pnpm exec tsc -b && rtk proxy pnpm exec vitest run`
Expected: tsc `No errors found`; vitest todos passando (contagem = 361 + testes novos desta feature).

- [ ] **Step 6: Commit**

```bash
git add public/app-parent/src/pages/Dashboard.tsx public/app-parent/src/pages/Dashboard.test.tsx
git commit -m "feat(onboarding): monta o checklist no topo do Dashboard"
```

---

## Verificação final (após todas as tasks)

- [ ] PHP unit suite verde (comando do header) — `OK (598 tests, ...)` (594 baseline + 4 novos).
- [ ] app-parent: `pnpm exec tsc -b` limpo + vitest todos verdes.
- [ ] Smoke manual opcional no LocalWP: com 0 filhos o checklist aparece no topo do Painel; criar um filho fecha o passo 1; parear fecha o passo 2 e o card some.

## Notas de escopo / não fazer

- **Não** buildar/deployar aqui (fica pro release seguinte, junto com o preço novo já commitado).
- **Não** limpar tokens órfãos na exclusão de filho (dívida pré-existente, fora de escopo — ver spec §3.1).
- **Não** adicionar botão "pular/depois" (decisão (a) do spec).
