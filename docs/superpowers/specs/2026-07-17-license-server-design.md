# License Server GuardKids — Núcleo (fatia 1)

**Data:** 2026-07-17
**Módulo:** Novo plugin `guardkids-license-server` (servidor) + `includes/License` (cliente guardkids-wp)
**Repos:** `guardkids-license-server` (novo, fork de `fluxomestre-license-server`) + `guardkids-wp` (existente)
**DB cliente:** sem migração — usa `wp_options` (transient de cache)

## Problema

A licença premium do GuardKids já funciona **no cliente**: o `Verifier` valida uma chave
Ed25519 offline (pubkey embarcada), o `Gate` gateia sete features premium, e o
`LicenseController` ativa/desativa. O que **não existe** é o outro lado: quem **emite** a
chave. Hoje isso é `scripts/issue-license.php` — um CLI que roda no notebook do Djair, lê a
privkey de `~/.guardkids/issuer.key` e imprime uma chave pra colar no painel do cliente. Não
há registro do que foi emitido, pra quem, nem como desfazer.

E há um buraco de segurança concreto: **a revogação é decorativa.** O `Gate::isRevoked()`
(`includes/License/Gate.php:151-155`) lê a lista de `jti` revogados de
`get_option('guardkids_license_revoked')` — o wp_options **do próprio site do cliente**. Nada
em produção escreve nessa option (só testes). Ou seja: hoje **só o próprio cliente poderia se
auto-revogar**. Se uma chave vazar, for compartilhada, ou um chargeback acontecer, não há como
o emissor cortar o acesso. A verificação por domínio (`sub` vs `siteurl`) e por expiração (`exp`)
funcionam; a revogação é a única das três defesas que não tem quem a acione.

Esta fatia cria o servidor que emite e revoga, e fecha o loop no cliente pra que revogar tenha
efeito real.

## Decisões (fechadas no brainstorming anterior)

1. **Verificação continua offline.** Não migramos pro modelo online do fluxomestre/planocerto
   (`POST /validate` a cada request). O Ed25519 embarcado é bom: funciona sem rede, é O(1), e não
   cria dependência do servidor no caminho quente. O servidor **não valida** licenças — só as
   **emite** e publica a **lista de revogadas**.

2. **Revogação com atraso é aceitável.** Como corolário de (1): o cliente faz **phone-home
   diário** ao endpoint `/revoked`, cacheia o resultado, e **falha aberta** — se o servidor
   estiver fora, mantém o último cache e não derruba o premium de quem pagou. A janela entre
   revogar e o cliente perceber é de até ~24h. Para o caso de uso (chargeback, chave vazada),
   isso é aceitável; derrubar premium legítimo por um servidor offline não seria.

3. **Fatiar em 3; fazer só o núcleo agora.** Esta fatia = **cunhar + guardar + entregar por
   email + revogar + endpoint `/revoked` + o cliente consumindo `/revoked`**, acionado por
   **admin/CLI**. Já tira o processo do notebook do Djair. Fora desta fatia: checkout/pagamento,
   webhook de plataforma, e a página de ativação self-service (ver "Fora de escopo").

4. **Plugin WP na Hostinger; privkey em constante no wp-config.** O servidor é um plugin WP
   autônomo, deployado num WP na Hostinger (mesmo padrão do fluxomestre-license-server). A privkey
   Ed25519 vive em `define('GKL_ISSUER_PRIVKEY_B64', '...')` no `wp-config.php`, **fora do git**.
   A pubkey correspondente já está embarcada no `Verifier` do cliente.

5. **Fork do `fluxomestre-license-server`.** Reuso de **andaime**, não de lógica: autoloader,
   Plugin boot, CPT, rate-limiter, comando WP-CLI e o harness de testes standalone
   (`tests/run.php`). A lógica de emissão (Ed25519) é nova; o webhook/HMAC/Hotmart e os
   controllers `validate`/`status` **não vêm** (são do modelo online).

6. **Escopo confirmado 2026-07-17: os dois lados.** A spec cobre o servidor **e** a mudança no
   guardkids-wp que consome `/revoked`. Fazer só o servidor deixaria `/revoked` como código morto
   e a revogação seguiria decorativa — o exato problema que originou a fatia.

## Arquitetura

Dois codebases, um contrato (o formato da chave, já definido pelo `Verifier`).

```
┌─────────────────────────────┐         ┌──────────────────────────────┐
│  guardkids-license-server   │         │        guardkids-wp          │
│  (WP na Hostinger)          │         │   (site do cliente)          │
│                             │         │                              │
│  privkey (wp-config)        │  cunha  │  pubkey (Verifier, embarcada)│
│  Signer Ed25519 ───────────────chave──▶  LicenseController::activate │
│  CPT gkl_license (registro) │  email  │  Gate (valida offline)       │
│  WP-CLI mint / revoke       │         │                              │
│  GET /gkl/v1/revoked ◀────── phone-home diário (cron) ── RevocationCache
│  (lista de jti revogados)   │         │  Gate::isRevoked lê o cache   │
└─────────────────────────────┘         └──────────────────────────────┘
```

