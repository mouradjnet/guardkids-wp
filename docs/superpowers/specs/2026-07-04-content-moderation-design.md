# Moderação de Conteúdo — Mundo Guardião (v1.34.0)

**Data:** 2026-07-04
**Módulo:** Mundo Guardião (conteúdo infantil)
**DB:** v22 → v23 (migração 023)

## Problema

Hoje o conteúdo do Mundo Guardião é curado exclusivamente pelos pais (`/content/*`
create/update/delete = `requireAdmin`) e a criança apenas consome. Não existe etapa de
revisão: **conteúdo adicionado fica imediatamente visível pra criança** (filtrado só por
idade). Falta um portão de aprovação — nada deve chegar na criança sem um guardião ter
aprovado explicitamente.

## Decisões (fechadas no brainstorming)

1. **Foco:** fila de aprovação (gate) — conteúdo novo entra como `pending` e só fica
   visível pra criança depois de aprovado.
2. **Quem aprova:** qualquer guardião, **incluindo o próprio autor** (gate de staging).
   Não trava pai solo; funciona também com co-guardião. Sem exigência de "4 olhos".
3. **Escopo do status:** **global** no catálogo — uma coluna de status em `content_items`.
   O recorte por-criança continua sendo por idade + recomendações (como já é hoje).
4. **Abordagem:** A — coluna `status` em `content_items` + filtro na camada de leitura da
   criança (com `approved_by`/`approved_at` na mesma tabela pra rastreabilidade barata,
   sem tabela de auditoria separada).
5. **Máquina de estados:** `pending ⇄ approved` (2 estados, reversível). Sem "rejeitado"
   separado — deletar ou deixar pendente cobre o caso. A revogação (`approved → pending`)
   cobre de graça "tirar da vista da criança algo já publicado" sem deletar.

## Modelo de dados

**Migração 023** (bump `GUARDKIDS_DB_VERSION` 22 → 23), padrão idempotente
`addColumnIfMissing`:

- `content_items.status` — `VARCHAR(20) NOT NULL DEFAULT 'pending'`
- `content_items.approved_by` — `BIGINT UNSIGNED NULL` (WP user id de quem aprovou,
  via `get_current_user_id()` — o endpoint de aprovação é `requireAdmin`)
- `content_items.approved_at` — `DATETIME NULL`

**Grandfather:** imediatamente após adicionar a coluna, rodar uma única vez
`UPDATE content_items SET status='approved', approved_at=<agora UTC> WHERE status='pending'`
— todo conteúdo já existente em prod continua visível. Conteúdo novo criado pela app
depois disso nasce `pending` explicitamente.

> **Risco principal:** o grandfather é o ponto sensível — se falhar, conteúdo real some da
> vista das crianças. Mitigado por (a) teste unit do grandfather e (b) smoke E2E pós-deploy
> comparando a contagem da biblioteca do filho antes/depois.

## Backend

### `ContentRepository` (`database/ContentRepository.php`)

- `search(?int $categoryId, ?string $term, ?int $childAge, bool $approvedOnly = false)`:
  quando `approvedOnly` é `true`, adiciona `AND status = 'approved'` ao WHERE.
- Novo `findApprovedById(int $id): ?array` — devolve o item só se `status='approved'`,
  senão `null` (usado nos caminhos de recomendação/favorito da criança).
- `create()`: grava `status = 'pending'` explicitamente.
- Novos `approve(int $id, int $userId): bool` e `revoke(int $id): bool` — `UPDATE`
  do status + `approved_by`/`approved_at` (`userId` = `get_current_user_id()`; revoke
  zera `approved_by`/`approved_at`).
- Novo `countByStatus(string $status): int` — pro badge de pendentes.

### Enforcement — regra de ouro: todo caminho de leitura da criança usa leitura approved-only

| Caminho child (`ContentController`) | Chamada | Mudança |
|---|---|---|
| `childLibrary` | `search(cat, term, age)` | `search(..., approvedOnly: true)` |
| `childLibraryCategories` | `search(null, null, age)` + contagem | `search(..., approvedOnly: true)` |
| `childRecommendations` | `findById()` por rec | `findApprovedById()` (pula pendente) |
| `childFavorites` | `findById()` por favorito | `findApprovedById()` (pula pendente) |
| `childAddFavorite` | grava por `contentId` | valida aprovado antes de gravar; 409 se não |
| `childHistory` | grava por `contentId` | valida aprovado antes de gravar; 409 se não |

