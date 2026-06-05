# GuardKids WP — Spec de Design

- **Data original:** 2026-05-21 (auth foundation — descartado)
- **Reescrita:** 2026-06-05 (alinhamento com o código entregue)
- **Projeto:** GuardKids WP — plugin WordPress de controle parental + 2 PWAs
- **Status:** entregue até a camada REST; frontend em mock data (sem integração ainda)

> **Histórico:** este spec, na versão original (2026-05-21), descrevia uma
> "Fundação M0+M1" centrada em JWT/pairing/sessions com 4 tabelas (`users`,
> `children`, `sessions`, `settings`). O produto pivotou para controle parental
> direto, com auth via nonce do WordPress + `manage_options` e 5 tabelas de
> domínio. Esta reescrita reflete o código no commit `5a49542` (master).

---

## 1. Contexto

GuardKids WP é um plugin WordPress de **controle parental** acompanhado de dois
PWAs Vite/React/TS — um painel do responsável (`app-parent`) e um painel
infantil (`app-child`) — servidos por `public/` do próprio plugin. Toda a
configuração do controle parental (filhos, sites permitidos/bloqueados,
categorias bloqueadas, solicitações da criança, preferências) é persistida no
banco do WordPress e administrada via REST sob o namespace `guardkids/v1`.

## 2. Critérios de sucesso

O plugin está pronto na sua versão atual quando:

1. Ativa em **WP 6.4+ / PHP 8.1+** sem notice/warning.
2. O migration runner cria as 5 tabelas idempotentemente; reativar não duplica.
3. Seed inicial popula 6 categorias padrão (adult-content, gambling, etc.).
4. `uninstall.php` remove as 5 tabelas e as opções (`guardkids_db_version`,
   `guardkids_jwt_secret` — esta última herdada da fase auth descartada, ainda
   limpa por segurança).
5. As 9 rotas do namespace `guardkids/v1` respondem com `current_user_can('manage_options')`.
6. Usuários sem `manage_options` recebem **401**.
7. `app-parent` e `app-child` buildam (`pnpm build`) e a UI estática reproduz
   os mockups Stitch (Guardian Harmony design system).

## 3. Escopo

### 3.1 Dentro do escopo (estado atual)

- Plugin WP com autoloader PSR-4 **self-contained** (sem Composer em runtime).
- 5 tabelas de domínio + migration runner versionado + uninstall completo.
- REST `guardkids/v1`: CRUD de filhos, decisões de solicitações, gestão de
  sites (whitelist/blacklist), bloqueio por categorias e settings chave/valor.
- Frontend estático (mock data) dos dois PWAs com design system compartilhado.

### 3.2 Fora do escopo (até aqui)

- **Auth nativa do plugin** — sem JWT, sem login de criança, sem pairing code.
  Auth é 100% via cookie/nonce do WordPress + capability `manage_options` no
  responsável. Crianças **não** são usuários WP, e o `app-child` ainda não tem
  fluxo de autenticação (mock data por enquanto).
- Integração REST do frontend (`mockData.ts` → fetch real).
- Service worker / PWA offline real (manifest existe, SW não).
- Testes automatizados (PHPUnit / Vitest) — ainda não implementados.
- Páginas no `wp-admin` — `app-parent` é uma SPA externa em `public/`,
  acessada via URL direta do plugin; não há tela no admin do WP ainda.
- Multisite, multi-responsável, premium/licenciamento.

## 4. Arquitetura

### 4.1 Componentes

```
┌─────────────────────┐        ┌─────────────────────┐
│  app-parent (SPA)   │        │   app-child (PWA)   │
│  Vite + React + TS  │        │  Vite + React + TS  │
│  Tailwind + Stitch  │        │  Tailwind + Stitch  │
└──────────┬──────────┘        └──────────┬──────────┘
           │ (REST — não integrado ainda)              │
           └───────────────┬───────────────────────────┘
                           ▼
           ┌──────────────────────────────────┐
           │  REST guardkids/v1 (9 rotas)     │
           │  auth: WP nonce + manage_options │
           └──────────────┬───────────────────┘
                          ▼
           ┌──────────────────────────────────┐
           │  Controllers (api/Controllers/)  │
           │  Child · Request · Site · Category · Settings
           └──────────────┬───────────────────┘
                          ▼
           ┌──────────────────────────────────┐
           │  Repositories (database/)        │
           │  Repository (base) + 5 concretos │
           └──────────────┬───────────────────┘
                          ▼
                       $wpdb (MySQL)
```

### 4.2 Autenticação

Todas as rotas exigem `current_user_can('manage_options')` via
`permission_callback`. O nonce do WP é entregue ao cliente JS via
`wp_localize_script` (a ser feito quando integrarmos o frontend). Não há
sessão própria do plugin — a sessão é a do WordPress.

