# Design — Biblioteca Inteligente (Mundo Guardião, Sprint 2)

- **Data:** 2026-07-02
- **Status:** aprovado (aguardando review do spec)
- **Base:** guardkids-wp **v1.27.0 / DB v16** (Sprint 1 já em prod).
- **Escopo:** encher de funcionalidade a infra da Sprint 1. **Não altera nada existente** (estende as tabelas/controller/telas do módulo). Entrega completa em 1 release (v1.28.0).

## 1. Objetivo

Transformar o Mundo Guardião numa **biblioteca infantil**: os pais cadastram/gerenciam uma biblioteca da família (conteúdo com metadados), destacam recomendações por filho, e a criança navega/busca/filtra por idade/favorita/abre conteúdo, com histórico e analytics para os pais.

## 2. Decisões (brainstorming)
1. **Biblioteca da família + recomendações por-filho.** Uma biblioteca compartilhada; cada filho vê o compatível com a idade; recomendações são destaques ordenados que o pai fixa para um filho.
2. **Sprint 2 inteira, 1 release** (v1.28.0).
3. **Um `ContentController`** cobre admin + filho (métodos do filho sob `/child/library/*`, token).
4. **Tags** = VARCHAR comma-separated (LIKE), sem tabela de junção. **Nível** = string. **Idade** = `age_min`/`age_max` (o filho é auto-filtrado por `children.age`).

## 3. Schema — migração 017 (`GUARDKIDS_DB_VERSION` 16 → 17)

ALTERs via `$wpdb->query` + guarda idempotente (checar `SHOW COLUMNS` antes de cada `ADD`, pois `ADD COLUMN` não é idempotente e a migração pode re-rodar num estado parcial):

- **`content_items`** add: `age_min TINYINT UNSIGNED NOT NULL DEFAULT 0`, `age_max TINYINT UNSIGNED NOT NULL DEFAULT 99`, `estimated_minutes SMALLINT UNSIGNED NULL`, `level VARCHAR(20) NULL`, `tags VARCHAR(255) NULL`.
- **`content_recommendations`** add: `sort_order INT NOT NULL DEFAULT 0`.
- **`content_history`** add: `duration_seconds INT NOT NULL DEFAULT 0`.
- **Seed 12 categorias** em `content_categories` (idempotente — `INSERT ... ON DUPLICATE KEY UPDATE name=VALUES(name)` pela `slug` UNIQUE):

| slug | name | icon | sort_order |
|---|---|---|---|
| games | Jogos | sports_esports | 1 |
| learn | Aprender | school | 2 |
| create | Criar | palette | 3 |
| science | Ciências | science | 4 |
| portuguese | Português | menu_book | 5 |
| math | Matemática | calculate | 6 |
| english | Inglês | translate | 7 |
| videos | Vídeos | smart_display | 8 |
| reading | Leitura | auto_stories | 9 |
| school | Escola | backpack | 10 |
| coding | Programação | code | 11 |
| creativity | Criatividade | brush | 12 |

Helper de idempotência: uma função `addColumnIfMissing($wpdb, $table, $column, $definition)` que roda o `ALTER` só se a coluna não existir.

## 4. Modelo de idade

Faixas do briefing → `age_min`/`age_max`: 4-6 → (4,6); 7-9 → (7,9); 10-13 → (10,13); 14-16 → (14,16). O pai escolhe a faixa no form (dropdown); grava min/max. O filho vê só `age_min ≤ children.age ≤ age_max`. Sem seletor de idade no app-filho (implícito). Conteúdo sem faixa definida usa o default (0,99) = todos.

## 5. Repositories (estende a S1)

- **`ContentRepository`**: `findById(id)`, `update(id, data)`, `delete(id)`, `search(?category, ?term, ?childAge)` — WHERE por `category_id`, `LIKE title/tags`, e faixa de idade; `count()` (existe). `create` grava os campos novos.
- **`RecommendationRepository`**: `findByChildOrdered(childId)` (ORDER BY sort_order, id), `findById`, `update(id, {content_id?, note?})`, `delete(id)`, `reorder(int[] $ids)` (aplica sort_order pela posição), `nextSortOrder(childId)`.
- **`FavoriteRepository`**: `remove(childId, contentId)`, `contentIdsOf(childId)` (para marcar favoritos no browse), `add` (existe, idempotente por UNIQUE).
- **`HistoryRepository`**: `record(childId, contentId, action, durationSeconds)`; analytics: `mostAccessed(limit)` (GROUP BY content_id COUNT), `favoriteCategories(limit)` (JOIN items GROUP BY category), `timePerCategory()` (SUM duration JOIN items GROUP BY category), `lastAccess(childId, contentId)`.

## 6. REST — `ContentController`

**Pais (admin — `requireAdmin`):**
- `GET /content` (+ `?category=<id>&search=<t>`) — lista/filtra.
- `GET /content/{id}` — um item.
- `POST /content` — cria (body: title, description, categoryId, ageMin, ageMax, url, thumbnail, estimatedMinutes, level, tags). O form dos pais mapeia a faixa escolhida (4-6/7-9/10-13/14-16) para ageMin/ageMax antes de enviar.
- `PUT /content/{id}` — edita.
- `DELETE /content/{id}` — remove.
- `GET /content/categories`, `GET /content/summary` (existem).
- `GET /content/analytics` — `{mostAccessed:[{contentId,title,opens}], favoriteCategories:[{category,opens}], timePerCategory:[{category,minutes}]}`.
- `GET /content/recommendations?child_id=<id>` — ordenadas.
- `POST /content/recommendations` (existe) — cria (com `sort_order = next`).
- `PUT /content/recommendations/{id}` — edita nota/content.
- `DELETE /content/recommendations/{id}` — remove.
- `POST /content/recommendations/reorder` — body `{child_id, ids:[...]}` aplica ordem.

