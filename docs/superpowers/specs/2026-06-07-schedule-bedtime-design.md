# Schedule (bedtime + weekday on/off) — Design

**Status:** Aprovado para implementação
**Data:** 2026-06-07
**Escopo:** Fase 8 — persistência e enforcement de bedtime e dias da semana permitidos. Wire-up real dos cards `BedtimeCard` e `WeeklyCard` em `TimeLimits.tsx`. Bloqueio efetivo no `app-child` quando fora do horário/dia permitido.

---

## 1. Motivação e contexto

A página `TimeLimits.tsx` do `app-parent` tem hoje **4 cards** — apenas um (`DailyTimeCard`, que persiste `limit_minutes`) está ligado ao backend. Os outros três (`BedtimeCard`, `WeeklyCard`, `TimelineCard`) carregam `<ComingSoonBadge />` e mantêm state apenas local. Sem `BedtimeCard` e `WeeklyCard` persistentes, o produto não cumpre a promessa de controle parental: as regras aparecem no painel mas não são aplicadas em lugar nenhum.

Este spec entrega o caminho completo das duas regras (bedtime + weekday) — schema, REST, UI persistente, e enforcement server-driven no PWA. **`TimelineCard` continua mockado** (depende de agregação horária de `usage_events`, fora do escopo) e **daily limit enforcement não entra** (a coluna `limit_minutes` já existe; passar a bloquear quando excedida é Fase 9).

### Constraints técnicos que enquadram o escopo

- O PWA do filho roda em qualquer navegador — não há agente nativo. Enforcement só funciona dentro do `app-child` (a criança ainda pode abrir outro navegador). MVP aceita essa limitação.
- Relógio do device é não-confiável (criança poderia adiantar pra burlar bedtime). Por isso o **servidor calcula** o estado de bloqueio e o child só consome.

---

## 2. Arquitetura

```
TimeLimits.tsx (parent)
  ├─ DailyTimeCard (existente, sem mudança)
  ├─ BedtimeCard     ──► updateChild(id, { bedtime_enabled, bedtime_start, bedtime_end })
  └─ WeeklyCard      ──► updateChild(id, { allowed_weekdays })
                              │
                              ▼
                    PATCH /children/:id   (manage_options, nonce)
                              │
                              ▼
              wp_guardkids_children (4 colunas novas)
                              │
                              ▼
         ScheduleEvaluator (serviço puro PHP)
         calcula isBlocked + reason + unlockAt
                              │
                              ▼
              GET /child/me  (child token)
              devolve { ...campos atuais, schedule: { isBlocked, reason, unlockAt } }
                              │
                              ▼
         App.tsx (child) ──► força <Blocked reason unlockAt /> se isBlocked
         polling: refetch on visibility + interval 60s
```

**Princípios:**

- **Sem rotas novas**: estende `PATCH /children/:id` (4 args novos) e enriquece `GET /child/me` (1 objeto novo).
- **Sem tabela nova**: 4 colunas em `wp_guardkids_children` (regras são 1:1 com filho neste escopo).
- **Lógica isolada**: `ScheduleEvaluator` é função pura — recebe `(config, $now)` e devolve estado. Sem `$wpdb`, sem `current_time()`. Trivialmente testável.
- **`wp_timezone()` como única fonte de timezone**: controller injeta `$now` no fuso do site.
- **Polling alinhado ao tracker**: 60s em `app-child` (mesmo ritmo do `usageTracker`), mais `refetchOnWindowFocus`.

---

## 3. Schema — migration 003

Arquivo novo: `database/migrations/003_schedule_columns.php`.

```sql
ALTER TABLE wp_guardkids_children
  ADD COLUMN bedtime_start    TIME       NULL                  AFTER limit_minutes,
  ADD COLUMN bedtime_end      TIME       NULL                  AFTER bedtime_start,
  ADD COLUMN bedtime_enabled  TINYINT(1) NOT NULL DEFAULT 0    AFTER bedtime_end,
  ADD COLUMN allowed_weekdays CHAR(7)    NOT NULL DEFAULT 'YYYYYYY' AFTER bedtime_enabled;
```

