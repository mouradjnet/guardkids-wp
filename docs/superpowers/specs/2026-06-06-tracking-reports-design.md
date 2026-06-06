# Tracking de uso + página Reports — Design

**Status:** Aprovado pra implementação
**Data:** 2026-06-06
**Escopo:** MVP — heartbeat de sessão + cliques em atalho do SafeBrowser, alimentando a página Reports do app-parent.

---

## 1. Motivação e contexto

A página `Reports` do app-parent hoje renderiza de mock estático em `data/mockData.ts`. O backend (plugin WP `guardkids-wp`) tem 5 tabelas (`children`, `requests`, `sites`, `categories`, `settings`) — não há nenhum dado de uso. Este spec cobre:

1. **Backend**: nova tabela `usage_events`, repositório, endpoint REST de ingest (`POST /child/events`) e endpoint de leitura (`GET /reports`).
2. **App-child (PWA)**: `usageTracker` singleton que envia heartbeats enquanto o PWA está visível, e dispara `site_open` em cliques de atalho no SafeBrowser.
3. **App-parent**: substituir mock por query real, manter UI atual (4 KPI cards, chart empilhado, top sites, per-child summary, range Semana/Mês).

### Constraint técnico que enquadra o escopo

O PWA só mede o que acontece dentro dele. Não dá pra medir "minutos no YouTube" sem agente externo (browser extension/MDM). Portanto, MVP entrega:

- **Heartbeat** (tempo total de tela no PWA app-child) → KPIs, chart, per-child summary
- **Cliques em atalho** (proxy de popularidade) → top sites por **# de aberturas** (não minutos)
- Sem botão Exportar (deferido)

---

## 2. Arquitetura

```
PWA app-child            REST guardkids/v1            DB
─────────────            ───────────────              ──
visibilitychange  ──►  POST /child/events       ──►  wp_guardkids_usage_events
heartbeat (60s)        (token-auth, anti-escalada)   (raw rows)
site_open click   ──►  POST /child/events
                       (mesmo endpoint, type diff)

App-parent Reports ──►  GET /reports?range=week  ──►  SQL GROUP BY
                       (manage_options auth)         (kpis, daily, top sites,
                                                      per-child summary)
```

**Princípios:**
- Um endpoint de ingest aceita os dois tipos de evento via discriminator `type`.
- Um endpoint de leitura devolve toda a Reports num único payload (UI cacheia com `['reports', range]`).
- Sem cron, sem job, sem pré-agregação — volume baixo justifica SQL no read.
- Heartbeat só sai com `document.visibilityState === 'visible'` (não conta tempo em background).

---

## 3. Schema

Nova migration `database/migrations/002_usage_events.php`, seguindo padrão do `001_initial_schema.php`:

```sql
CREATE TABLE wp_guardkids_usage_events (
    id               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    child_id         BIGINT UNSIGNED  NOT NULL,
    type             VARCHAR(20)      NOT NULL,
    domain           VARCHAR(191)     NULL,
    duration_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at       DATETIME         NOT NULL,
    PRIMARY KEY (id),
    KEY child_day (child_id, created_at),
    KEY child_domain (child_id, domain)
) {$charsetCollate};
```

| Campo | Decisão | Por quê |
|---|---|---|
| `type` VARCHAR(20) | enum string `'heartbeat'` ou `'site_open'` | Mesma convenção de `requests.kind`, `sites.list_type` |
| `domain` VARCHAR(191) NULL | só populado quando `type=site_open` | 191 = max prefix index utf8mb4 |
| `duration_seconds` SMALLINT UNSIGNED | 0–65535 (~18h) | Heartbeat normalmente 60, site_open=0 |
| sem `updated_at` | events são imutáveis | Append-only |
| Índices `(child_id, created_at)` e `(child_id, domain)` | cobrem 100% das queries de Reports | Range query + top sites |

**Sem `metadata` JSON, sem `user_agent`, sem `kind` extensível** — YAGNI.

### Repository

`includes/Database/UsageEventRepository.php` (extends `Repository`):

```php
public function insert(array $event): int;
public function aggregateDailyMinutes(int $childId, string $fromIso, string $toIso): array;
public function topDomains(int $childId, string $fromIso, string $toIso, int $limit = 10): array;
public function kpisForRange(int $childId, string $fromIso, string $toIso): array;
```

