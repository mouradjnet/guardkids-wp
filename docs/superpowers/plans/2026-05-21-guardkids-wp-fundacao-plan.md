# GuardKids WP — Plano de Implementação

- **Data original:** 2026-05-21 (auth foundation — descartado)
- **Reescrita:** 2026-06-05 (retrospectiva + próximos passos)
- **Spec correspondente:** [`../specs/2026-05-21-guardkids-wp-fundacao-design.md`](../specs/2026-05-21-guardkids-wp-fundacao-design.md)

> **Histórico:** este plano, na versão original (2026-05-21), descrevia 19
> passos para uma "Fundação M0+M1" focada em JWT/pairing. O produto pivotou
> para controle parental direto. Este documento agora é uma retrospectiva
> do que foi entregue + roadmap do que falta.

---

## 1. Estado atual (branch `master`)

```
5a49542 feat: frontend public/ — apps PWA app-parent + app-child (Vite/React/TS)
1f1ac3f feat: Fase F — REST API guardkids/v1 (controllers + rotas)
15bd6bb feat: Fase B+C — schema, migrations, repositories e autoloader
7163b8e feat: bootstrap do plugin (Fase A, Passos 1-3)
41c7a7a chore: inicializa repositorio com spec e plano da Fundacao
```

## 2. Fases entregues

### ✅ Fase A — Bootstrap do plugin (`7163b8e`)
- `guardkids.php` com header WP, constantes e guard de `ABSPATH`.
- `includes/Plugin.php` singleton com `boot()`, hooks de ciclo de vida,
  carregamento de text domain.
- `composer.json` inicial (depois ajustado em B+C para `require-dev` only).

### ✅ Fase B — Banco de dados (`15bd6bb`)
- `database/MigrationRunner.php`: idempotente, lê `database/migrations/NNN_*.php`,
  compara com `guardkids_db_version`, executa pendentes via `dbDelta()`.
- `database/migrations/001_initial_schema.php`: cria as 5 tabelas
  (`children`, `requests`, `sites`, `categories`, `settings`).
- `uninstall.php`: drop das 5 tabelas + delete de opções persistentes.
- Ativação chama o runner; `plugins_loaded` cobre upgrade sem reativar.
- Seed das 6 categorias padrão no `onActivate` via `CategoryRepository::seed`.

### ✅ Fase C — Repositories (`15bd6bb`, mesmo commit que B)
- `database/Repository.php`: base abstrata com `findById`, `findAll`,
  `findWhere`, `insert`, `update`, `delete` — todas via `$wpdb->prepare()`.
- 5 concretos: `Child`, `Request` (com `findByStatus` + `decide`),
  `Site` (com `findByList`), `Category` (com `seed`),
  `Settings` (key-value JSON com `get`/`set`/`all`).
- Autoloader self-contained (`includes/Autoloader.php`, 3 roots PSR-4)
  agrupado no mesmo commit.

### ✅ Fase F — REST API (`1f1ac3f`)
- `api/RestApi.php` registra 9 rotas no namespace `guardkids/v1`.
- 5 controllers em `api/Controllers/`, finos, consumindo os repositories.
- Auth: `current_user_can('manage_options')` em todo `permission_callback`.
- Respostas JSON camelCase, erros `WP_Error` padronizados (404/409/422/500).
- `Plugin.php` passa a chamar `(new RestApi())->register()` no boot.

### ✅ Frontend `public/` (`5a49542`)
- Dois apps Vite + React + TS + Tailwind compartilhando Guardian Harmony.
- `app-parent`: SPA responsiva, 10 páginas + 11 componentes.
- `app-child`: PWA mobile-first, 5 páginas + 8 componentes + manifest.
- Mock data em `src/data/mockData.ts` — sem integração REST ainda.
- `.gitignore` cobre `dist/`, `node_modules/`, `vite.config.{js,d.ts}` e
  `*.tsbuildinfo`.

## 3. Roadmap — próximos passos

Ordenados por prioridade (cada item é um commit lógico independente).

### 1. Integração REST do frontend
- Substituir `mockData.ts` por chamadas `fetch('/wp-json/guardkids/v1/*')` com
  header `X-WP-Nonce`.
- Camada `src/api/` em cada app (client REST tipado).
- TanStack Query (já listado no briefing) para cache e revalidação.
- Tela de erro/loading em cada página.

### 2. Headers seguros no REST
- Filtro `rest_post_dispatch` adicionando `X-Content-Type-Options: nosniff`,
  `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: DENY`
  nas respostas do namespace `guardkids/v1`.

### 3. Testes
- PHPUnit no plugin: Repository (CRUD), MigrationRunner (idempotência),
  smoke tests dos 9 endpoints REST com `WP_REST_Server`.
- Vitest em cada app: componentes-chave (`ChildCard`, `ScreenTime`, etc.).
- `.wp-env.json` para subir o ambiente de teste.

### 4. PWA real no `app-child`
- `vite-plugin-pwa` + ícones (`icon-192.png`, `icon-512.png`).
- Service worker com `workbox` para cache offline da shell.
- Verificar instalabilidade via Chrome DevTools → Application → Manifest.

### 5. Roteamento + estado global no frontend
- `react-router-dom` quando passarmos de 1 tela ativa.
- `zustand` para estado compartilhado (conforme briefing).

### 6. Auth do `app-child`
- Decidir mecanismo: token de dispositivo emitido pelo `app-parent` (retoma
  parcialmente a ideia de pairing code, mas com escopo enxuto) ou cookie WP
  do responsável + escopo de "filho ativo".
- Tabela `sessions` (se necessária) entra em migration `002_*`.

### 7. Servir os builds via WP
- Rota WP que faz output do `dist/index.html` de cada app, com
  `<base href>` correto.
- Decisão pendente: hospedar em rotas WP ou tornar os PWAs apps separados
  servidos por um host estático e consumindo só a REST.

### 8. Deploy
- Pacote `.zip` do plugin (excluindo `node_modules/`, `dist/`, `.git/`,
  `docs/`, `tests/`).
- Pipeline de build automático para `public/app-*/dist/`.
- Hospedagem do site WordPress (alvo: VPS — alinhado com [[project-guardkids-pwa]]).

## 4. Comandos de desenvolvimento

```powershell
# Plugin
cd C:/Users/mysho/guardkids-wp
# (PHP fora do PATH global — usar o do LocalWP quando rodar PHPUnit)

# Apps frontend
cd public/app-parent ; pnpm install ; pnpm dev
cd public/app-child  ; pnpm install ; pnpm dev
pnpm build           # gera dist/
```

## 5. Premissas de ambiente

1. **PHP** — winget 8.1.34 instalado fora do PATH; usar PHP embutido do
   LocalWP (8.2/8.4) para lint e PHPUnit.
2. **Composer** — local por projeto (`composer.phar`); winget `Composer.Composer`
   não existe.
3. **pnpm** — disponível no PATH; `node_modules/` ignorado em cada app.
4. **Docker / `wp-env`** — não instalado; alternativa: testes unitários puros
   com mocks de `$wpdb`.