### 4.3 Autoloader

PSR-4 com 3 roots, sem Composer em runtime (`includes/Autoloader.php`):

| Prefixo | Diretório |
|---|---|
| `GuardKids\Api\` | `api/` |
| `GuardKids\Database\` | `database/` |
| `GuardKids\` | `includes/` |

Composer fica apenas em `require-dev` (PHPUnit, polyfills) — não é exigido em
runtime nem no servidor.

## 5. Banco de dados

Prefixo real = `$wpdb->prefix . 'guardkids_'`. Tabelas criadas em
`database/migrations/001_initial_schema.php`.

### `wp_guardkids_children`
| Coluna | Tipo | Notas |
|--------|------|-------|
| `id` | BIGINT UNSIGNED PK AI | |
| `slug` | VARCHAR(64) UNIQUE | identificador estável |
| `name` | VARCHAR(120) | |
| `age` | TINYINT UNSIGNED NULL | 0–21 |
| `avatar_url` | TEXT NULL | |
| `device` | VARCHAR(120) NULL | nome do dispositivo |
| `status` | VARCHAR(16) DEFAULT 'offline' | `online` \| `offline` |
| `used_minutes` | SMALLINT UNSIGNED DEFAULT 0 | uso de hoje |
| `limit_minutes` | SMALLINT UNSIGNED DEFAULT 60 | limite diário |
| `created_at`, `updated_at` | DATETIME | |

### `wp_guardkids_requests`
Solicitações da criança (mais tempo, liberar site).
| Coluna | Tipo | Notas |
|--------|------|-------|
| `id` | BIGINT UNSIGNED PK AI | |
| `child_id` | BIGINT UNSIGNED KEY | |
| `kind` | VARCHAR(32) | `extra_time` \| `unblock_site` \| etc. |
| `description`, `highlight` | VARCHAR(255) NULL | |
| `reason` | TEXT NULL | justificativa da criança |
| `status` | VARCHAR(16) DEFAULT 'pending' | `pending` \| `approved` \| `denied` |
| `decided_at`, `decided_by` | DATETIME / BIGINT NULL | |
| `created_at`, `updated_at` | DATETIME | |

### `wp_guardkids_sites`
Listas de sites permitidos/bloqueados.
| Coluna | Tipo | Notas |
|--------|------|-------|
| `id` | BIGINT UNSIGNED PK AI | |
| `domain` | VARCHAR(255) | |
| `category` | VARCHAR(64) NULL | slug de `categories` |
| `list_type` | VARCHAR(16) DEFAULT 'whitelist' | `whitelist` \| `blacklist` |
| `applies_to` | TEXT NULL | JSON: ids de filhos afetados |
| `created_at`, `updated_at` | DATETIME | |

### `wp_guardkids_categories`
Categorias de conteúdo bloqueáveis (com seed inicial).
| Coluna | Tipo | Notas |
|--------|------|-------|
| `id` | BIGINT UNSIGNED PK AI | |
| `slug` | VARCHAR(64) UNIQUE | |
| `name` | VARCHAR(120) | |
| `description` | TEXT NULL | |
| `icon` | VARCHAR(64) NULL | Material Symbol |
| `blocked` | TINYINT(1) DEFAULT 0 | flag global |
| `created_at`, `updated_at` | DATETIME | |

Seed na ativação: `adult-content`, `gambling`, `extreme-violence`,
`social-networks` (blocked=1) + `videos`, `online-games` (blocked=0).

### `wp_guardkids_settings`
Key-value store com payload JSON.
| Coluna | Tipo | Notas |
|--------|------|-------|
| `id` | BIGINT UNSIGNED PK AI | |
| `setting_key` | VARCHAR(120) UNIQUE | |
| `value` | LONGTEXT NULL | JSON encoded |
| `updated_at` | DATETIME | |

## 6. API REST

Namespace `wp-json/guardkids/v1/`. Toda rota exige
`current_user_can('manage_options')`.

| Método | Rota | Função |
|--------|------|--------|
| GET    | `/children` | Lista todos os filhos |
| POST   | `/children` | Cria um filho |
| GET    | `/children/{id}` | Detalhe |
| PATCH  | `/children/{id}` | Atualiza campos parciais |
| DELETE | `/children/{id}` | Remove |
| GET    | `/requests?status={pending|approved|denied|all}` | Lista solicitações |
| POST   | `/requests/{id}/approve` | Aprova (gravando `decided_by`) |
| POST   | `/requests/{id}/deny` | Nega |
| GET    | `/sites?list={whitelist|blacklist|all}` | Lista sites |
| POST   | `/sites` | Adiciona à lista |
| DELETE | `/sites/{id}` | Remove |
| GET    | `/categories` | Lista categorias com flag `blocked` |
| PATCH  | `/categories/{id}` | Atualiza `blocked` |
| GET    | `/settings` | Retorna todos os pares chave/valor decodificados |
| PATCH  | `/settings` | Faz merge dos pares enviados no JSON body |

Respostas em JSON **camelCase** (transformação no `toJson()` dos controllers).
Erros em formato `WP_Error` (`code`, `message`, `data.status`). Códigos HTTP
usados: 200, 201, 401, 404, 409 (request já decidido), 422 (validação), 500.

## 7. Segurança

- **Auth REST:** capability `manage_options` (admin do WP) em cada
  `permission_callback`. Não há `__return_true`.
- **Queries:** todas via `$wpdb->prepare()` (base `Repository`).
- **Sanitização:** `sanitize_text_field`, `sanitize_title`, `esc_url_raw`
  declarados nos `args` de cada rota.
- **Validação de enums:** `list_type`, `status` etc. validados via `enum` no
  schema do `register_rest_route`.
- **Uninstall:** drop das 5 tabelas + delete das opções persistentes.
- **Pendente:** headers seguros no `rest_post_dispatch`
  (`X-Content-Type-Options: nosniff` etc.) — ainda não implementados.

## 8. Estrutura de pastas (estado atual)

```
guardkids-wp/
├── guardkids.php                # bootstrap, constantes, registra autoloader
├── uninstall.php                # drop tabelas + delete opções
├── composer.json                # require-dev only (phpunit)
├── .gitignore
├── api/
│   ├── RestApi.php              # registra as 9 rotas
│   └── Controllers/
│       ├── ChildController.php
│       ├── RequestController.php
│       ├── SiteController.php
│       ├── CategoryController.php
│       └── SettingsController.php
├── includes/
│   ├── Autoloader.php           # PSR-4 self-contained
│   └── Plugin.php               # singleton: hooks, migrations, seed, REST
├── database/
│   ├── MigrationRunner.php
│   ├── Repository.php           # base CRUD com $wpdb->prepare
│   ├── {Child,Request,Site,Category,Settings}Repository.php
│   └── migrations/
│       └── 001_initial_schema.php
├── public/
│   ├── README.md
│   ├── app-parent/              # SPA Vite/React/TS (10 páginas)
│   └── app-child/               # PWA Vite/React/TS (5 páginas)
└── docs/superpowers/{specs,plans}/
```

## 9. Frontend

Dois apps Vite + React 18 + TypeScript + Tailwind, design system **Guardian
Harmony** (Deep Blue + Soft Mint Green + Warm Orange, fontes Montserrat/Inter,
glassmorphic). Mock data em `src/data/mockData.ts` — sem integração REST
ainda.

**`app-parent`** (SPA responsiva, sidebar desktop + bottom nav mobile):
páginas Dashboard, Children, Approvals, SitesRules, TimeLimits, Reports,
Settings, License, Upgrade (10 no total). 11 componentes compartilhados
(ChildCard, PendingRequests, RecentBlocks, SideNav, etc.).

**`app-child`** (PWA mobile-first instalável): páginas Home, Browser,
Requests, Blocked, Alerts (5 no total). 8 componentes (Header, BottomNav,
ScreenTime, QuickActions, etc.) + `manifest.webmanifest`. Ícones PWA
(192/512) ainda não adicionados; service worker não instalado.

## 10. Premissas e decisões

1. **Auth via WP nonce + capability** — descartado o JWT/pairing do design
   original. Crianças **não** são usuários WP; o `app-child` será
   autenticado quando integrarmos o frontend (provavelmente via token
   de dispositivo emitido pelo `app-parent`).
2. **Plugin sem dependências de runtime** — autoloader self-contained,
   `composer.json` apenas em `require-dev`.
3. **Frontend em `public/`** servido pelo plugin (não pelo wp-admin) —
   decisão de UX para experiência app-like; rotas WP servirão os builds.
4. **Single-site, single-language (pt-BR)** — multisite e i18n completo
   ficam para depois.

## 11. Próximos passos sugeridos

1. **Integração REST do frontend** — substituir `mockData.ts` por
   `fetch('/wp-json/guardkids/v1/*')` com nonce do WP.
2. **Ícones PWA + service worker** no `app-child` (`vite-plugin-pwa`).
3. **Testes** — PHPUnit nos Repositories + smoke tests dos endpoints REST;
   Vitest nos PWAs.
4. **Headers seguros** no `rest_post_dispatch` (Seção 7).
5. **Roteamento React** — quando o frontend crescer além de 1 tela ativa,
   adicionar `react-router-dom` e estado global (`zustand` é a aposta no
   briefing original).
6. **Auth do `app-child`** — fluxo de pareamento dispositivo↔filho (a
   antiga ideia de pairing code pode ser retomada aqui, agora com escopo
   muito mais focado).