### Convenções

- **`bedtime_start` / `bedtime_end`**: `TIME` (HH:MM:SS) em **local time do site**. Conversão UTC↔local fica no `ScheduleEvaluator`, não na coluna.
- **`bedtime_enabled = 0`**: ignora `*_start`/`*_end` mesmo se preenchidas (toggle desliga sem perder valores — UX comum). Validação no controller: se `enabled=1`, ambas `start` e `end` precisam ser não-nulas.
- **`allowed_weekdays`**: 7 chars `Y`/`N`, posição **0=segunda … 6=domingo** (alinha com ISO-8601 weekday e com a UI atual que começa em "Seg").
  - Default `'YYYYYYY'`: todos os dias liberados — preserva comportamento atual de todos os filhos já cadastrados após a migration.
  - `'NNNNNNN'` é válido (= bloqueado todo dia o dia inteiro). Sem regra mágica.
- **Cross-midnight no bedtime**: se `bedtime_start > bedtime_end` (ex: `21:30` → `07:00`), a janela cruza meia-noite. O Evaluator trata via `now >= start OR now < end`. Sem coluna nova.

### Bump de versão

`guardkids.php` — `GUARDKIDS_DB_VERSION: 2 → 3`. Sem isso `maybeRunMigrations()` skipa (regra do projeto, ver `feedback_guardkids_wp_migration_bump`).

### Migration runner

Segue padrão de 001/002 (closure recebendo `$wpdb, $charsetCollate`, rodando `dbDelta`). `dbDelta` em `ALTER ADD COLUMN` é idempotente.

---

## 4. ScheduleEvaluator (serviço puro PHP)

Arquivo novo: `includes/Schedule/ScheduleEvaluator.php`. Pure function, sem `$wpdb`, sem side-effects.

### Interface

```php
namespace GuardKids\Schedule;

final class ScheduleEvaluator
{
    /**
     * @param array{
     *   bedtime_enabled: int|bool,
     *   bedtime_start:   ?string,   // 'HH:MM:SS' local time
     *   bedtime_end:     ?string,
     *   allowed_weekdays: string,    // 7 chars Y/N, pos 0=Mon
     * } $config
     * @param \DateTimeImmutable $now  já no timezone local (caller injeta)
     *
     * @return array{
     *   isBlocked: bool,
     *   reason:    'bedtime'|'weekday'|null,
     *   unlockAt:  ?string,          // ISO-8601 UTC, null se !isBlocked
     * }
     */
    public function evaluate(array $config, \DateTimeImmutable $now): array;
}
```

### Lógica (ordem importa — weekday > bedtime)

1. **Weekday check**: `$dayIndex = (int)$now->format('N') - 1` (0..6). Se `allowed_weekdays[$dayIndex] === 'N'` ⇒ `isBlocked=true, reason='weekday', unlockAt = próxima 00:00 local do próximo dia 'Y'`. Se `allowed_weekdays === 'NNNNNNN'` ⇒ `unlockAt=null` (sem horizonte).
2. **Bedtime check**: só se `bedtime_enabled=1` E ambos `start`/`end` não-nulos.
   - **Janela normal** (`start < end`, ex: `13:00-15:00`): bloqueado se `$start <= $now < $end`. `unlockAt = $end`.
   - **Janela cross-midnight** (`start > end`, ex: `21:30-07:00`): bloqueado se `$now >= $start OR $now < $end`.
     - Se `$now >= $start`: `unlockAt = $endTomorrow`.
     - Se `$now < $end`: `unlockAt = $endToday`.
   - **Edge `start == end`**: trata como janela vazia (não bloqueia). Evita lockout permanente acidental por configuração inválida.
3. Caso contrário ⇒ `isBlocked=false, reason=null, unlockAt=null`.
4. `unlockAt` sempre serializado em **UTC** (ISO-8601 com `Z`), porque o child compara contra `Date.now()`.

### Injeção do `$now`

O controller (`ChildSelfController::me`) faz:

```php
$tz       = wp_timezone();
$now      = new \DateTimeImmutable('now', $tz);
$state    = $this->evaluator->evaluate($childRow, $now);
```

Permite testes determinísticos com `$now` fixo, sem `Clock` interface, sem `current_time()` mockado.

### Testes

`tests/Unit/Schedule/ScheduleEvaluatorTest.php` (~15 cases):

- Weekday Y, sem bedtime → unblocked.
- Weekday N → blocked, `reason=weekday`, `unlockAt` no próximo dia Y às 00:00.
- Bedtime disabled → ignora start/end.
- Bedtime normal (13-15), now=14:00 → blocked, `unlockAt=15:00 UTC`.
- Bedtime cross-midnight (22-07), now=23:00 → blocked, `unlockAt=07:00 amanhã`.
- Bedtime cross-midnight, now=06:00 → blocked, `unlockAt=07:00 hoje`.
- Bedtime cross-midnight, now=08:00 → unblocked.
- `start == end` → não bloqueia.
- Boundary: now exatamente em `end` → libera (intervalo half-open).
- `allowed_weekdays='NNNNNNN'` → blocked todo dia, `unlockAt=null`.
- Combo: weekday N + bedtime enabled → `reason=weekday` (precedência).

---

## 5. REST API

### 5.1 `PATCH /children/:id` — 4 args novos

Em `api/Controllers/ChildController.php → updateArgs()`:

```php
'bedtime_enabled' => [
    'type'    => 'boolean',
    'default' => null,                 // null = não tocar
],
'bedtime_start' => [
    'type'              => 'string',
    'pattern'           => '^([01]\\d|2[0-3]):[0-5]\\d$',  // HH:MM
    'sanitize_callback' => 'sanitize_text_field',
],
'bedtime_end' => [
    'type'              => 'string',
    'pattern'           => '^([01]\\d|2[0-3]):[0-5]\\d$',
    'sanitize_callback' => 'sanitize_text_field',
],
'allowed_weekdays' => [
    'type'              => 'string',
    'pattern'           => '^[YN]{7}$',
    'sanitize_callback' => 'sanitize_text_field',
],
```

**Validação extra no controller** (não dá pra expressar em JSON-Schema):

- Se `bedtime_enabled=true` ⇒ `bedtime_start` E `bedtime_end` precisam estar presentes (na request OU no row atual). 422 se um lado fica nulo.
- Coerção: `HH:MM` da request vira `HH:MM:00` antes do `Repository::update` (coluna é `TIME`).
- Partial update preservado: enviar só `allowed_weekdays` não mexe nos campos de bedtime.

**`childToJson()`** (em `ChildController` e `ChildSelfController` — duplicado hoje, extrair pra `ChildPresenter` no caminho) inclui:

```json
{
  "id": 7, "name": "Maria", ...,
  "bedtimeEnabled": true,
  "bedtimeStart": "21:30",
  "bedtimeEnd":   "07:00",
  "allowedWeekdays": "YYYYYNN"
}
```

### 5.2 `GET /child/me` — bloco `schedule`

Mesma rota, mesmo auth. Resposta passa de:

```json
{ "id": 7, "name": "Maria", "usedMinutes": 42, "limitMinutes": 120, ... }
```

para:

```json
{
  "id": 7, "name": "Maria", "usedMinutes": 42, "limitMinutes": 120, ...,
  "schedule": {
    "isBlocked": true,
    "reason":   "bedtime",
    "unlockAt": "2026-06-08T10:00:00Z"
  }
}
```

Implementação:

```php
public function me(WP_REST_Request $req): WP_REST_Response|WP_Error
{
    $childId = $this->auth->resolveChildId($req);
    if ($childId === null) { return new WP_Error(...); }

    $row = $this->children->findById($childId);
    if ($row === null) { return new WP_Error(...); }

    $tz       = wp_timezone();
    $now      = new \DateTimeImmutable('now', $tz);
    $schedule = $this->evaluator->evaluate($row, $now);

    return rest_ensure_response(
        $this->presenter->childToJson($row) + ['schedule' => $schedule]
    );
}
```