### `ContentController` (admin)

- `listContents` aceita `?status=pending|approved|all` (default `all`) e **inclui `status`
  no JSON** de cada item (o child não usa, mas é inofensivo).
- Novos endpoints (ambos `requireAdmin`, registrados em `RestApi::registerContentRoutes`):
  - `POST /content/(?P<id>\d+)/approve`
  - `POST /content/(?P<id>\d+)/revoke`
- `summary` ganha `pendingCount` (via `countByStatus('pending')`) pro badge no painel.
- `analytics`/`summary` do admin continuam contando tudo (visão do pai).

## Frontend

### app-parent — `ContentDashboard.tsx` + `api/content.ts` (mudanças cirúrgicas, reusa o hub)

- **Filtro de status** acima da lista: segmented control *Todos / Pendentes / Aprovados*;
  passa `status` pro `listContents(status)`.
- **Badge por item** na lista: `Pendente` (âmbar) / `Aprovado` (verde), + botão de ação
  contextual **"Aprovar"** (quando pendente) ou **"Revogar"** (quando aprovado). Chamam os
  endpoints novos e invalidam `['content','list']` + `['content','summary']`.
- **Contador de pendentes**: badge no cabeçalho "Conteúdo Infantil" vindo de
  `summary.pendingCount`.
- **`api/content.ts`**: novos `approveContent(id)` / `revokeContent(id)` e param `status`
  em `listContents`.
- **`ContentForm`**: sem mudança de UI. Conteúdo salvo nasce `pending` e aparece na lista
  com o botão "Aprovar" — a aprovação é um clique deliberado separado (mantém o gate
  íntegro). Sem "salvar e publicar direto" (YAGNI).

### app-child — nenhuma mudança de código

O enforcement é 100% server-side: conteúdo pendente simplesmente não volta nas queries do
filho (biblioteca, categorias, recomendações, favoritos). A superfície infantil não sabe
que moderação existe. Efeito correto e de graça: se um conteúdo favoritado for revogado,
ele some da lista de favoritos da criança automaticamente.

## Testes

### PHP unit (padrão `FakeWpdb`/reflection, sem MySQL)

- **Migração 023:** conteúdo existente vira `approved` (grandfather); coluna default
  `pending`; idempotência (rodar 2× não quebra).
- **`ContentRepository`:** `search(approvedOnly:true)` exclui pendente; `findApprovedById`
  devolve `null` pra pendente; `create` grava `pending`; `approve`/`revoke` setam status +
  `approved_by`/`approved_at`; `countByStatus`.
- **`ContentController` child:** `childLibrary` e `childLibraryCategories` (contagens) não
  incluem pendente; `childRecommendations` e `childFavorites` pulam pendente;
  `childAddFavorite`/`childHistory` rejeitam contentId não-aprovado (409).
- **`ContentController` admin:** `approve`/`revoke` funcionam e exigem admin (401 sem);
  `listContents?status=` filtra; `summary.pendingCount` correto.

### Frontend vitest

- **app-parent `ContentDashboard`:** filtro de status troca a query; badge Pendente/Aprovado
  por item; "Aprovar" chama `approveContent` + invalida; "Revogar"; contador de pendentes.
- **`api/content.ts`:** `approveContent`/`revokeContent` batem no endpoint certo.
- **app-child:** nenhum teste novo (sem mudança de código); suíte atual segue verde.

## Rollout / deploy

1. Branch `feat/content-moderation`; implementar por fatias com commits.
2. Suítes verdes: PHP unit (PHP 8.2 do LocalWP + `sodium` + `OPENSSL_CONF`), vitest
   app-parent + app-child.
3. Bump **v1.34.0** (feature = minor) + `GUARDKIDS_DB_VERSION` → 23.
4. PR → merge → tag → build dos 2 apps → `build-release-zip.php` → GitHub Release com zip.
5. Deploy SSH (`wp plugin install --force`) → **confirmar `db_version=23`** e — smoke crítico
   — que a **biblioteca do filho ainda devolve o conteúdo existente** (prova do grandfather).

## Fora de escopo (YAGNI / sprints futuras)

- Sugestões de conteúdo pela criança (fila de sugestão) — outra fonte de conteúdo.
- Validação automática de segurança de URL (SSRF-safe, allowlist de domínio, preview).
- Denúncia/flag de conteúdo já publicado além da revogação manual.
- Estado "rejeitado" separado / notas de moderação / auditoria multi-evento.
- Aprovação por-criança.