### Contrato da chave (imutável — já é o que o cliente aceita)

O servidor **replica exatamente** a mecânica de `scripts/issue-license.php` (que continua
existindo como fallback local). Formato: `base64url(payload_json).base64url(assinatura)`.

Payload (campos exigidos por `Verifier::hydrate`):

```json
{
  "iss": "guardkids",
  "sub": "https://cliente.com",   // domínio, rtrim('/') — trava a chave ao siteurl
  "jti": "a1b2c3…",               // 24 hex random — id de revogação
  "iat": 1721160000,
  "exp": 1752696000,
  "plan": "premium",
  "features": ["browser","categories","schedule","reports","location","unlimited_kids","full_history"],
  "email": "cliente@example.com"
}
```

Assinatura: `sodium_crypto_sign_detached(base64url(json), privkey)`, com
`json_encode(..., JSON_UNESCAPED_SLASHES)`. **Qualquer divergência de encoding quebra a
verificação** — por isso o Signer do servidor é uma cópia fiel das linhas 152-166 do
`issue-license.php`, não uma reimplementação livre.

## Servidor: o que reusa vs. o que é novo

| Arquivo do fork | Destino no guardkids-license-server |
|---|---|
| `class-autoloader.php` | reusa (rebrand namespace) |
| `class-plugin.php` | reusa (boot; remove wiring de webhook) |
| `class-license-cpt.php` | **adapta** — CPT `gkl_license`, campos novos (ver abaixo) |
| `class-rate-limiter.php` | reusa (protege `/revoked`) |
| `class-cli-command.php` | **adapta** — comandos `mint` e `revoke` |
| `tests/run.php` | reusa (harness standalone, stubs WP embutidos) |
| `class-hmac-verifier.php` | **descarta** (sem webhook nesta fatia) |
| `class-hotmart-event-handler.php` | **descarta** (sem plataforma nesta fatia) |
| `api/class-validate-controller.php` | **descarta** (validação é offline no cliente) |
| `api/class-status-controller.php` | **descarta** (idem) |
| `api/class-webhook-controller.php` | **descarta** (sem webhook nesta fatia) |

**Novos:**
- `Signer` (Ed25519) — cunha a chave a partir do payload + privkey do wp-config.
- `api/RevokedController` — `GET /gkl/v1/revoked`.
- Meta-box/coluna no admin do CPT pra revogar com um clique.

### CPT `gkl_license` — o registro do que foi emitido

Um post por licença emitida. `post_status` guarda o ciclo de vida.

| Campo (post meta) | Uso |
|---|---|
| `jti` | id de revogação — é o que `/revoked` lista |
| `sub` | domínio travado |
| `email` | cliente (entrega + suporte) |
| `plan` / `features` | o que foi concedido |
| `iat` / `exp` | emissão / expiração |
| `key_b64` | a chave cunhada completa (pra reenviar por email sem re-cunhar) |
| `post_status` | `gkl_active` \| `gkl_revoked` |

> **Gotcha herdado do fluxomestre (memória `project-fluxomestre-license-server`, v1.1.1):** ao
> buscar posts por meta, **não usar `post_status => 'any'`** — `'any'` exclui status com
> `exclude_from_search => true`, que é como CPTs de licença se registram, tornando toda licença
> invisível. Usar a lista explícita de status nos lookups.

### `GET /gkl/v1/revoked`

Retorna a lista de `jti` com `post_status = gkl_revoked`:

```json
{ "revoked": ["jti1", "jti2"], "generated_at": "2026-07-17T12:00:00Z" }
```

- **Público, sem auth.** Um `jti` é hex opaco random — a lista não vaza dado pessoal (sem
  email, domínio ou nome). Auth aqui só adicionaria um segredo compartilhado a gerir sem
  ganho real.
- **Rate-limited** (reusa o `RateLimiter`, ex. 60/min/IP) contra abuso.
- **Lista completa, não `?jti=`.** Uma request/dia que o cliente cacheia inteira é mais
  resiliente (o cliente decide localmente, offline entre os polls) do que uma consulta por
  jti que acopla cada checagem a uma ida de rede. A lista de revogadas cresce devagar.

### WP-CLI (o que substitui o `issue-license.php` no notebook)

```bash
wp gkl mint --email=cliente@example.com --domain=https://cliente.com --expires=2027-12-31 [--plan=premium] [--features=…]
wp gkl revoke --jti=<jti>          # muda post_status → gkl_revoked (aparece no /revoked no próximo poll)
```