Sobrecargas com `childId = 0` significam "todos os filhos" (consistente com o pattern de `findByChild` no `RequestRepository`).

---

## 4. Endpoints REST

### 4.1 Ingest — `POST /wp-json/guardkids/v1/child/events`

Auth: `X-GuardKids-Token` header (mesmo pattern do `ChildSelfController` existente). `childId` vem do token (anti-escalada — request não escolhe).

**Payload heartbeat:**
```json
{ "type": "heartbeat", "duration_seconds": 60 }
```

**Payload site_open:**
```json
{ "type": "site_open", "domain": "khanacademy.org", "duration_seconds": 0 }
```

**Resposta `201`:**
```json
{ "id": 12345, "createdAt": "2026-06-06T19:32:01" }
```

**Validações (WP_REST_Server args):**
- `type` ∈ `['heartbeat', 'site_open']`
- `duration_seconds` 0–3600 (cap de 1h por evento — rejeita ruído)
- `domain` obrigatório se `type=site_open`, ignorado se `heartbeat`
- `domain` sanitize: lowercase + `sanitize_text_field`

**Erros:**
- `401 child_auth_required` — token inválido (helper existente)
- `422 invalid_payload` — type/domain inválido ou `duration_seconds` fora do range
- `500 db_error` — falha no insert

**Implementação:** método novo `eventsCreate` no `ChildSelfController` + rota registrada em `RestApi::registerChildSelfRoutes()`.

### 4.2 Leitura — `GET /wp-json/guardkids/v1/reports`

Auth: `manage_options` (helper `RestApi::requireManage`).

**Query params:**
- `range` enum `week|month`, default `week`. Janela rolling: `to = now()`, `from = now() - range_days` (7 ou 30). Inclusivo em `from`, exclusivo em `to`.
- `child_id` opcional. Sem param = agrega todos. Com param = filtra single child.

**Resposta `200`:**
```json
{
  "range": "week",
  "from": "2026-05-30T19:32:01",
  "to": "2026-06-06T19:32:01",
  "kpis": {
    "totalMinutes": 1248,
    "avgMinutesPerDay": 178,
    "percentOfLimit": 0.74,
    "deltaPctVsPrevious": -0.12
  },
  "dailyByChild": [
    { "day": "2026-05-30", "byChild": { "1": 180, "2": 90 } }
  ],
  "topSites": [
    { "domain": "youtube.com", "opens": 14, "topChildId": 1 }
  ],
  "perChild": [
    { "childId": 1, "name": "Lucas", "totalMinutes": 720, "avgMinutesPerDay": 103 }
  ]
}
```

**Decisões de modelagem:**
- `dailyByChild` é flat array com bucket `byChild: { childId → minutes }` — UI faz pivot/stack visual.
- `topSites.topChildId` mostra qual filho mais clicou; `null` se empate.
- `kpis.deltaPctVsPrevious` compara `totalMinutes` da janela atual com a janela imediatamente anterior (semana: 14d–7d atrás; mês: 60d–30d atrás). Fórmula: `(atual - anterior) / anterior`. `null` se `anterior == 0` (evita divisão por zero).
- `percentOfLimit` usa `SUM(children.limit_minutes) * range_days` como denominador; `null` se algum filho não tem limite.
- **Sem endpoints separados** — um único payload alimenta Reports inteira.

**Implementação:** novo `ReportsController` em `api/Controllers/` + rota em novo `RestApi::registerReportsRoutes()`.

---

## 5. Ingest do PWA (app-child)

### 5.1 Onde mora

Novo módulo `public/app-child/src/lib/usageTracker.ts` — singleton. Inicializado uma vez no `App.tsx` do child depois do pair OK. Reutiliza `apiFetch` de `api/client.ts` (já manda `X-GuardKids-Token`).

### 5.2 Lifecycle do heartbeat

```
init():
  visibleSince = Date.now()  // se já visible no boot
  listen('visibilitychange', onVisibilityChange)
  interval = setInterval(flush, 60_000)
  listen('beforeunload', flushSync)

onVisibilityChange():
  if visible: visibleSince = Date.now()
  else:       flush()  // descarrega o que acumulou

flush():
  if !visible: return
  elapsedSec = (Date.now() - visibleSince) / 1000
  if elapsedSec < 5: return            // ignora ruído (<5s)
  if elapsedSec > 90: elapsedSec = 90  // cap (proteção contra throttle/sleep)
  POST /child/events { type: 'heartbeat', duration_seconds: elapsedSec }
  visibleSince = Date.now()

flushSync():  // beforeunload
  navigator.sendBeacon('/wp-json/guardkids/v1/child/events',
    Blob com payload + Authorization header? Nope — sendBeacon não suporta custom headers.
    Fallback: fetch keepalive: true)
```

