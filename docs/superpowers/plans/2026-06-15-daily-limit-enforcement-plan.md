# Plano — Fase 9: Daily Limit Enforcement

- **Data:** 2026-06-15
- **Versão alvo:** `1.6.0` (feature nova → minor) · `GUARDKIDS_DB_VERSION` 8 → 9
- **Pré-requisito:** Fase 8 (bedtime/weekday) entregue — este plano espelha aquela arquitetura.
- **Branch sugerida:** `feature/daily-limit-enforcement`

## 1. Objetivo

Hoje o limite diário de tela (`limit_minutes`, default 60) é **configurável mas não
bloqueia nada** (apontado como Fase 9 em `2026-06-07-schedule-bedtime-design.md:13`).
Esta fase faz o limite **bloquear de verdade** o PWA infantil quando os minutos de
hoje atingem o teto, reusando o trilho server-driven já existente:

```
ScheduleEvaluator (pura) → ChildSelfController::me → /child/me.schedule → App.tsx (poll 60s) → <Blocked reason="limit" />
```

### Critério de sucesso (verificável)

1. Filho com `daily_limit_enabled=1` e `limit_minutes=60` que já acumulou ≥60 min de
   `usage_events` **hoje** (dia local do site) recebe `schedule.isBlocked=true,
   reason='limit', unlockAt=<próxima meia-noite local em UTC>` no `/child/me`.
2. O PWA entra em `<Blocked>` em ≤60s (mesmo mecanismo de bedtime) e mostra mensagem
   de limite + contador até a meia-noite.
3. Abaixo do teto, ou com `daily_limit_enabled=0`, **não bloqueia**.
4. Precedência: `weekday` > `bedtime` > `limit` (um dia inteiro bloqueado ou bedtime
   ativo não viram "limit").
5. Suíte completa verde (PHPUnit unit + integration + Vitest) e CI 4/4.

## 2. Decisões de design (assumidas — confirmar antes da Fase 1)

| Decisão | Escolha default | Alternativa | Por quê |
|---|---|---|---|
| **Fonte dos minutos de hoje** | Agregação `SUM(duration_seconds)` de `usage_events` (type `heartbeat`+`site_open`) no **dia local** via `CONVERT_TZ` | Coluna `used_minutes` | A coluna é sempre 0 (nunca escrita); agregação é a única fonte honesta e já tem padrão (`minutesByHourOfDay`) |
| **Opt-in vs sempre-on** | Nova coluna `daily_limit_enabled` (default **0**) + toggle no painel | Enforcar sempre que `limit_minutes>0` | `limit_minutes` default 60 → ligar sem opt-in bloquearia famílias em prod sem aviso |
| **Premium ou free** | **Free** (acompanha `limit_minutes`, que já é free) | Premium (junto de bedtime/weekday) | Monetização. Manter consistência com gating atual; bedtime/weekday seguem o gancho premium "Rotina escolar" |
| **`unlockAt`** | Próxima **meia-noite local** convertida pra UTC | Janela deslizante 24h | Limite "diário" reseta na virada do dia local, alinhado com o agrupamento por `DATE()` |
| **Precedência** | weekday > bedtime > limit | — | limit é o bloqueio "mais leve" e tem unlock menos preciso |

> Itens **fora de escopo** desta fase (não fazer): exibir `usedMinutes` real no painel
> dos pais (a coluna `used_minutes=0` deixa o anel do dashboard sempre vazio — é dívida
> pré-existente, tratar em fase separada); notificação push ao atingir o limite;
> "tempo extra" temporário que estende o teto do dia (já existe o fluxo de `requests`
> kind=`extra_time`, mas consumi-lo pra elevar o limite é outra fase).

## 3. Fases

> Cada fase termina com testes verdes locais + 1 commit lógico + push, esperando CI
> 4/4 verde antes da próxima. Usar `subagent-driven-development` (uma task por subagent
> fresco, review do diff antes de seguir).

---

### Fase 1 — Migration 009 + bump de versão

**Arquivos:** `database/migrations/009_daily_limit_enabled.php` (novo), `guardkids.php`.

- Migration adiciona `daily_limit_enabled TINYINT(1) NOT NULL DEFAULT 0` em
  `wp_guardkids_children`, **AFTER `limit_minutes`**. Usar `$wpdb->query("ALTER TABLE …")`
  direto (mesmo padrão de `003`/`006` — `dbDelta` não aplica ALTER de forma confiável).
- `guardkids.php`: `GUARDKIDS_DB_VERSION` 8 → **9** e `GUARDKIDS_VERSION` → **1.6.0**
  no **mesmo commit** (senão `maybeRunMigrations` skipa — regra conhecida do projeto).