`mint` cunha, cria o CPT, e dispara o email com a chave. A regra de emissão vive num Service
(`LicenseIssuer::issue()`) reusável por CLI e, na fatia futura, pela página self-service — o
mesmo padrão `createLicense()` que o fluxomestre extraiu pra CLI+webhook compartilharem.

### Entrega por email

`wp_mail()` pro `email` do payload, com a chave e instruções de ativação (colar em
Configurações → Licença no painel dos pais). Texto e remetente GuardKids.

## Cliente (guardkids-wp): fechar o loop da revogação

Mudança cirúrgica, **sem migração de banco** (usa transient/option).

1. **`RevocationCache`** (novo, `includes/License/`): faz `GET` no `/gkl/v1/revoked` do servidor,
   guarda a lista de `jti` num transient (`gk_revoked_jti`, TTL ~25h) + timestamp do último
   sucesso. Agendado num **cron diário** (`wp_schedule_event`). **Falha aberta:** se a request
   falhar (timeout, 5xx, servidor fora), **mantém o cache anterior** e não esvazia a lista — nunca
   revoga por indisponibilidade. Primeira execução sem cache = lista vazia = ninguém revogado
   (também aberto).

2. **`Gate::isRevoked()`** passa a ler do `RevocationCache` em vez de
   `get_option('guardkids_license_revoked')`. Assinatura pública do `Gate` intacta —
   `status()`/`can()`/`plan()` não mudam. Só a fonte da lista de revogados muda.

3. **Base do servidor** como constante (ex. `GK_LICENSE_SERVER_BASE`), espelhando como o cliente
   fluxomestre aponta pro seu server.

> A option `guardkids_license_revoked` local pode ser mantida como **override manual de
> emergência** (união com a lista remota): permite revogar na unha via `wp option` sem depender
> do servidor. Decisão menor — confirmar na implementação.

## Segurança

- **Privkey nunca no git.** Só em `wp-config.php` do servidor (`GKL_ISSUER_PRIVKEY_B64`). O
  `.gitignore` do servidor cobre qualquer `*.key`. Se a privkey vazar, todas as chaves emitidas
  são forjáveis → rotacionar a pubkey no `Verifier` do cliente invalida tudo (custo alto,
  documentado).
- **Rate-limit** no `/revoked`.
- **`mint`/`revoke` só via WP-CLI/admin autenticado** (sem rota REST de emissão nesta fatia — o
  self-service, que exporia emissão à web, é fatia futura e precisa de prova-de-compra).
- **CPT privado** (`public => false`, `exclude_from_search`) — licenças não aparecem no site.

## Fora de escopo (fatias futuras)

- **Fatia 2 — Página de ativação self-service.** A chave é travada por domínio (`sub`), e no ato
  da compra não se sabe o domínio do cliente. Precisa de uma página onde o cliente, com prova de
  compra, informa o domínio e recebe a chave cunhada na hora. Exige um mecanismo de prova-de-compra
  (token/código) — desenho próprio.
- **Fatia 3 — Checkout + webhook da plataforma.** Integrar a plataforma de venda (Hotmart ou
  outra — indefinida) via webhook que dispara `mint` automático. Aqui é que o
  `HotmartEventHandler`/HMAC do fork voltariam a ser úteis.
- **Renovação / grace period.** O fluxomestre tem grace de 7 dias; aqui a expiração é o `exp` da
  chave e renovar = emitir nova. Grace automático fica pra quando houver cobrança recorrente.

## Critérios de sucesso (verificáveis)

Servidor:
- `wp gkl mint …` cunha uma chave que o `Verifier` do cliente aceita (`status() === 'active'`) — teste
  cruzado: cunhar no server, verificar com a pubkey embarcada.
- O CPT registra a licença; `wp gkl revoke --jti=X` move pra `gkl_revoked`.
- `GET /gkl/v1/revoked` lista o `jti` revogado e omite os ativos.
- `tests/run.php` verde (harness standalone, como o fluxomestre).

Cliente:
- `RevocationCache` popula o transient a partir do `/revoked`; `Gate::isRevoked()` passa a
  refletir a lista remota (teste com server stubado).
- **Falha aberta provada:** com o fetch falhando e cache prévio presente, o premium continua;
  sem cache, ninguém é revogado. Teste que falsifica: sem esse comportamento, um server offline
  derrubaria premium legítimo.
- Suíte PHP do guardkids-wp segue verde.

## Plano de implementação

Detalhamento passo-a-passo (server-first, cliente depois) sai via `writing-plans` a partir desta
spec. Ordem sugerida: (1) fork+rebrand do andaime → (2) `Signer` + teste cruzado com a pubkey →
(3) CPT + `mint` CLI + email → (4) `revoke` + `/revoked` + rate-limit → (5) `RevocationCache` +
cron + `Gate::isRevoked` no cliente → (6) smoke E2E: mint no server, ativar no cliente, revoke,
esperar o poll, ver o premium cair.
