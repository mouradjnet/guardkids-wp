# Notificações por Email — Resumo diário + Relatório semanal

**Data:** 2026-06-23
**Status:** Aprovado (aguardando review do spec)
**Escopo:** Tirar do mock os canais de **email** da seção "Notificações" em `Settings.tsx`: resumo diário e relatório semanal, enviados por cron a todos os guardiões ativos. Push e alertas em tempo real ficam fora (Web Push é frente futura).

## Contexto

A seção "Notificações" (`public/app-parent/src/pages/Settings.tsx:79-131`) está `comingSoon` com 4 toggles `locked`: `notifications.push`, `notifications.email` (resumo diário), `notifications.realtime`, `notifications.weekly_report`.

Infra reaproveitável já existente:
- `wp_mail` (usado em `GuardianController` para convites).
- Cron lifecycle idempotente em `includes/Plugin.php`: `maybeScheduleCron()` roda no `onActivate` e no `plugins_loaded` (cobre "substituir plugin" via WP Admin — ver [[feedback-wp-plugin-lifecycle-install-fallback]]); `onDeactivate` limpa hooks. Padrão atual: `guardkids_daily_purge`.
- Agregações em `UsageEventRepository` (kpis por período, minutos por filho, bloqueios), `ChildRepository`, `RequestRepository`.
- `SettingsRepository` key-value para flags; `GuardianRepository` (tem `findWhere` protegido).
- WP 6.4+ (recorrência `weekly` disponível desde WP 5.4).

## Decisões (confirmadas com o usuário)

1. **Escopo = só email**: resumo diário + relatório semanal. Push/realtime ficam fora (Web Push/VAPID é frente futura).
2. **Destinatário = todos os guardiões ativos** (admin + colaboradores), cada um no email da tabela `guardians`. Toggle global liga/desliga (sem preferência por guardião nesta v1).
3. **Conteúdo essencial:**
   - **Diário** (22h): por filho — minutos usados hoje + limite; nº de pedidos pendentes; nº de bloqueios hoje.
   - **Semanal** (seg 8h): por filho — minutos na semana; nº de bloqueios na semana; pedidos aprovados/negados na semana.
4. **Formato = HTML simples branded** (cabeçalho com cor da marca + listas legíveis; `wp_mail` com header `Content-Type: text/html`).
5. **Padrão = desligado/opt-in** (`fallback=false`): nenhum email até o admin ligar.
6. **Horários** no fuso do site: diário às 22:00, semanal segunda às 08:00.

## Arquitetura

Builder de dados separado do mailer (coesão + testes limpos).

### Backend — `includes/Notifications/`

**`DigestData`** — agrega os números. Construtor `?wpdb` (mesmo padrão do `Purger`); faz as agregações com queries `$wpdb->prepare` próprias (COUNT/SUM por filho e por janela de data), no estilo do `UsageEventRepository`. Não depende de métodos específicos de outros repos — assim cada query fica explícita e testável com fake wpdb.
- `buildDaily(): array` → `['date' => ISO, 'children' => [['name','usedMinutes','limitMinutes'], ...], 'pendingRequests' => int, 'blocksToday' => int]`.
- `buildWeekly(): array` → `['range' => [...], 'children' => [['name','weekMinutes'], ...], 'blocksWeek' => int, 'requestsApproved' => int, 'requestsDenied' => int]`.

**`DigestMailer`** — orquestra envio. Construtor injeta `DigestData`, `GuardianRepository`, `SettingsRepository` (todos com default real).
- `sendDaily(): int` → se `settings['notifications.email']` falsy: return 0 (early, zero query/envio). Senão monta `buildDaily()`, renderiza HTML, loopa `GuardianRepository::findActive()`, `wp_mail($email, $subject, $html, ['Content-Type: text/html; charset=UTF-8'])`. Retorna nº de emails enviados.
- `sendWeekly(): int` → idem, gated por `settings['notifications.weekly_report']`.
- Renderização: método privado `renderDailyHtml(array $data): string` / `renderWeeklyHtml(array $data): string` — string HTML com header branded (cor Deep Blue do plugin) + listas. Sem template engine.
- Falha de `wp_mail` num guardião não interrompe o loop (continua nos demais).

