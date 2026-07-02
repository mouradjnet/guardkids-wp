# Design — Notificações do app-filho (fundação in-app; fase 1 de push)

- **Data:** 2026-07-02
- **Status:** aprovado (aguardando review do spec)
- **Escopo:** app-filho (PWA infantil) do guardkids-wp
- **Abordagem escolhida:** tabela dedicada + serviço `Notifier` (funil único), arquitetada para o Web Push plugar como canal de entrega na fase 2.

## 1. Contexto e objetivo

Hoje a página **Alertas** do app-filho é 100% mock (array hardcoded em `Alerts.tsx`) e **não existe backend de notificações**. Web Push real (notificação do SO com o app fechado) exige SW customizado, VAPID, storage de subscription e envio web-push no servidor — com caveats de iOS (16.4+, PWA instalada). Nada disso se sustenta sem uma fundação de notificações.

Esta fase entrega essa **fundação in-app**: um backend real de notificações, a página Alertas viva e um badge de não-lidas. É pré-requisito do push e já entrega valor sozinha (mata o mock). O Web Push fica para a **fase 2**, plugando no mesmo funil.

### Metas
- Tabela + repositório de notificações com estado lido/não-lido e dedup.
- Geração de notificações a partir de 4 gatilhos reais.
- Endpoints autenticados por token do filho; `unreadNotifications` no `/child/me`.
- Alertas real + badge de não-lidas na BottomNav.
- Arquitetura pronta para o `PushSender` da fase 2.

### Não-metas (YAGNI nesta fase)
- Web Push / Service Worker customizado / VAPID / subscriptions.
- Preferências por tipo de notificação; on/off por filho.
- Agrupamento/threading; paginação além de um limite fixo.
- Notificações para o painel-pais (esta fase é só o app-filho).

## 2. Modelo de dados

Nova tabela `wp_guardkids_notifications` — migração **014**, `GUARDKIDS_DB_VERSION` 13 → 14, aplicada via `$wpdb->query` (padrão confiável; dbDelta é unreliable pra ALTER — lição das migrações 003/007).

| coluna | tipo | nota |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `child_id` | BIGINT UNSIGNED NOT NULL | `KEY child_created (child_id, created_at)` |
| `type` | VARCHAR(32) NOT NULL | ver §4 |
| `title` | VARCHAR(160) NOT NULL | |
| `body` | VARCHAR(255) NULL | |
| `dedup_key` | VARCHAR(191) NULL | `UNIQUE KEY child_dedup (child_id, dedup_key)` |
| `read_at` | DATETIME NULL | null = não-lida |
| `created_at` | DATETIME NOT NULL | UTC (`current_time('mysql', true)`) |

Observações:
- `dedup_key` NULL é permitido e **não** dispara o UNIQUE (MySQL permite múltiplos NULL). Notificações sem necessidade de idempotência podem omitir a chave; as de janela/evento a usam.
- `uninstall.php` passa a dropar `wp_guardkids_notifications`.

`NotificationRepository extends Repository` (`tableSuffix() = 'notifications'`):
- `findByChild(int $childId, int $limit = 50): array` — `WHERE child_id = ? ORDER BY created_at DESC, id DESC LIMIT` (clamp 1..50).
- `unreadCount(int $childId): int` — `COUNT(*) WHERE child_id = ? AND read_at IS NULL`.
- `markAllRead(int $childId): int` — `UPDATE ... SET read_at = <utc> WHERE child_id = ? AND read_at IS NULL`; retorna linhas afetadas.
- `createIfAbsent(int $childId, ?string $dedupKey, array $data): bool` — se `dedupKey` não-nulo e já existe linha `(child_id, dedup_key)`, não insere (retorna false). Insere com `title/body/type` e retorna true.

## 3. API REST (namespace `guardkids/v1`, auth por `X-GuardKids-Token`)

No `ChildSelfController` + rotas em `RestApi::registerChildSelfRoutes`:

- **`GET /child/notifications`** → `array<{id:int, type:string, title:string, body:?string, read:bool, createdAt:?string}>` (máx 50, novo→velho). `childId` sempre do token.
- **`POST /child/notifications/read`** → `markAllRead(childId)` → `{updated:int}`.
- **`GET /child/me`** → resposta ganha `unreadNotifications: int` (via `NotificationRepository::unreadCount`). O app já faz polling do `/me` a cada 60s → o badge atualiza sem endpoint dedicado.

Todas com `permission_callback` = `ChildAuth::requireToken()`.

## 4. Geração por gatilho — serviço `Notifier`

`GuardKids\Notifications\Notifier` (construtor aceita `?NotificationRepository` para teste) é o **funil único** de criação. Métodos e cópia (PT-BR, kid-friendly):

| `type` | Método / ponto de chamada | title | body | dedup_key |
|---|---|---|---|---|
| `request_approved` | `notifyRequestDecided($row,'approved')` em `RequestController::decide` | `Seu pedido foi aprovado! 🎉` | `${description} ${highlight}` (trim) | `req:{id}` |
| `request_denied` | idem, `'denied'` | `Seu pedido não foi aprovado` | idem | `req:{id}` |
| `site_allowed` | `notifySiteAllowed($domain)` em `SiteController::create` (só `list_type=whitelist`) — **1 linha por filho** | `Novo site liberado` | `Agora você pode acessar ${domain}` | `site:{domain}` |
| `time_warning` | `notifyApproachingLimits(...)` em `ChildSelfController::me` | `Tempo acabando` | `Faltam ${N} min de tela hoje.` | `limit:{Y-m-d local}` |
| `bedtime_warning` | idem | `Hora de dormir chegando` | `A hora de dormir começa em ${N} min.` | `bedtime:{Y-m-d local}` |
| `blocked` | `notifyBlocked($childId,$detail)` em `ChildSelfController::eventsCreate` (type `schedule_block`) | por `detail`: bedtime→`Hora de dormir` / weekday→`Dia bloqueado` / limit→`Tempo esgotado` | `O acesso está pausado agora.` | `blocked:{detail}:{Y-m-d local}` |

