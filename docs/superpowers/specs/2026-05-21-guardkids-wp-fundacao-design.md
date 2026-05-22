# GuardKids WP — Spec de Design: Fundação (M0 + M1)

- **Data:** 2026-05-21
- **Projeto:** GuardKids WP — plugin WordPress premium de controle parental web + PWA
- **Milestone:** M0 (Fundação do plugin) + M1 (Auth + esqueleto da API REST)
- **Status:** Aprovado para virar plano de implementação

---

## 1. Contexto

GuardKids WP é um produto novo e independente: um plugin WordPress premium que
funciona em conjunto com um PWA mobile-first de controle parental **web** (painel
dos pais + painel infantil + navegador seguro). O produto inteiro foi decomposto
em 9 milestones:

| # | Milestone | Resumo |
|---|-----------|--------|
| **M0** | Fundação | Bootstrap do plugin, migrations, tabelas |
| **M1** | Auth + esqueleto REST | JWT, login pais/crianças, middleware |
| M2 | PWA shell | Manifest, service worker, build Vite/React |
| M3 | Painel dos Pais | Gestão de filhos, dashboard, wp-admin |
| M4 | Regras & Sites | Whitelist/blacklist, categorias, horários |
| M5 | Painel Infantil | Rotina, tempo restante, tela de bloqueio |
| M6 | Navegador Infantil | Navegação curada/filtrada |
| M7 | Uso + Relatórios + Aprovações + Push | Tracking e notificações |
| M8 | Premium / Licença | Feature gates e licenciamento |

**Este spec cobre apenas M0 + M1.** Cada milestone seguinte terá seu próprio
ciclo spec → plano → implementação.

---

## 2. Objetivo e critérios de sucesso

Entregar a base do plugin: um plugin que ativa no WordPress, cria seu schema de
banco e expõe uma **API REST autenticada e segura** — verificável de ponta a
ponta sem nenhum frontend (testes via cliente REST e PHPUnit).

A Fundação está pronta quando **todos** os critérios abaixo passam:

1. O plugin ativa e desativa em **WP 6.4+ / PHP 8.1+** sem nenhum notice/warning.
2. O migration runner cria as 4 tabelas da Fundação; reativar o plugin é
   idempotente (não recria nem duplica nada).
3. `uninstall.php` remove todas as tabelas e opções do plugin.
4. `POST /auth/login` com credenciais válidas de um pai retorna access token +
   refresh token.
5. `GET /auth/me` com access token válido retorna o perfil do sujeito logado.
6. Um pai autenticado gera um código de pareamento para um filho via
   `POST /children/{id}/pairing-code`.
7. `POST /auth/pair` com código válido retorna access token + device token da
   criança; o código é de uso único e expira.
8. `POST /auth/refresh` rotaciona os tokens; `POST /auth/logout` e
   `DELETE /sessions/{id}` revogam a sessão.
9. Qualquer rota protegida rejeita token ausente, inválido ou expirado com **401**.
10. Rate limiting responde **429** ao brute-force em `login` e `pair`.
11. Testes PHPUnit cobrem `AuthService` e os repositories, rodando via `wp-env`.

---

## 3. Escopo

### 3.1. Dentro do escopo (M0 + M1)

- Bootstrap do plugin (`guardkids.php`), constantes, autoload PSR-4 via Composer.
- Hooks de ativação/desativação e `uninstall.php`.
- Sistema de migrations versionado + 4 tabelas da Fundação.
- Namespace REST `guardkids/v1` e esqueleto de roteamento.
- Autenticação completa de pais e crianças (JWT + refresh/device tokens).
- Middleware de segurança: autenticação, rate limiting, sanitização, headers.
- Endpoints de auth e CRUD mínimo de filhos (necessário para o pareamento).
- Testes PHPUnit da camada de auth e de persistência.

### 3.2. Fora do escopo (milestones futuros)

- **Sem React / Vite / PWA** — toda a M2. Esta entrega é backend puro.
- As tabelas `rules`, `sites`, `usage`, `requests`, `notifications`, `licenses`
  **não** são criadas agora — cada uma vem na migration do seu milestone. É esse
  o propósito do migration runner versionado: não criar schema especulativo.
