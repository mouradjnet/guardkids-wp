# GuardKids WP — Plano de Implementação: Fundação (M0 + M1)

- **Data:** 2026-05-21
- **Spec de origem:** [`../specs/2026-05-21-guardkids-wp-fundacao-design.md`](../specs/2026-05-21-guardkids-wp-fundacao-design.md)
- **Escopo:** backend puro — plugin ativável + API REST autenticada. Sem frontend.

---

## Como usar este plano

Os passos são executados **em ordem**. Cada passo tem um critério de
**verificação** objetivo — não avance enquanto ele não passar. Commitar ao
final de cada passo lógico (após o `git init` do Passo 0).

Stack-alvo: **WordPress 6.4+ / PHP 8.1+**, single-site. Ferramentas:
**Composer**, **`wp-env`** (Docker) para testes, **PHPUnit**.

> A skill `writing-plans` não está instalada; este plano foi escrito
> diretamente, no mesmo formato (fases ordenadas + verificação por passo).

---

## Fase 0 — Repositório

### Passo 0 — Inicializar git *(aguardando OK do usuário)*
- `git init` em `guardkids-wp/`; criar `.gitignore` (`vendor/` fica fora do
  ignore — é empacotado; ignorar `node_modules/`, `.idea/`, `*.log`, artefatos
  de teste do `wp-env`).
- Commit inicial com o spec e este plano já presentes.
- **Verificação:** `git status` limpo após o commit; spec e plano versionados.

---

## Fase A — Bootstrap do plugin