`ScheduleEvaluator` é instanciado no `__construct` (sem DI container — segue padrão do projeto).

### 5.3 Sem mudanças noutras rotas

- `POST /child/events` continua aceitando heartbeats mesmo se `isBlocked=true` (útil pra debug; YAGNI rejeita restringir).
- `POST /child/requests` (pedir mais tempo) mantém aberto — é o caminho de escape do filho.

### 5.4 Testes

- `tests/Unit/Api/ChildControllerScheduleTest.php` (~6 cases): PATCH com cada campo isolado, 422 (`enabled=true` sem start/end), pattern `HH:MM` inválido, `allowed_weekdays` chars errados.
- `tests/Unit/Api/ChildSelfMeScheduleTest.php` (~3 cases): `/me` devolve `schedule.isBlocked=false`, `=true reason=bedtime`, `=true reason=weekday`.

---

## 6. UI app-parent (`TimeLimits.tsx`)

### 6.1 Tipo `Child`

`public/app-parent/src/api/types.ts` — adiciona:

```ts
export type Weekday7 = `${'Y'|'N'}${'Y'|'N'}${'Y'|'N'}${'Y'|'N'}${'Y'|'N'}${'Y'|'N'}${'Y'|'N'}`;

export type Child = {
  // ... campos atuais
  bedtimeEnabled: boolean;
  bedtimeStart: string | null;   // 'HH:MM'
  bedtimeEnd:   string | null;
  allowedWeekdays: Weekday7;
};
```

`api/children.ts` — `UpdateChildInput` ganha os mesmos como opcionais (`Partial<Pick<Child, ...>>`).

### 6.2 `BedtimeCard` — persistente

Substitui state local mockado por:

- `enabled` derivado de `child.bedtimeEnabled` + optimistic state.
- `start = child.bedtimeStart ?? '21:30'`, `end = child.bedtimeEnd ?? '07:00'`.
- **Debounce 600ms** nos `TimeInput` (helper local, sem lodash). Toggle persiste imediato.
- Mutation segue padrão de `DailyTimeCard` (`useMutation` + `invalidateQueries(['children'])`).
- Validação client-side: toggle ON com `start` ou `end` vazio mostra erro inline e NÃO envia mutation (espelha o 422 do backend).
- Remove `<ComingSoonBadge />` e o info-box "Storage dedicado entra numa migration futura."

### 6.3 `WeeklyCard` — persistente

- Passa a receber `child` por prop.
- `enabled = new Set(parseWeekdays(child.allowedWeekdays))`.
- `toggle` dispara `updateChild({ allowed_weekdays: serializeWeekdays(next) })`.
- Helpers em `lib/weekdays.ts` (função pura, testes próprios).
- Remove `<ComingSoonBadge />`.

### 6.4 `TimelineCard` — fora de escopo

Mantém mock + badge. Ajusta copy: "Em construção — virá quando tivermos timeline de uso por hora."

### 6.5 Helpers novos

`public/app-parent/src/lib/weekdays.ts`:

```ts
export const WEEKDAY_IDS = ['mon','tue','wed','thu','fri','sat','sun'] as const;
export type WeekDay = typeof WEEKDAY_IDS[number];

export function parseWeekdays(s: string): WeekDay[] {
  if (!/^[YN]{7}$/.test(s)) return [...WEEKDAY_IDS];     // fallback seguro
  return WEEKDAY_IDS.filter((_, i) => s[i] === 'Y');
}

export function serializeWeekdays(days: Set<WeekDay>): string {
  return WEEKDAY_IDS.map(d => days.has(d) ? 'Y' : 'N').join('');
}
```

### 6.6 Testes Vitest

- `pages/TimeLimits.test.tsx` (expandido, +5 cases): valores iniciais vindos de `listChildren`, toggle bedtime persiste, `TimeInput` com fake timer persiste após debounce, toggle weekday persiste, validação inline.
- `lib/weekdays.test.ts` (novo, ~6 cases): parse válido/inválido, serialize todos Y, serialize subconjunto, round-trip.