- Páginas no wp-admin — M3.
- Múltiplos responsáveis por família, planos premium e feature gates — M8.
  As colunas `plan`/`role` **não** entram nas tabelas agora; serão adicionadas
  por migration quando o milestone que as usa for implementado.
- Multisite: **não suportado**. O alvo é instalação WordPress single-site.

---

## 4. Decisão de arquitetura — Autenticação

Modelo **unificado de tokens** para pais e crianças, com a mesma mecânica de
refresh, diferindo apenas no fluxo de obtenção do primeiro token e na duração.

### 4.1. Tokens

- **Access token** — JWT assinado (HS256) com a biblioteca `firebase/php-jwt`
  (padrão de mercado, MIT), instalada via Composer. Validade **60 minutos**.
  Claims: `iss` (URL do site), `sub` (id do sujeito), `typ` (`guardian`|`child`),
  `sid` (id da sessão), `iat`, `exp`, `jti`.
- **Refresh token** — string aleatória de 32 bytes (base64url). Devolvido em
  texto claro ao cliente **uma única vez**; persistido apenas como hash
  **SHA-256** na tabela `sessions`. Rotacionado a cada `/auth/refresh`.
- **Segredo JWT** — usa a constante `GUARDKIDS_JWT_SECRET` definida em
  `wp-config.php` se existir; caso contrário, gera um segredo de 256 bits na
  ativação e o guarda na opção `guardkids_jwt_secret` (não-autoload).

### 4.2. Fluxo do pai (responsável)

1. `POST /auth/login` com `username`/`email` + `password`.
2. O serviço valida via `wp_authenticate()`.
3. Garante (cria se não existir) o perfil em `wp_guardkids_users` para aquele
   `wp_users.ID`.
4. Cria uma sessão (`subject_type = guardian`) e devolve access + refresh token.
   Refresh token do pai: validade **30 dias**.

### 4.3. Fluxo da criança (pareamento único)

1. O pai autenticado chama `POST /children/{id}/pairing-code`.
2. O serviço gera um **código numérico de 6 dígitos**, cria uma sessão
   `pending` (`subject_type = child`), guarda o **hash** do código e uma
   expiração de **15 minutos**. Devolve o código em texto claro ao pai.
3. No dispositivo da criança, `POST /auth/pair` envia o código.
4. O serviço valida o hash, confere expiração e uso único, promove a sessão
   para `active` e devolve access token + **device token** (o refresh token da
   criança), com validade **180 dias**.
5. A criança não digita mais nada nesse dispositivo. O pai pode revogar via
   `DELETE /sessions/{id}`.

### 4.4. Revogação

Toda sessão é uma linha em `wp_guardkids_sessions` com `status`. Revogar =
marcar `status = revoked`. O middleware de autenticação rejeita qualquer access
token cujo `sid` aponte para sessão não-`active` ou expirada — assim o JWT curto
deixa de ser aceito em no máximo 60 minutos mesmo antes de expirar.

---

## 5. Banco de dados

### 5.1. Migration runner

- Arquivos em `database/migrations/`, nomeados `NNN_descricao.php` (ex.:
  `001_initial_schema.php`). Cada migration expõe `up()` idempotente.
- A opção `guardkids_db_version` guarda o número da última migration aplicada.
- O runner roda na **ativação** e em `plugins_loaded` (para cobrir atualizações
  do plugin sem reativação). Aplica em ordem todas as migrations pendentes.
- A criação de tabelas usa `dbDelta()` com o `charset_collate` do WordPress.

### 5.2. Tabelas da Fundação (`001_initial_schema`)

Prefixo real = `$wpdb->prefix . 'guardkids_'`. Abaixo com prefixo padrão `wp_`.

**`wp_guardkids_users`** — perfil do responsável (liga a `wp_users`):

| Coluna | Tipo | Notas |
|--------|------|-------|
| `id` | BIGINT UNSIGNED PK AI | |
| `wp_user_id` | BIGINT UNSIGNED, UNIQUE | referencia `wp_users.ID` |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |

**`wp_guardkids_children`** — filhos de um responsável:

| Coluna | Tipo | Notas |
|--------|------|-------|
| `id` | BIGINT UNSIGNED PK AI | |
| `guardian_id` | BIGINT UNSIGNED, KEY | → `guardkids_users.id` |
| `name` | VARCHAR(100) | |
| `avatar` | VARCHAR(50) NULL | chave de avatar pré-definido (sem upload nesta fase) |
| `birth_year` | SMALLINT UNSIGNED NULL | |
| `status` | VARCHAR(20) DEFAULT 'active' | `active` \| `suspended` |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |

**`wp_guardkids_sessions`** — sessões de pais e dispositivos de crianças
(*tabela nova, não listada no briefing original; necessária para refresh tokens
revogáveis e para o pareamento*):

| Coluna | Tipo | Notas |
|--------|------|-------|
| `id` | BIGINT UNSIGNED PK AI | |
| `subject_type` | VARCHAR(10) | `guardian` \| `child` |
| `subject_id` | BIGINT UNSIGNED, KEY (com subject_type) | id em users ou children |
| `refresh_token_hash` | CHAR(64) NULL, KEY | SHA-256; NULL enquanto pareamento pendente |
| `pairing_code_hash` | CHAR(64) NULL | SHA-256; só para pareamento de criança pendente |
| `pairing_expires_at` | DATETIME NULL | |
| `label` | VARCHAR(100) NULL | ex.: "Celular da Ana" |
| `status` | VARCHAR(20) DEFAULT 'pending' | `pending` \| `active` \| `revoked` |
| `expires_at` | DATETIME | |
| `last_seen_at` | DATETIME NULL | |
| `created_at` | DATETIME | |

**`wp_guardkids_settings`** — configurações chave/valor:

| Coluna | Tipo | Notas |
|--------|------|-------|
| `id` | BIGINT UNSIGNED PK AI | |
| `scope` | VARCHAR(10) | `global` \| `guardian` \| `child` |
| `scope_id` | BIGINT UNSIGNED DEFAULT 0 | 0 para `global` |
| `setting_key` | VARCHAR(100) | |
| `setting_value` | LONGTEXT NULL | JSON |
| `updated_at` | DATETIME | |
| | UNIQUE (`scope`,`scope_id`,`setting_key`) | |

> As foreign keys são lógicas (aplicadas no código), não constraints SQL —
> padrão do WordPress, que usa MyISAM/InnoDB sem garantir FKs entre tabelas.

### 5.3. WP Cron

Um evento agendado `guardkids_cleanup` (diário) apaga sessões expiradas ou
`revoked` antigas e códigos de pareamento vencidos.

---

## 6. API REST

Namespace: `wp-json/guardkids/v1/`.

| Método | Rota | Auth | Função |
|--------|------|------|--------|
| POST | `/auth/login` | pública (rate-limited) | Login do pai |
| POST | `/auth/pair` | pública (rate-limited) | Pareamento da criança |
| POST | `/auth/refresh` | refresh token | Rotaciona os tokens |
| POST | `/auth/logout` | access token | Revoga a sessão atual |
| GET | `/auth/me` | access token | Perfil do sujeito logado |
| GET | `/children` | access token (pai) | Lista os filhos do responsável |
| POST | `/children` | access token (pai) | Cria um filho |
| POST | `/children/{id}/pairing-code` | access token (pai) | Gera código de pareamento |
| DELETE | `/sessions/{id}` | access token (pai) | Revoga uma sessão/dispositivo |

Respostas de erro padronizadas: `{ "code", "message", "data": { "status" } }`,
seguindo o formato `WP_Error` do core. Códigos HTTP: 200/201, 400, 401, 403,
404, 409 (código já usado), 422 (validação), 429 (rate limit).

### 6.1. Camadas (Clean Architecture / SOLID)

```
Controller (api/controllers/)   → fino; traduz HTTP ↔ Service
        │
Service (includes/auth/)        → regra de negócio (AuthService, ChildService)
        │
Repository                      → encapsula $wpdb (User/Child/SessionRepository)
        │
WordPress ($wpdb, wp_users)
```

- **Validators** (`api/validators/`) — validam e sanitizam o payload antes do
  controller chamar o service.
- **Middleware** (`api/middleware/`) — `permission_callback` reais:
  `AuthMiddleware` (valida JWT e carrega o sujeito), `RateLimitMiddleware`.