### Passo 1 — `composer.json` e autoload
- Criar `composer.json`: autoload **PSR-4** `GuardKids\` → `src/` (ver nota
  abaixo), `require` de `firebase/php-jwt` (`^6.0`), `require-dev` de
  `phpunit/phpunit` e `yoast/phpunit-polyfills`.
- Decisão de layout: o spec lista pastas funcionais (`api/`, `includes/`,
  `database/`). Para o PSR-4 funcionar limpo, o **namespace raiz `GuardKids\`
  mapeia para a pasta `includes/`**, e `api/` e `database/` ficam como
  subnamespaces (`GuardKids\Api\…`, `GuardKids\Database\…`) movidos para dentro
  de `includes/` OU declarados como múltiplos roots PSR-4. Adotar **múltiplos
  roots PSR-4** (`GuardKids\Api\` → `api/`, `GuardKids\Database\` → `database/`,
  `GuardKids\` → `includes/`) para preservar a árvore de pastas do spec.
- Rodar `composer install`.
- **Verificação:** `vendor/autoload.php` existe; `composer dump-autoload`
  roda sem erro.

### Passo 2 — `guardkids.php` (arquivo principal)
- Header do plugin (Name, Version `0.1.0`, Requires PHP `8.1`, Requires at
  least `6.4`, Text Domain `guardkids`, License GPL-2.0+).
- Guard `defined('ABSPATH') || exit;`.
- Definir constantes: `GUARDKIDS_VERSION`, `GUARDKIDS_FILE`, `GUARDKIDS_DIR`,
  `GUARDKIDS_URL`, `GUARDKIDS_DB_VERSION`.
- Incluir `vendor/autoload.php` (com checagem de existência).
- Instanciar `GuardKids\Plugin` e chamar `boot()`.
- **Verificação:** o plugin aparece em **Plugins** no wp-admin sem fatal error.

### Passo 3 — Classe `GuardKids\Plugin` (bootstrap)
- `includes/Plugin.php`: singleton; método `boot()` registra hooks
  (`init`, `rest_api_init`, `plugins_loaded`); registra
  `register_activation_hook` / `register_deactivation_hook`.
- Carrega text domain (`load_plugin_textdomain`).
- **Verificação:** plugin **ativa e desativa** sem nenhum notice/warning
  (testar com `WP_DEBUG = true`).

---

## Fase B — Banco de dados

### Passo 4 — Migration runner
- `database/MigrationRunner.php` (`GuardKids\Database\MigrationRunner`): lê os
  arquivos `database/migrations/NNN_*.php` em ordem, compara com a opção
  `guardkids_db_version`, executa `up()` das pendentes, atualiza a opção.
- Cada migration é uma classe com `version(): int` e `up(): void`.
- **Verificação:** teste unitário — runner aplica migration fake e atualiza
  a versão; rodar de novo não reaplica.

### Passo 5 — Migration `001_initial_schema`
- `database/migrations/001_initial_schema.php`: cria as **4 tabelas** da
  Fundação (`users`, `children`, `sessions`, `settings`) via `dbDelta()` com
  `$wpdb->get_charset_collate()`, exatamente com as colunas/índices da Seção
  5.2 do spec.
- **Verificação:** após ativação, `SHOW TABLES LIKE 'wp_guardkids_%'` retorna
  as 4 tabelas; reativar não gera erro nem altera o schema (idempotente).

### Passo 6 — Hooks de ativação/desativação + cron
- Ativação: roda o `MigrationRunner`; gera `guardkids_jwt_secret` se a
  constante `GUARDKIDS_JWT_SECRET` não existir; agenda o evento cron
  `guardkids_cleanup` (diário).
- Desativação: limpa o agendamento do cron (não apaga dados).
- Runner também roda em `plugins_loaded` (cobre atualização sem reativar).
- `guardkids_cleanup`: apaga sessões expiradas/`revoked` antigas e códigos de
  pareamento vencidos.
- **Verificação:** após ativar, `wp_next_scheduled('guardkids_cleanup')`
  retorna timestamp; o segredo JWT existe; desativar remove o agendamento.

### Passo 7 — `uninstall.php`
- Dropar as tabelas `wp_guardkids_*`; deletar as opções
  (`guardkids_db_version`, `guardkids_jwt_secret`); limpar transients de
  rate limit.
- **Verificação:** após "Excluir" o plugin no wp-admin, nenhuma tabela nem
  opção `guardkids_*` permanece.

---

## Fase C — Repositories

### Passo 8 — Base e repositories
- `includes/Database/Repository.php` (base): acesso a `$wpdb`, helpers de
  `prepare`, mapeamento linha→entidade.
- `UserRepository`, `ChildRepository`, `SessionRepository`: CRUD com
  `$wpdb->prepare()` em **todas** as queries.
- `SessionRepository` cobre: criar sessão pendente/ativa, achar por
  `refresh_token_hash`, achar por `pairing_code_hash`, marcar `revoked`,
  atualizar `last_seen_at`.
- **Verificação:** testes de integração de CRUD para os 3 repositories
  (criar, ler, atualizar, deletar) passam.

---

## Fase D — Camada de autenticação

### Passo 9 — `JwtService`
- `includes/auth/JwtService.php`: emite e valida access tokens HS256 via
  `firebase/php-jwt`. Resolve o segredo (constante > opção). Claims conforme
  Seção 4.1 do spec (`iss`, `sub`, `typ`, `sid`, `iat`, `exp`, `jti`).
- **Verificação:** testes unitários — assinar/validar; token expirado é
  rejeitado; assinatura adulterada é rejeitada.

### Passo 10 — `AuthService`
- `includes/auth/AuthService.php`. Métodos:
  - `loginParent(login, senha)` → valida via `wp_authenticate()`, garante o
    perfil em `guardkids_users`, cria sessão `guardian`, retorna par de tokens.
  - `createPairingCode(guardianId, childId)` → gera código de 6 dígitos, cria
    sessão `child` `pending` com hash do código e expiração de 15 min.
  - `pairChild(codigo)` → valida hash/expiração/uso único, promove sessão a
    `active`, retorna access + device token (180 dias).
  - `refresh(refreshToken)` → valida hash, rotaciona, retorna novo par.
  - `logout(sessionId)` / `revoke(guardianId, sessionId)` → marca `revoked`.
  - `resolveSubject(accessToken)` → valida JWT **e** confere se a sessão
    (`sid`) está `active` e não expirada.
- Refresh tokens: 32 bytes aleatórios, devolvidos em claro uma vez, salvos só
  como SHA-256. Pai: 30 dias; criança: 180 dias.
- **Verificação:** testes unitários cobrindo os critérios de sucesso 4–9 do
  spec (login, pareamento, refresh, logout, revogação, rejeição de token
  inválido/expirado).

---

## Fase E — Segurança / Middleware

### Passo 11 — Rate limiting
- `includes/security/RateLimiter.php`: contador por transient, chave =
  `guardkids_rl_{acao}_{hash(ip)}`; limite 5 tentativas / 15 min.
- `api/middleware/RateLimitMiddleware.php`: aplicado a `login` e `pair`.
- **Verificação:** 6ª tentativa em <15 min retorna **429**.

### Passo 12 — Middleware de autenticação
- `api/middleware/AuthMiddleware.php`: `permission_callback` que extrai o
  Bearer token, chama `AuthService::resolveSubject`, anexa o sujeito ao
  request; suporta variação "exige tipo `guardian`".
- **Verificação:** rota protegida retorna **401** para token ausente,
  inválido ou expirado, e para sessão revogada.

### Passo 13 — Headers seguros
- Filtro `rest_post_dispatch`: adiciona `X-Content-Type-Options: nosniff`,
  `Referrer-Policy` e demais headers da Seção 7 nas respostas do namespace
  `guardkids/v1`.
- **Verificação:** resposta de qualquer endpoint do plugin traz os headers.

---

## Fase F — API REST

### Passo 14 — Validators
- `api/validators/`: um validator por payload (login, pair, create-child,
  pairing-code). Sanitização com funções `sanitize_*` do WP; retorno de erro
  **422** com mensagens por campo.
- **Verificação:** payload inválido retorna 422 com a lista de erros.

### Passo 15 — Rotas e controllers
- `api/routes/`: registra no `rest_api_init` as 9 rotas da Seção 6 do spec,
  cada uma com `permission_callback` real.
- `api/controllers/`: `AuthController`, `ChildController`,
  `SessionController` — finos, chamam validator → service → resposta.
- Respostas de erro padronizadas no formato `WP_Error`.
- **Verificação:** percorrer no cliente REST os critérios de sucesso 4–10 do
  spec, todos com o comportamento esperado.

---

## Fase G — Testes e verificação final

### Passo 16 — Ambiente de testes
- `.wp-env.json` + `phpunit.xml.dist` + bootstrap de testes; `composer`
  scripts (`test`, `lint`).
- **Verificação:** `wp-env start` sobe; `composer test` executa a suíte.

### Passo 17 — Suíte de integração
- Testes cobrindo **os 11 critérios de sucesso** da Seção 2 do spec
  (ativação/idempotência, uninstall, login, me, pairing-code, pair, refresh,
  logout/revogação, 401, 429).
- **Verificação:** suíte 100% verde; cada critério tem ao menos 1 teste.

### Passo 18 — Verificação manual de fechamento
- Ativar em WP limpo com `WP_DEBUG`, rodar o fluxo completo no cliente REST
  (login pai → criar filho → gerar código → parear criança → refresh →
  revogar), desativar e desinstalar.
- **Verificação:** todos os 11 critérios do spec confirmados; zero
  notice/warning no `debug.log`.

---

## Fase H — i18n e documentação mínima

### Passo 19 — i18n e README de setup
- Gerar `languages/guardkids.pot`; garantir text domain em todas as strings
  visíveis.
- `README.md` do projeto: requisitos, instalação, setup do `wp-env`, como
  rodar os testes, exemplos de chamada da API.
- **Verificação:** `.pot` gerado; README permite a um dev subir o ambiente
  do zero.

---

## Resumo de entregáveis

```
guardkids-wp/
├── guardkids.php · uninstall.php · composer.json · .gitignore · README.md
├── api/{routes,controllers,middleware,validators}/
├── includes/{Plugin.php, auth/, security/, Database/}
├── database/{MigrationRunner.php, migrations/001_initial_schema.php, schema/}
├── languages/guardkids.pot
├── .wp-env.json · phpunit.xml.dist · tests/
└── docs/superpowers/{specs,plans}/
```

## Ordem de commits sugerida

Um commit por passo (ou por fase, para passos pequenos): `0–3` bootstrap ·
`4–7` banco · `8` repositories · `9–10` auth · `11–13` segurança ·
`14–15` API · `16–18` testes · `19` docs.

## Pendências antes de codar

1. **`git init`** — aguardando seu OK (Passo 0).
2. **Docker/`wp-env`** — necessário para os testes de integração; confirmar
   que o Docker está disponível na máquina.