---

## 7. Enforcement no app-child

### 7.1 Cliente `me()`

`public/app-child/src/api/me.ts` (novo, segue padrão do `app-parent`):

```ts
export type ChildScheduleState = {
  isBlocked: boolean;
  reason:   'bedtime' | 'weekday' | null;
  unlockAt: string | null;  // ISO-8601 UTC
};

export type ChildMe = {
  id: number; name: string; usedMinutes: number; limitMinutes: number;
  /* ... outros campos */
  schedule: ChildScheduleState;
};

export async function fetchMe(token: string): Promise<ChildMe> { /* ... */ }
```

### 7.2 `App.tsx` — força `<Blocked />` server-driven

```tsx
const meQuery = useQuery({
  queryKey: ['me'],
  queryFn:  () => fetchMe(token!),
  enabled:  !!token,
  refetchInterval: 60_000,            // alinhado com heartbeat
  refetchOnWindowFocus: true,
  staleTime: 30_000,
});

const isBlocked = meQuery.data?.schedule.isBlocked ?? false;
const schedule  = meQuery.data?.schedule;

if (isBlocked && schedule) {
  return (
    <div className="min-h-screen overflow-x-hidden bg-surface text-on-surface">
      <Blocked
        reason={schedule.reason!}
        unlockAt={schedule.unlockAt}
        onNavigate={setActivePage}
      />
    </div>
  );
}
```

**Política de erro: fail-open.** Se `meQuery` nunca obteve resposta, assume não bloqueado. Razão: produto não pode trancar a tela porque o WP caiu — pior UX que um pequeno bypass temporário. Decisão consciente.

**Tracker durante bloqueio:** `usageTracker` continua rodando (tela bloqueada também é "visível"). Casa com a decisão de 5.3 (server aceita heartbeat blocked).

### 7.3 `Blocked.tsx` — props reais, sem mock

- Remove `import { blockedInfo } from '../data/mockData'`.
- Props: `{ reason: 'bedtime'|'weekday'; unlockAt: string|null; onNavigate }`.
- `remaining` deriva de `unlockAt`:

```ts
const [now, setNow] = useState(() => Date.now());
useEffect(() => {
  const id = setInterval(() => setNow(Date.now()), 1000);
  return () => clearInterval(id);
}, []);
const unlockMs    = unlockAt ? Date.parse(unlockAt) : null;
const remainingSec = unlockMs ? Math.max(0, Math.floor((unlockMs - now)/1000)) : null;
```

- `unlockAt === null` → mostra **"—"** e texto "Sem horário liberado configurado".
- Copy do badge: map `{ bedtime: 'Soneca', weekday: 'Dia bloqueado' }` em PT-BR.
- Bloco "Que tal isso?" (alternatives mockadas) sai. Substitui por texto simples: "Aproveita pra brincar fora, ler um livro ou descansar 💙" (até termos catálogo real de sugestões).
- **Quando `remainingSec` chega a 0:** o componente NÃO desbloqueia sozinho. O próximo `refetchInterval` (≤60s) traz `isBlocked=false` e `App.tsx` re-renderiza a árvore normal. Garante consistência com a fonte da verdade.

### 7.4 Testes Vitest (app-child)

Precisa adicionar `@testing-library/react` no `app-child` (hoje só tem vitest lib-only). Copia config do `app-parent`.

- `pages/Blocked.test.tsx` (novo, ~5 cases): render com `reason=bedtime`/`weekday`, contador com `unlockAt` futuro, contador com `unlockAt=null` mostra "—", botão Requests dispara `onNavigate('requests')`, contador chega a 0 sem auto-desbloquear.
- `App.test.tsx` (novo, ~4 cases): renderiza `<Blocked />` quando `isBlocked`, renderiza Home quando não, `refetchInterval` configurado, fail-open em erro de rede.

---

## 8. Plano de testes consolidado