**Filho (token — `ChildAuth::requireToken`), sob `/child/library/*`:**
- `GET /child/library` (+ `?category&search`) — conteúdo age-filtered pela idade do token; cada item traz `favorited:bool`.
- `GET /child/library/categories` — categorias com contagem de conteúdo compatível.
- `GET /child/library/recommendations` — recomendações do pai para este filho (ordenadas), com o conteúdo embutido.
- `GET /child/library/favorites` — favoritos do filho (conteúdo).
- `POST /child/library/favorites` — body `{content_id}` (childId do token).
- `DELETE /child/library/favorites/{contentId}`.
- `POST /child/library/history` — body `{content_id, action, duration_seconds}` (action `open`|`close`).

O `childId` sempre sai do token. `POST /content/favorites` da S1 permanece registrado (alias legado, sem uso) — não removido pra não quebrar.

## 7. app-parent — gestão de conteúdo

`src/api/content.ts` ganha: `listContents(filters)`, `getContent(id)`, `createContent(data)`, `updateContent(id,data)`, `deleteContent(id)`, `getAnalytics()`, `listRecommendations(childId)`, `createRecommendation`, `updateRecommendation`, `deleteRecommendation`, `reorderRecommendations`. Tipos correspondentes.

**`ContentDashboard`** reescrito (mantém a rota `content`):
- Topo: **analytics** — 3 cards (Conteúdo mais acessado, Categorias favoritas, Tempo por categoria) com estados vazio/loading.
- **Lista de conteúdos** (tabela/cards) com busca + filtro por categoria; ações editar/excluir; botão **"Adicionar Conteúdo"** (ativo) abre `ContentForm`.
- **`ContentForm`** (dialog): título, descrição, categoria (dropdown das 12), faixa etária (dropdown 4-6/7-9/10-13/14-16), link, miniatura (URL), tempo estimado (min), nível (dropdown), tags (texto). Cria/edita.
- **`RecommendationManager`**: seletor de filho + lista ordenada com adicionar (escolher conteúdo + nota) / editar / remover / reordenar (↑/↓).

## 8. app-child — Biblioteca real

`src/api/content.ts` ganha: `browseLibrary(filters)`, `listLibraryCategories()`, `listChildRecommendations()`, `listChildFavorites()`, `addFavorite(contentId)` (→ `/child/library/favorites`), `removeFavorite(contentId)`, `recordHistory(contentId, action, duration)`. Tipos.

**`Mundo`** reescrito (mantém rota `mundo`, deixa de ser estático):
- **Busca** (input) filtra por título/categoria/tags.
- **Recomendados pelos pais** (carrossel/lista ordenada) quando houver.
- **Grid de categorias** (12, com contagem de conteúdo compatível) → tocar filtra.
- **Conteúdos** (age-filtered) em `ContentCard` com `FavoriteButton` wired (toggle add/remove). Tocar no card **abre o link** (`window.open` nova aba) e **registra histórico** (`open`; ao voltar/visibilitychange, `close` com `duration_seconds`).
- Seção/aba **Favoritos**.
- Reusa `toUrl` (do Browser) pra montar a URL de abertura.

## 9. UX — estados

- **`Skeleton`** (novo componente) para loading das listas.
- **Estado vazio** (reusa `EmptyState`): biblioteca sem conteúdo, categoria vazia, sem favoritos, sem recomendações.
- **Erro** (bloco com ícone + mensagem + retry) nas queries.
- **Busca sem resultados** ("Nada encontrado pra '<termo>'").
Aplicados na biblioteca do filho e nas listas dos pais.

## 10. Testes

**PHP (unit):**
- `ContentRepository`: search (por categoria/termo/idade), CRUD (create com campos novos, update, delete, findById).
- `RecommendationRepository`: ordered, update, delete, reorder, nextSortOrder.
- `FavoriteRepository`: remove, contentIdsOf.
- `HistoryRepository`: record com duration; mostAccessed, favoriteCategories, timePerCategory.
- `ContentController`: CRUD conteúdo (201/200/404), analytics shape, recomendações CRUD+reorder, e os endpoints `/child/library/*` (age-filter aplica a idade do token; favorito add/remove; history grava; 401 sem token).
- Migração 017 idempotente (helper addColumnIfMissing) — Unit + Integration real; seed das 12 categorias.

**vitest:**
- app-parent: `ContentDashboard` (analytics + lista + busca), `ContentForm` (submit cria/edita), `RecommendationManager` (add/reorder), `content` api.
- app-child: `Mundo` (browse/busca/categorias/favoritar/abrir→history com window.open mockado), `Skeleton`, `content` api.

## 11. Não-metas
Upload de miniatura (só URL); editor rico; moderação/aprovação; sync externo; conquistas/gamificação; premium gating; multi-família; paginação (listas simples nesta sprint).

## 12. Riscos
- **Tamanho** (~15-20 tasks) — mitigado por TDD incremental e commit por task.
- **Migração idempotente** — `ADD COLUMN` re-rodável só com o guard `addColumnIfMissing` (lição das migrações 003/007).
- **Nada existente alterado** — estende tabelas/controller; o alias legado `POST /content/favorites` fica.