**Verify:** `MigrationRunnerTest` continua verde (ordem + idempotência). Integration:
subir MySQL e confirmar coluna criada com default 0. → `phpunit` unit + integration verdes.

---

### Fase 2 — `UsageEventRepository::minutesUsedToday`

**Arquivo:** `database/UsageEventRepository.php` + testes.

Novo método, espelhando `minutesByHourOfDay` (timezone-aware):

```php
/** Minutos consumidos por um filho no dia local informado (YYYY-MM-DD). */
public function minutesUsedToday(int $childId, string $localDateIso): int
{
    $tz = function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC';
    $sql = $this->db->prepare(
        'SELECT COALESCE(SUM(duration_seconds),0) FROM ' . $this->table()
        . ' WHERE child_id = %d AND type IN ("heartbeat","site_open")'
        . ' AND CONVERT_TZ(created_at, "+00:00", %s) BETWEEN %s AND %s',
        $childId, $tz, "{$localDateIso} 00:00:00", "{$localDateIso} 23:59:59",
    );
    return (int) floor(((int) $this->db->get_var($sql)) / 60);
}
```

**Verify:** unit (`tests/Unit/Database/UsageEventRepositoryTest.php`) — soma só
heartbeat+site_open, ignora `schedule_block`; integration (`tests/Integration/Repository/UsageEventRepositoryTest.php`)
com MySQL real — eventos de ontem não contam, soma cruza fuso corretamente.

---

### Fase 3 — `ScheduleEvaluator` ciente de limite (função pura)

**Arquivo:** `includes/Schedule/ScheduleEvaluator.php` + `ScheduleEvaluatorTest`.

- Estender a assinatura mantendo pureza:
  `evaluate(array $config, DateTimeImmutable $now, int $minutesUsedToday = 0): array`.
- `reason` no PHPDoc passa a `'bedtime'|'weekday'|'limit'|null`.
- **Após** os checks de weekday e bedtime (precedência preservada), adicionar:

```php
$dailyEnabled = (int) ($config['daily_limit_enabled'] ?? 0) === 1;
$limit        = (int) ($config['limit_minutes'] ?? 0);
if ($dailyEnabled && $limit > 0 && $minutesUsedToday >= $limit) {
    return [
        'isBlocked' => true,
        'reason'    => 'limit',
        'unlockAt'  => $this->toUtcIso($now->modify('+1 day')->setTime(0, 0, 0)),
    ];
}
```

**Verify:** novos casos no `ScheduleEvaluatorTest` —
(a) desabilitado → não bloqueia mesmo acima do teto;
(b) habilitado e abaixo → não bloqueia;
(c) habilitado e `>=` teto → `reason='limit'`, `unlockAt`=meia-noite local seguinte em UTC;
(d) `limit_minutes=0` + habilitado → não bloqueia;
(e) precedência: weekday `N` e limite estourado → `reason='weekday'`; bedtime ativo e
limite estourado → `reason='bedtime'`.

---

### Fase 4 — `ChildSelfController::me` calcula e injeta os minutos

**Arquivo:** `api/Controllers/ChildSelfController.php` + `ChildSelfMeScheduleTest`.

- Em `me()`, antes do `evaluate`, calcular o dia local e os minutos:

```php
$today   = $now->format('Y-m-d');
$usedMin = $this->events->minutesUsedToday($childId, $today);
$schedule = $this->evaluator->evaluate($row, $now, $usedMin);
```

- (Já injeta `UsageEventRepository` no construtor — sem nova dependência.)

**Verify:** unit — stubar repo pra devolver minutos acima/abaixo do teto e asserir o
`schedule.reason` na resposta do `/child/me`.

---

### Fase 5 — REST args + persistência de `daily_limit_enabled`

**Arquivo:** `api/Controllers/ChildController.php` + `ChildControllerTest`/`ChildControllerScheduleTest`.

- `createArgs()` e `updateArgs()`: adicionar `'daily_limit_enabled' => ['type' => 'boolean']`.
- Persistir no insert (default 0) e no update (`null` = não mexe, mesmo padrão de
  `bedtime_enabled`). **Não** entra em `touchesSchedule` (fica free, ver §2).
- `childToJson()`: expor `'dailyLimitEnabled' => (int)($row['daily_limit_enabled'] ?? 0) === 1`.

**Verify:** unit — PATCH liga/desliga a flag e ela volta no GET; ligar `daily_limit_enabled`
**não** retorna 402 (free).

---

### Fase 6 — Frontend child: tipo + tela Blocked + report

**Arquivos:** `public/app-child/src/api/types.ts`, `pages/Blocked.tsx`, `App.tsx`
(+ `Blocked.test.tsx`).