| Camada | Arquivo | Cases novos | Foco |
|---|---|---|---|
| Migration | `tests/Unit/Database/MigrationRunnerTest.php` (existente) | +1 | 003 adiciona as 4 colunas; idempotente |
| Service puro | `tests/Unit/Schedule/ScheduleEvaluatorTest.php` (novo) | ~15 | Weekday Y/N, bedtime normal, cross-midnight, edge `start==end`, boundary em `end`, precedência, `unlockAt` UTC, `'NNNNNNN'` → `null` |
| Controller PATCH | `tests/Unit/Api/ChildControllerScheduleTest.php` (novo) | ~6 | Partial update, 422 enabled sem start/end, pattern `HH:MM`, pattern weekdays, sanitize, preserva fields atuais |
| Controller `/me` | `tests/Unit/Api/ChildSelfMeScheduleTest.php` (novo) | ~3 | `schedule` no payload, 3 cenários |
| Lib parent | `public/app-parent/src/lib/weekdays.test.ts` (novo) | ~6 | parse válido/inválido, serialize, round-trip |
| Page parent | `public/app-parent/src/pages/TimeLimits.test.tsx` (existente) | +5 | BedtimeCard persiste, WeeklyCard persiste, validação inline, debounce |
| Page child | `public/app-child/src/pages/Blocked.test.tsx` (novo) | ~5 | render por reason, contador derivado, `unlockAt=null`, botão Requests, não auto-desbloqueia |
| App child | `public/app-child/src/App.test.tsx` (novo) | ~4 | Render `<Blocked />` quando `isBlocked`, render Home quando não, refetch, fail-open |

**Total novo:** ~45 testes (~25 PHPUnit + ~20 Vitest). Base esperada após Fase 8: **~309 testes** (vs 264 hoje).

---

## 9. Critérios de sucesso

Fase 8 está "pronta" quando:

1. **Migration 003 aplicada**: `wp_guardkids_children` tem as 4 colunas; rows existentes ganham defaults (`enabled=0`, `weekdays='YYYYYYY'`); `GUARDKIDS_DB_VERSION=3`.
2. **PATCH /children/:id** aceita os 4 args com validação completa (incluindo 422 nos casos inválidos da seção 5.1).
3. **GET /child/me** devolve `schedule` correto em 3 cenários: livre, bloqueado por bedtime (normal + cross-midnight), bloqueado por weekday.
4. **TimeLimits.tsx**: `BedtimeCard` e `WeeklyCard` sem `<ComingSoonBadge />`, persistindo via `updateChild`, optimistic update funcional.
5. **App.tsx (child)**: ao ativar bedtime no parent dentro da janela atual, a tela do child vira `<Blocked />` em ≤60s (ou ao trazer foco). Ao sair da janela, volta sozinho no próximo `refetch`.
6. **Blocked.tsx**: contador real baseado em `unlockAt`, sem mock; copy correto por reason.
7. **Suíte verde**: `phpunit` + `pnpm test` nos dois apps, ~309 testes ao todo.
8. **Pre-commit gate** (do CLAUDE.md) passa sem regressão nos 264 testes atuais.
9. **Manual smoke** no LocalWP (https://guardkids-wp.local): pareio um device, configuro bedtime atual ±30min no parent, vejo o child bloquear em ≤60s.

---

## 10. Fora de escopo (explícito)

Itens deliberadamente excluídos desta fase:

- **Daily limit enforcement**: a coluna `limit_minutes` existe mas hoje só informa — não bloqueia automaticamente quando excedida. Fica pra Fase 9.
- **Limite por dia da semana** (ex: 1h seg-sex, 3h fim de semana): exige tabela N rows.
- **Múltiplas janelas de bedtime** (cochilo + noite): exige tabela N rows.
- **Timeline real no `TimelineCard`**: depende de agregação horária de `usage_events`.
- **Timezone por filho** (coluna `timezone` em `children`): YAGNI.
- **Recuperação fail-closed em erro de rede**: fail-open consciente (seção 7.2).
- **Bloqueio de `POST /child/events` ou `POST /child/requests` durante bloqueio**: caminhos de escape ficam abertos.
- **Push notification pro parent quando filho entra/sai de bedtime**: depende de canal de push real, Fase futura.