### 5.3 Decisões

| Pergunta | Decisão | Por quê |
|---|---|---|
| Cadence | 60s | 1 family × 3 kids × 60 req/h = trivial |
| Threshold mínimo | 5s | Filtra tab-switch rápido |
| Cap por evento | 90s | Browser pode throttlar timer; sleep/lock pode gerar elapsed gigante |
| `beforeunload` | flush via `fetch({ keepalive: true })` (sendBeacon não suporta auth header) | Não perde o último intervalo |
| Pause em background | sim, via `visibilitychange` | Senão conta tela travada |
| Fila offline em localStorage | **Não** | Perda <1min/dia aceitável no MVP |

### 5.4 Site opens

Wrapping no click handler de `SiteShortcut` em `Browser.tsx`:

```ts
function trackSiteOpen(domain: string): void {
  apiFetch('/child/events', {
    method: 'POST',
    body: JSON.stringify({ type: 'site_open', domain, duration_seconds: 0 }),
  }).catch(() => { /* silent — métrica não bloqueia UX */ });
}
```

### 5.5 Erros / retry

- Heartbeat falhou → silent. Próximo flush em 60s pega o intervalo seguinte.
- `401` do token → tracker silencioso. A query `getMe` do Home detecta 401 e força re-pareamento (lógica já existe). Tracker pausa naturalmente porque app re-pareia.
- Offline → silent. Sem fila local.
- Sem retry exponencial — métrica é best-effort.

### 5.6 Testabilidade

`createUsageTracker(deps)` aceita `{ now, fetcher, doc }` injetáveis (default: `Date.now`, `apiFetch`, `document`). Testes usam `vi.useFakeTimers()` e validam: heartbeat ao 60s, pausa em hidden, flush em visibility change, cap em 90s, threshold 5s, beforeunload, silent fail no 401.

---

## 6. App-parent: substituir mock por dados reais

### 6.1 API client

Novo `public/app-parent/src/api/reports.ts`:

```ts
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
export type ReportTopSite = { domain: string; opens: number; topChildId: number | null };
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
export function getReport(range: ReportRange = 'week'): Promise<Report>;
```

### 6.2 Reports.tsx — mudanças

```ts
const reportQuery = useQuery({
  queryKey: ['reports', range],
  queryFn: () => getReport(range),
});
const childrenQuery = useQuery({ queryKey: ['children'], queryFn: listChildren });
```

**Painéis remapeados:**

| Painel | Origem nova | Notas |
|---|---|---|
| 4 KPI cards | `report.kpis` derivado localmente | `delta` formata `deltaPctVsPrevious` (ex.: `-12%`); `positive = delta < 0` (gastar menos é bom); `percentOfLimit` null → "—" |
| Stacked chart 7d | `report.dailyByChild` + `report.perChild` (nomes/cores) | `childColors` deixa de ser hardcoded por slug; vira mapping por `childId` com 3 cores fixas + fallback |
| Top sites | `report.topSites` + lookup do filho via `perChild` | Coluna "min" vira **"X aberturas"**; `topChild` → `perChild.find(c => c.id === topChildId)?.name ?? 'Família'` |
| Per-child summary | `report.perChild` direto | Avatar vem de `listChildren` (query separada) |

### 6.3 Range toggle

`RangeButton`s existentes só ganham handlers reais. Mudança de `range` invalida cache via `queryKey: ['reports', range]`.

### 6.4 Estados loading/error/empty

Mesmo padrão das outras páginas:
- `isLoading` → skeletons pulsantes
- `error` → `<ListError>` (componente existente)
- `dailyByChild.length === 0` → empty state "Ainda não há dados de uso. Os dados aparecem quando seus filhos abrirem o app."

### 6.5 Botão Exportar

Continua placeholder (`disabled` + tooltip "Em breve"). Marcado como follow-up.

### 6.6 Cleanup do mock

Remover de `public/app-parent/src/data/mockData.ts`: `dailyMinutesByDay`, `reportKpis`, `topSites`, `type KpiCard`, `type TopSite`. Auditar usos antes de deletar.