- `types.ts`: `ScheduleReason = 'bedtime' | 'weekday' | 'limit'`.
- `Blocked.tsx`: adicionar entradas `limit` em `MESSAGE_BY_REASON`
  ("Você usou todo o tempo de tela de hoje. Amanhã recarrega!") e `LABEL_BY_REASON`
  ("Tempo esgotado"); tornar o ícone do círculo reason-aware (`timer_off` p/ limit,
  `bedtime` p/ resto) em vez do `bedtime` hardcoded.
- `App.tsx`: incluir `'limit'` no guard do `useEffect` de dedupe que dispara
  `reportScheduleBlock` (hoje só `bedtime`/`weekday`). O enum `detail` do endpoint já
  aceita `'limit'` — nada muda no backend de telemetria.

**Verify:** `Blocked.test.tsx` — render com `reason='limit'` mostra label/mensagem certos
e contador até `unlockAt`. `App.test`/usageTracker — dispara `reportScheduleBlock('limit')`
uma vez por sessão (dedupe por `reason+unlockAt`).

---

### Fase 7 — Frontend parent: toggle no DailyTimeCard

**Arquivos:** `public/app-parent/src/api/types.ts`, `api/children.ts`,
`pages/TimeLimits.tsx` (+ `TimeLimits.test.tsx`).

- `types.ts` (Child): adicionar `dailyLimitEnabled: boolean`.
- `children.ts` (`UpdateChildInput`): adicionar `daily_limit_enabled?: boolean`.
- `TimeLimits.tsx` → `DailyTimeCard`: adicionar um `<Toggle>` no header
  ("Bloquear ao atingir o limite") espelhando o `BedtimeCard`, persistindo via
  `updateChild(child.id, { daily_limit_enabled })`. Presets de minutos ficam como estão.

**Verify:** `TimeLimits.test.tsx` — toggle chama `updateChild` com
`daily_limit_enabled` e reflete o estado vindo do `child`.

---

### Fase 8 — Docs + contadores + verificação fim-a-fim

**Arquivos:** `README.md`, este plano.

- Atualizar contadores de testes no README (somar os novos casos das Fases 2–7).
- Mencionar `daily_limit_enabled` na descrição de schema (9 colunas de schedule → +1).
- **Verificação manual** (sem código novo) no LocalWP `guardkids-wp.local`:
  1. No painel `http://guardkids-wp.local/painel-pais` → Limites de Tempo: ligar o
     toggle e setar 60 min num filho pareado.
  2. Gerar ~60 min de `usage_events` (heartbeats) pro filho hoje (via seed SQL ou uso real).
  3. No PWA `http://guardkids-wp.local/painel-filho`: confirmar que entra em `<Blocked>`
     com mensagem de limite e contador até a meia-noite em ≤60s.
  4. Virar a flag pra off → desbloqueia no próximo refetch.

## 4. Resumo de arquivos tocados

| Camada | Arquivos |
|---|---|
| Migration/versão | `database/migrations/009_daily_limit_enabled.php`, `guardkids.php` |
| Backend | `database/UsageEventRepository.php`, `includes/Schedule/ScheduleEvaluator.php`, `api/Controllers/ChildSelfController.php`, `api/Controllers/ChildController.php` |
| Frontend child | `public/app-child/src/api/types.ts`, `pages/Blocked.tsx`, `App.tsx` |
| Frontend parent | `public/app-parent/src/api/types.ts`, `api/children.ts`, `pages/TimeLimits.tsx` |
| Testes | `ScheduleEvaluatorTest`, `UsageEventRepositoryTest` (unit+integration), `ChildSelfMeScheduleTest`, `ChildControllerTest`/`ChildControllerScheduleTest`, `Blocked.test.tsx`, `TimeLimits.test.tsx` |
| Docs | `README.md`, este plano |

## 5. Riscos / armadilhas

- **Timezone**: usar SEMPRE `CONVERT_TZ`/`wp_timezone()` no cálculo do dia — `created_at`
  é UTC. Já validado no padrão `minutesByHourOfDay`.
- **`CONVERT_TZ` precisa das tabelas de timezone do MySQL**; em fallback usar offset.
  Conferir no MySQL do `docker-compose.test.yml` (o teste de `minutesByHourOfDay` já cobre).
- **`reportScheduleBlock`** roda em `useEffect` com dedupe via `localStorage` — não
  duplicar lógica; só ampliar o guard pra incluir `'limit'`.
- **Bump de `GUARDKIDS_DB_VERSION`** tem que ir no mesmo commit da migration.
- **Não** tocar no anel `usedMinutes` do painel dos pais nesta fase (fora de escopo §2).
