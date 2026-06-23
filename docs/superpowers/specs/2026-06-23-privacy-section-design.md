# Privacidade — Export / Limpar histórico / Excluir conta

**Data:** 2026-06-23
**Status:** Aprovado (aguardando review do spec)
**Escopo:** Sair do mock as 3 ActionRows da seção "Privacidade" em `Settings.tsx` do app-parent, ligando-as a endpoints REST reais.

## Contexto

A seção "Privacidade" (`public/app-parent/src/pages/Settings.tsx:193-220`) está marcada `comingSoon` com 3 `ActionRow` sem comportamento: **Exportar todos os dados**, **Limpar histórico** e **Excluir conta e todos os dados**. É a última frente grande de features em mock do plugin.

Modelo de dados: 11 tabelas `wp_guardkids_*` — `children, categories, requests, sites, settings, usage_events, locations, safe_zones, guardians, guardian_invites, companion_devices`. Auth REST: nonce WP + `manage_options` (`requireAdmin`) no namespace `guardkids/v1`. Já existe `includes/Maintenance/Purger.php` que apaga `usage_events` (>90d) e `locations` (>30d) via cron diário `guardkids_daily_purge`.

## Decisões (todas confirmadas com o usuário)

1. **Excluir conta** = apagar os dados da família e **manter o plugin ativo** e pronto pra recomeçar. Não mexe em usuários WP, não desativa o plugin, **não apaga a licença** (`wp_options.guardkids_license`).
2. **Export** = download **JSON síncrono** (sem job em background, sem storage temporário).
3. **Limpar histórico** = reusar o `Purger` (`usage_events` >90d, `locations` >30d) + estender para `requests` decididos >90d. Pedidos pendentes são preservados.
4. **Confirmação** = modal "digite `EXCLUIR` para habilitar" na exclusão; `window.confirm` simples no limpar histórico; nenhuma no export.
5. **Acesso** = disponível para todos os admins (`manage_options`), **sem premium gate** (direitos de dados não têm paywall).
6. **`delete-all` preserva `guardians` e `guardian_invites`** — apaga os outros 9 (children, categories, requests, sites, usage_events, locations, safe_zones, companion_devices + reset de `settings`). Quem administra continua; só os dados das crianças/atividade somem.

## Arquitetura

Controller dedicado fino + serviços testáveis, reusando o `Purger`.

### Backend (`guardkids/v1`, todos `requireAdmin`, sem premium gate)

**`api/Controllers/PrivacyController.php`** com 3 endpoints:

- **`GET /privacy/export`** → `PrivacyExporter::collect(): array`
  - Lê as 11 tabelas e devolve `{ exported_at, site_url, version, tables: { children: [...], requests: [...], ... } }`.
  - **Exclui** as keys reservadas de token da tabela `settings` (`child_token:*`, `companion_token:*`) — mesmo filtro que o `SettingsController` aplica na saída.
  - O download em si é montado no frontend (mantém auth por nonce; um link direto não carregaria o header).

- **`POST /privacy/clear-history`** → reusa `Purger`
  - `purgeOldUsageEvents(90)` + `purgeOldLocations(30)` + **novo** `Purger::purgeOldDecidedRequests(90)`.
  - **Não altera o `run()` do cron** — o cron diário continua idêntico (só as 2 tabelas append-only). O purge de requests é exclusivo da ação manual.
  - Retorna `{ usage_events: N, locations: N, requests: N }`.

- **`POST /privacy/delete-all`** → `PrivacyEraser::wipeAll(): array`
  - DELETE em: `children, categories, requests, sites, usage_events, locations, safe_zones, companion_devices` + limpa a tabela `settings` (volta aos defaults).
  - **Preserva**: `guardians`, `guardian_invites`, usuários WP, `wp_options.guardkids_license`. Não desativa o plugin.
  - Body exige `{ confirm: "EXCLUIR" }` como defesa em profundidade (rejeita 400 se ausente/errado).
  - Retorna resumo `{ tables: { children: N, ... } }`.

**Serviços novos:**
- `includes/Privacy/PrivacyExporter.php` — `collect()` agrega as tabelas; filtra tokens.
- `includes/Privacy/PrivacyEraser.php` — `wipeAll()` apaga as 9 tabelas/reset settings; lista de tabelas explícita (preserva guardians).

**Extensão do `Purger`:** novo método `purgeOldDecidedRequests(int $daysOld): int` → `DELETE FROM ...requests WHERE decided_at IS NOT NULL AND decided_at < %s`. O `decided_at IS NOT NULL` preserva pendentes naturalmente (pendentes têm `decided_at` NULL). Não entra no `run()`.

### Frontend (`Settings.tsx` + novo `api/privacy.ts`)

- **`api/privacy.ts`**: `exportData()`, `clearHistory()`, `deleteAllData(confirm)`.
- **Exportar:** mutation → recebe JSON → `Blob` → dispara download `guardkids-export-YYYY-MM-DD.json`. Sem confirmação.
- **Limpar histórico:** `window.confirm` → mutation → feedback com as contagens apagadas.
- **Excluir conta:** novo `components/DeleteAccountDialog.tsx` (modal; botão vermelho só habilita quando o input == `EXCLUIR`) → mutation com `{ confirm }` → feedback + `invalidateQueries` (UI volta ao estado zero).
- Remove `comingSoon` da seção Privacidade e o `disabled` dessas 3 ActionRows.
- Ajusta a copy do "Limpar histórico" para refletir o comportamento real (eventos/pedidos >90d, localizações >30d).

## Fluxo de dados

1. **Export:** botão → `GET /privacy/export` → JSON no body → front serializa em Blob → download local.
2. **Clear:** botão → confirm → `POST /privacy/clear-history` → Purger roda 3 deletes → contagens → toast.
3. **Delete:** botão → modal digite-`EXCLUIR` → `POST /privacy/delete-all {confirm}` → Eraser apaga 9 tabelas + reset settings → resumo → invalida cache → UI zera.

## Tratamento de erro

- Endpoints retornam `WP_Error` com `status` apropriado em falha (`db_error` 500; `invalid_confirm` 400 no delete sem `confirm` correto).
- Front: cada mutation tem `onError` mostrando mensagem + status (mesmo padrão do `deleteMutation`/`uploadError` já no projeto). Sem falha silenciosa.

## Testes

- **PHP (PHPUnit, FakeWpdb/reflection):**
  - `PrivacyControllerTest`: export tem shape esperado; export **omite** keys de token; clear-history retorna contagens; delete-all apaga as 9 tabelas e **preserva** guardians; delete-all sem `confirm` correto → 400.
  - `PurgerTest`: `purgeOldDecidedRequests` corta decididos com `decided_at` >90d e preserva pendentes (`decided_at` NULL); `run()` permanece sem tocar requests (sem regressão).
- **TS (Vitest):**
  - `api/privacy.test.ts`: path/método/body de cada chamada.
  - `Settings.test.tsx`: export dispara download (mock de `URL.createObjectURL`/anchor); clear pede confirm e mostra contagens; modal de exclusão só habilita o botão ao digitar `EXCLUIR`.

## Fora de escopo (YAGNI)

- Export assíncrono/ZIP/e-mail.
- Histórico de localização configurável.
- Agendamento da limpeza (já existe o cron de retenção).
- Premium gating destas ações.
- Tocar nas seções Notificações/Segurança (frentes separadas).
