# Design — Mundo Guardião, Sprint 1 (infraestrutura)

- **Data:** 2026-07-02
- **Status:** aprovado (aguardando review do spec)
- **Base:** guardkids-wp **v1.26.0** (o cabeçalho "v1.5.x" do briefing original é resíduo de template; trabalhamos sobre o código atual).
- **Escopo:** SÓ INFRAESTRUTURA. Sem curadoria de conteúdo, sem engine de recomendação, sem sync, sem gamificação real. Nada de funcionalidade existente é alterado.

## 1. Objetivo

Criar a fundação do módulo "Mundo Guardião": banco, REST, repositórios, navegação nos dois apps e as telas/vazias/componentes. Conteúdo real vem em sprints seguintes.

## 2. Decisões resolvidas (brainstorming)
1. **Tabelas com prefixo `content_`** (a `guardkids_categories` já existe pra categorias de sites — não pode colidir).
2. **Repositories** (padrão do projeto), não "Services".
3. **Endpoints funcionais** (CRUD básico real: GET lê das tabelas vazias → `[]`; POST insere de verdade). A "lógica" adiada é curadoria/sync/algoritmo, não o plumbing.
4. **Um `ContentController`** agrupa os endpoints do módulo (coesão; evita colidir com o `CategoryController` de sites).

## 3. Banco de dados — migração 016 (`GUARDKIDS_DB_VERSION` 15 → 16)

`CREATE TABLE IF NOT EXISTS` via `$wpdb->query` (padrão das migrações 013-015).

**`wp_guardkids_content_categories`** — seções/categorias do Mundo.
| coluna | tipo |
|---|---|
| id | BIGINT UNSIGNED PK AI |
| slug | VARCHAR(64) NOT NULL, `UNIQUE` |
| name | VARCHAR(120) NOT NULL |
| icon | VARCHAR(48) NULL |
| description | VARCHAR(255) NULL |
| sort_order | INT NOT NULL DEFAULT 0 |
| created_at | DATETIME NOT NULL |

**`wp_guardkids_content_items`** — itens de conteúdo.
| id PK | category_id BIGINT (`KEY`) | title VARCHAR(160) | description VARCHAR(255) NULL | url VARCHAR(512) NULL | type VARCHAR(32) NOT NULL DEFAULT 'link' | thumbnail VARCHAR(512) NULL | created_at DATETIME |

**`wp_guardkids_content_favorites`** — favoritos do filho.
| id PK | child_id BIGINT | content_id BIGINT | created_at DATETIME | `UNIQUE(child_id, content_id)`, `KEY(child_id)` |

**`wp_guardkids_content_recommendations`** — recomendações do responsável.
| id PK | child_id BIGINT | content_id BIGINT | guardian_id BIGINT NULL | note VARCHAR(255) NULL | created_at DATETIME | `KEY(child_id)` |

**`wp_guardkids_content_history`** — histórico de acesso (append-only).
| id PK | child_id BIGINT | content_id BIGINT | action VARCHAR(32) NOT NULL | created_at DATETIME | `KEY(child_id, created_at)` |

`uninstall.php` passa a dropar as 5 tabelas.

## 4. Repositories (`database/`)

Todos estendem `Repository` (base com `$wpdb->prepare`). CRUD funcional mínimo:
- **`ContentCategoryRepository`** (`content_categories`): `all()` (ordenado por sort_order), `count()`.
- **`ContentRepository`** (`content_items`): `all()`, `count()`, `findByCategory(id)`.
- **`FavoriteRepository`** (`content_favorites`): `findByChild(childId)`, `count()`, `add(childId, contentId)` (idempotente por UNIQUE).
- **`RecommendationRepository`** (`content_recommendations`): `all()`, `count()`, `add(childId, contentId, guardianId, note)`.
- **`HistoryRepository`** (`content_history`): `add(childId, contentId, action)`, `count()`.

Convenção: tabelas append-mostly sem `updated_at` → `insert` próprio setando só `created_at` (padrão do `LocationRepository`/`NotificationRepository`).

## 5. REST — `ContentController` (`api/Controllers/`)

Rotas em `RestApi.php`. Auth: admin = `requireAdmin` (nonce + `manage_options`, padrão dos controllers de pais); child = `ChildAuth::requireToken()`.

| Método/rota | Auth | Comportamento Sprint 1 |
|---|---|---|
| `GET /content/categories` | admin | `array<{id,slug,name,icon,description}>` (vazio→[]) |
| `GET /content` | admin | `array<{id,categoryId,title,description,url,type,thumbnail}>` |
| `GET /content/favorites` | admin | `array<{id,childId,contentId,createdAt}>` |
| `GET /content/recommendations` | admin | `array<{id,childId,contentId,note,createdAt}>` |
| `POST /content/recommendations` | admin | insere; body `{child_id, content_id, note?}` → 201 |
| `POST /content/favorites` | **token filho** | insere (childId do token); body `{content_id}` → 201 |
| `GET /content/summary` | admin | `{contents:int, categories:int, favorites:int, recommendations:int, lastSync:null}` — alimenta o dashboard |