- Autoload **PSR-4**, namespace raiz `GuardKids\`, via `composer.json`.
- O `vendor/` é versionado/empacotado no plugin distribuído (sem passo de
  Composer no servidor do cliente).

---

## 7. Segurança

- **JWT Bearer** em todas as rotas protegidas (header `Authorization: Bearer`).
- **Nonce WP** previsto para chamadas cookie-autenticadas do wp-admin — entra
  em uso na M3; o middleware já é desenhado para suportá-lo.
- **Rate limiting** por IP via transients: máx. **5 tentativas / 15 min** em
  `/auth/login` e `/auth/pair`; excedido → **429**. A chave do transient usa
  hash do IP (não guarda IP em claro).
- **Sanitização** de todo input via funções `sanitize_*` do WP nos validators;
  todas as queries usam `$wpdb->prepare()`.
- **`permission_callback`** real e específico em cada rota (nunca
  `__return_true` em rota protegida).
- **Headers seguros** nas respostas REST do plugin (`X-Content-Type-Options:
  nosniff`, `Referrer-Policy`, `X-Frame-Options` onde aplicável) via filtro
  `rest_post_dispatch`.
- Senhas nunca logadas; tokens só trafegam em HTTPS (documentado como
  pré-requisito de instalação).
- **Não implementar** spyware, keylogger ou captura oculta — explicitamente
  fora do produto.

---

## 8. Estrutura de pastas (criada na Fundação)

```
guardkids-wp/
├── guardkids.php              # bootstrap, constantes, autoload, hooks
├── uninstall.php              # remove tabelas + opções
├── composer.json              # firebase/php-jwt + autoload PSR-4
├── api/
│   ├── routes/                # registro das rotas REST
│   ├── controllers/           # AuthController, ChildController, SessionController
│   ├── middleware/            # AuthMiddleware, RateLimitMiddleware
│   └── validators/            # validação/sanitização de payload
├── includes/
│   ├── auth/                  # AuthService, JwtService, tokens
│   └── security/              # rate limiting, headers
├── database/
│   ├── migrations/            # 001_initial_schema.php + runner
│   └── schema/                # documentação do schema
├── languages/                 # .pot (text domain "guardkids")
└── docs/
    └── superpowers/specs/     # este documento
```

As pastas `admin/`, `public/`, `premium/` e os repositories de domínios futuros
não são criadas agora — entram nos seus respectivos milestones.

---

## 9. Testes

- Ambiente: **`wp-env`** (Docker) com a suíte de testes oficial do WordPress.
- **PHPUnit** com testes de integração cobrindo:
  - Migration runner: cria as tabelas; é idempotente; `uninstall` limpa tudo.
  - `AuthService`: login do pai, pareamento da criança, refresh, logout,
    revogação, rejeição de token expirado/inválido.
  - Repositories: CRUD básico de users, children e sessions.
  - Rate limiting: bloqueio após N tentativas.
- Cada critério de sucesso da Seção 2 tem ao menos um teste correspondente.

---

## 10. Premissas e decisões tomadas

1. Pais são usuários WordPress reais; crianças **não** são usuários WP.
2. Adicionada a 10ª tabela `wp_guardkids_sessions` (fora do briefing original),
   por ser indispensável para tokens revogáveis e pareamento.
3. As 5 tabelas de domínios futuros não entram nesta migration.
4. Colunas de plano/papel (premium, multi-responsável) ficam para a M8.
5. Sem frontend nesta entrega — verificação por cliente REST e PHPUnit.
6. Alvo single-site; multisite fora de escopo.
7. HTTPS é pré-requisito de produção (documentado, não forçado em código).

## 11. Riscos

- **`wp-env` exige Docker** na máquina de desenvolvimento (Windows). Mitigação:
  documentar o setup; testes unitários puros de `AuthService` podem rodar com
  mocks caso o Docker não esteja disponível.
- **Empacotar `vendor/`** aumenta o tamanho do plugin. Aceitável — `php-jwt` é
  uma dependência pequena e é o padrão para plugins distribuídos.
- **Segredo JWT em `wp_options`** é menos seguro que em `wp-config.php`. Por
  isso a constante `GUARDKIDS_JWT_SECRET` tem precedência e é a forma
  recomendada na documentação.