### 4.1. Aviso de tempo / hora de dormir (a parte mais delicada — sem cron)
Nasce durante o polling do `/child/me`, usando dados que o handler já tem (`usedMinutes`, `limitMinutes`, `bedtime_start`, `allowed_weekdays`, `now` no fuso do WP). Constante `WARNING_MINUTES = 10`.

- **time_warning**: se o limite diário está ligado (`limitMinutes > 0`) e `0 < (limitMinutes − usedMinutes) ≤ 10` → cria com `N = limitMinutes − usedMinutes`. Dedup `limit:{data local}` (1 por dia).
- **bedtime_warning**: se bedtime ligado e hoje é dia com bedtime e `0 < minutosAté(bedtime_start) ≤ 10` → cria com `N`. Dedup `bedtime:{data local}` (1 por noite).
- Só cria quando ainda **não** está bloqueado (é um aviso de que vai acontecer). A lógica de "minutos até" e o gate de limiar ficam numa função pura testável (`Notifier::approachingWarnings(childRow, usedMin, now): array<notif>`), e o `me` só persiste o resultado via `createIfAbsent`.

### 4.2. Site liberado para todos os filhos
A whitelist é da família (global). Quando o admin adiciona um site direto (`SiteController::create` com whitelist), cria-se **1 notificação por filho** (loop em `ChildRepository::findAll`), dedup `site:{domain}` por filho. O caminho de **aprovação de pedido** (`allowDomain` via `RequestController`) já gera `request_approved` para o filho específico → sem duplicação (são caminhos distintos).

## 5. Frontend (app-child)

- **`src/api/types.ts`**: `type Notification = { id:number; type:string; title:string; body:string|null; read:boolean; createdAt:string|null }`. `Child` ganha `unreadNotifications?: number`.
- **`src/api/child.ts`**: `listNotifications(): Promise<Notification[]>` (`GET /child/notifications`); `markNotificationsRead(): Promise<{updated:number}>` (`POST /child/notifications/read`).
- **`src/pages/Alerts.tsx`**: remove o mock. `useQuery(['child','notifications'], listNotifications)` com loading/erro/vazio. Ícone+tom derivados de `type` (mapa: approved→check_circle/mint, denied→cancel/error, site_allowed→public/primary, time_warning/bedtime_warning→schedule/orange, blocked→block/error). Ao montar, dispara `useMutation(markNotificationsRead)` e invalida `['child','me']` (zera o badge). Data relativa via helper (reusa o padrão `formatRelative` do Requests).
- **Badge**: o pontinho vermelho do item "Alertas" na `BottomNav` passa a refletir `unreadNotifications > 0`. `App.tsx` já tem o `meQuery` de alto nível → passa `alertsUnread={meQuery.data?.unreadNotifications ?? 0}` para `BottomNav`, que mostra o ponto quando `> 0`.

## 6. Testes

**PHP (unit, sem Docker):**
- `NotificationRepository`: create, `findByChild` (ordem/limit), `unreadCount`, `markAllRead`, `createIfAbsent` (insere 1x; 2ª chamada com mesmo dedup é no-op).
- `Notifier`: título/corpo corretos por gatilho; idempotência via dedup; `approachingWarnings` (dentro/fora do limiar de 10 min; bedtime em dia sem bedtime não dispara; não dispara se já bloqueado).
- `ChildSelfController`: `GET /child/notifications` (filtra pelo token, 401 sem token), `POST /child/notifications/read` (updated), `unreadNotifications` no `/me`.
- `RequestController::decide` cria notificação approved/denied.
- `SiteController::create` (whitelist) cria 1 por filho; blacklist não cria.
- Migração 014 idempotente (Unit `MigrationRunner` + Integration real).

**vitest (app-child):**
- `Alerts.tsx`: lista real, empty state, dispara mark-read ao montar.
- `BottomNav`: mostra o ponto quando `alertsUnread > 0`, esconde quando 0.
- `api/child.ts`: paths/métodos de `listNotifications`/`markNotificationsRead`.

## 7. Fase 2 (Web Push — fora deste spec)

O `Notifier` é o funil único de criação. A fase 2 adiciona: tabela `push_subscriptions`, endpoint de subscribe, chaves VAPID (opção pública no bootstrap do PWA), SW customizado via `injectManifest` com handlers `push`/`notificationclick`, e um `PushSender` que — logo após o `Notifier` criar a linha — entrega o web-push às subscriptions do filho. **Decisão adiada:** web-push em PHP puro (openssl + sodium, RFC 8291) vs. dependência `minishlink/web-push` (conflita com o princípio de plugin self-contained). iOS exige 16.4+ e PWA instalada.

## 8. Decisões resolvidas
- **Abordagem:** tabela dedicada + `Notifier` (aprovada; alternativas "derivar em leitura" e "reusar usage_events" descartadas — sem estado de leitura/dedup e não plugáveis em push).
- **4 gatilhos** incluídos nesta fase (incl. aviso de tempo, feito sem cron via polling do `/me`).
- **Site liberado** notifica todos os filhos (whitelist é da família).
- **Badge** via `unreadNotifications` no `/me` (sem endpoint dedicado).