---

## 7. Retenção

**MVP: sem cleanup automático.**

- Volume estimado: 3 kids × 60 heartbeats/h × 4h × 365d × 2 anos ≈ 525k rows. Trivial pra MySQL.
- Sem cron, sem TTL, sem partições.
- Quando virar problema real: `UsageEventRepository::cleanupOlderThan(int $days)` + cron WP. Documentado em follow-ups.

---

## 8. Testes

### 8.1 Backend PHP

- **`tests/Unit/Database/UsageEventRepositoryTest.php`** (novo, ~10 tests): `insert` com/sem domain; `aggregateDailyMinutes` agrupa, filtra range, retorna `[]` quando vazio; `topDomains` ignora heartbeat, ordena por opens desc, respeita limit; `kpisForRange` calcula totals e delta, retorna `deltaPctVsPrevious=null` sem janela anterior.
- **`tests/Unit/Api/ChildSelfControllerTest.php`** (extende existente, ~5 tests novos): `eventsCreate` insere heartbeat e site_open; rejeita type inválido (422); rejeita site_open sem domain (422); rejeita duration > 3600 (422); força `childId` do token.
- **`tests/Unit/Api/ReportsControllerTest.php`** (novo, ~6 tests): shape do payload com `range=week` default; aceita `range=month`; rejeita range desconhecido; filtra por `child_id`; arrays vazios sem dados; auth manage_options smoke test.

### 8.2 Frontend TS

- **`api/reports.test.ts`** (novo, ~2 tests): default `range=week`; passa `range=month`.
- **`lib/usageTracker.test.ts`** (novo, ~8 tests, `vi.useFakeTimers()`): heartbeat ao 60s; pausa em hidden; flush no visibility change; cap 90s; threshold 5s; beforeunload via `fetch keepalive`; silent fail no 401; init com visible state.
- **`pages/Reports.test.tsx`** (novo, ~9 tests): loading skeleton; KPIs renderizam formatados; chart com N barras; top sites com "X aberturas"; per-child summary com 1 card por filho; click Semana/Mês troca query; empty state; error state.

### 8.3 Cobertura esperada

- `UsageEventRepository` 100%
- `eventsCreate` 100%, `ReportsController` ~95%
- `usageTracker.ts` >90%
- `api/reports.ts` 100%
- `pages/Reports.tsx` >90%

**Suite final esperada:** ~40 testes novos (21 PHP + 19 TS).
- PHP: 77 → ~98 (UsageEventRepository ~10, ChildSelfController extends +~5, ReportsController ~6).
- TS: 131 → ~150 (api/reports ~2, lib/usageTracker ~8, pages/Reports ~9).
- Coverage app-parent: 75.74% → projeção ~83% (Reports.tsx sai de 0% pra >90%; é o maior gap atual).

---

## 9. Follow-ups deferidos

Documentados aqui pra não voltarem no review do plano:

| Item | Por que deferir |
|---|---|
| Export PDF/CSV do Reports | Stripe pattern — shippa quando pedido |
| Fila offline no `usageTracker` (localStorage queue) | YAGNI; perda <1min/dia aceitável |
| Tabela `usage_daily` pré-agregada | Premature optimization — vol baixo |
| Cron de cleanup com TTL | Idem |
| Atualizar `children.status='online'` no heartbeat | Acoplamento prematuro com Dashboard "online indicator" — fazer junto depois |
| SafeBrowser com navegação real (iframe/redirect/external) | Mudança de produto, fora de escopo de "tracking" |
| Range com date picker / mês personalizado | UI atual só Week/Month — evolui quando vier feedback |
| Tracking de tempo por domínio real | Exige agente externo (browser ext/MDM) — produto futuro |

---

## 10. Risco / aberturas conhecidas

- **`navigator.sendBeacon` não aceita custom headers** → tracker usa `fetch({ keepalive: true })` no `beforeunload`. Validar suporte: Chrome 80+ / Safari 13+ / Firefox 80+ (cobertura suficiente pra PWA).
- **iOS Safari pode matar timers em background agressivamente** → cap de 90s já mitiga; perda < cap por sessão.
- **Range toggle "Mês" sem dados de 30 dias** → empty state cobre; KPIs `deltaPctVsPrevious=null` cobre.
- **Sem migration rollback** — `MigrationRunner` existente não tem down; aceito (consistente com 001).