**`GuardianRepository::findActive(): array`** — método público novo: `return $this->findWhere(['status' => 'active']);`.

### Cron — `includes/Plugin.php`

- Constantes novas: `DAILY_DIGEST_HOOK = 'guardkids_daily_digest'`, `WEEKLY_DIGEST_HOOK = 'guardkids_weekly_digest'`.
- `maybeScheduleCron()` agenda os 2 novos além do purge (cada um com guard `wp_next_scheduled(...) === false`):
  - diário: `wp_schedule_event($next22h, 'daily', DAILY_DIGEST_HOOK)` — `$next22h` = próximo 22:00 no fuso do site (via `wp_timezone()` + `DateTimeImmutable`).
  - semanal: `wp_schedule_event($nextMon8h, 'weekly', WEEKLY_DIGEST_HOOK)` — próxima segunda 08:00 no fuso do site.
- Boot: `add_action(DAILY_DIGEST_HOOK, [$this, 'runDailyDigest'])` + `add_action(WEEKLY_DIGEST_HOOK, [$this, 'runWeeklyDigest'])`.
- Callbacks: `runDailyDigest()` → `(new DigestMailer())->sendDaily();` / `runWeeklyDigest()` → `sendWeekly()`.
- `onDeactivate()`: `wp_clear_scheduled_hook()` também para os 2 hooks novos.
- Cálculo do primeiro disparo isolado num helper privado `nextOccurrence(int $hour, ?int $weekday): int` (testável separadamente se quiser; mínimo: usar inline com `wp_timezone()`).

### Frontend — `Settings.tsx`

- `notifications.email`: remove `locked`, `fallback={true}` → `fallback={false}`.
- `notifications.weekly_report`: remove `locked`, `fallback={true}` → `fallback={false}`.
- `notifications.push` e `notifications.realtime`: continuam `locked` (disabled, "Em breve" implícito).
- Remove `comingSoon` da `<Section title="Notificações">` (parte funciona agora).
- Os toggles já persistem via o `set`→`updateSettings` existente. Nenhuma mudança de API no front.

## Fluxo de dados

1. Admin liga o toggle → `PATCH /settings { 'notifications.email': true }` (fluxo existente).
2. Cron diário dispara `guardkids_daily_digest` às 22h → `runDailyDigest()` → `DigestMailer::sendDaily()` → checa toggle → agrega → HTML → `wp_mail` por guardião ativo.
3. Idem semanal segunda 8h.

## Tratamento de erro

- Toggle desligado → early return (0 envios, 0 queries).
- `wp_mail` retornando false não aborta o loop nem o cron.
- Sem guardiões ativos → loop vazio, 0 envios.

## Testes

- **PHP (PHPUnit, fake wpdb / captura de `wp_mail`):**
  - `DigestDataTest`: `buildDaily`/`buildWeekly` produzem o shape esperado a partir de rows fake.
  - `DigestMailerTest`: toggle off → `wp_mail` não é chamado; toggle on → chamado 1×/guardião ativo, com header `text/html`, assunto e corpo não-vazios; falha de um envio não impede os demais.
  - `GuardianRepositoryTest`: `findActive` filtra `status='active'`.
- **TS (Vitest):**
  - `Settings.test.tsx`: toggle "Resumo diário por email" e "Relatório semanal" agora habilitados → clicar chama `updateSettings` com a key correta; push/realtime seguem disabled; ajustar o teste do badge ComingSoon (Notificações deixa de ter "Em breve"; só Segurança mantém).

## Fora de escopo (YAGNI)

Web Push/VAPID/service worker, alertas em tempo real, preferência de notificação por guardião, escolha de horário pela UI, digest mensal, anexar o JSON de export no email.