`lastSync` é `null` nesta sprint (não há sync); o campo existe pra UI mostrar "Nunca".

## 6. App-pais — "Conteúdo Infantil"

- **Nav:** novo item em `data/mockData.ts` `navItems`: `{ id: 'content', label: 'Conteúdo Infantil', icon: 'auto_stories' }`, inserido logo após `sites-rules`. `PageId` ganha `'content'`. `App.tsx` roteia `content` → `ContentDashboard`. `roleAccess` libera pra admin.
- **Página `pages/ContentDashboard.tsx`:** `useQuery(['content','summary'])` → 5 métricas (Conteúdos, Categorias, Favoritos, Recomendações — 0; Última sincronização — "Nunca"); bloco vazio "Nenhum conteúdo cadastrado"; botão **"Adicionar Conteúdo" `disabled`** (sem implementação).
- **api:** `src/api/content.ts` → `getContentSummary()`, `listContentCategories()`, `listContents()`, `listRecommendations()`, `listFavorites()`, `createRecommendation()`. Tipos em `src/api/types.ts`.

## 7. App-filho — aba "🌎 Mundo"

- **Nav:** `PageId` ganha `'mundo'`. `BottomNav` vira **6 itens** na ordem Início, **Mundo**, Navegar, Localização, Pedidos, Alertas (`{ id:'mundo', label:'Mundo', icon:'public' }` como 2º). `App.tsx` roteia `mundo` → `Mundo`. `Header` mapeia título "Mundo".
- **Página `pages/Mundo.tsx`:** grid de 7 `CategoryCard` estáticos:
  Jogos (`sports_esports`), Aprender (`school`), Criar (`palette`), Desafios (`emoji_events`), Favoritos (`favorite`), Indicados pelos Pais (`recommend`), Conquistas (`military_tech`) — cada card com ícone, descrição, contador (0) e mini estado-vazio; no rodapé, `EmptyState` "Seu mundo será preenchido pelo papai". **Estático** nesta sprint (tabelas vazias, sem fetch).

## 8. Componentes React + tipos + hooks (app-filho)

- **Componentes (`src/components/`):** `CategoryCard` (ícone+nome+descrição+contador+estado vazio), `ContentCard` (item de conteúdo — placeholder), `FavoriteButton` (coração toggle — visual, sem wire), `RecommendationCard` (card de recomendação — placeholder), `EmptyState` (ilustração + mensagem, reutilizável).
- **Tipos (`src/api/types.ts`):** `ContentCategory`, `Content`, `Favorite`, `Recommendation`.
- **api (`src/api/content.ts`):** apenas `addFavorite(contentId)` → `POST /content/favorites` (token do filho). As LEITURAS são admin (vivem no app-pais); a tela Mundo é estática nesta sprint e as leituras do filho (endpoints token `/child/...`) ficam pra sprint futura. `addFavorite` existe e é testada, mas ainda não é wired na UI (building block).

## 9. Testes

**PHP (unit):**
- 5 Repositories (CRUD básico: insert/count/find via fake `$wpdb`).
- `ContentController`: cada GET retorna array; `GET /content/summary` conta certo; `POST /content/recommendations` (admin) insere; `POST /content/favorites` (token) usa childId do token + 401 sem token; admin endpoints 403 sem manage_options.
- Migração 016 idempotente (Unit + Integration real).

**vitest:**
- app-child: `Mundo` (7 cards + textos + "Seu mundo será preenchido pelo papai"), `CategoryCard`, `EmptyState`, `BottomNav` (item Mundo presente), `content` api (paths).
- app-parent: `ContentDashboard` (5 métricas zeradas, "Nunca"/"Nenhum conteúdo", botão disabled), `SideNav`/nav (item "Conteúdo Infantil"), `content` api.

## 10. Não-metas (Sprint 1)
Cadastro/curadoria de conteúdo; engine/algoritmo de recomendação; sincronização (lastSync fica null); conquistas/gamificação reais; wire do FavoriteButton e da tela Mundo aos dados; **endpoints token de leitura pro filho** (`/child/...` — o Mundo é estático agora); premium gating; histórico consumido em UI.

## 11. Riscos
- **BottomNav com 6 itens** fica apertada no mobile (era 5) — decisão explícita do briefing; validar visual no build.
- Nenhuma alteração em tabelas/endpoints/componentes existentes (regra dura do briefing) — o prefixo `content_` e o `ContentController` isolam o módulo.
