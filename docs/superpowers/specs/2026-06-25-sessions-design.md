# Gestão de sessões — Design

**Data:** 2026-06-25
**Status:** Aprovado (aguardando review do spec escrito)
**Versão alvo:** v1.14.0 (minor, sem migração)

## Contexto

Última slice em mock da seção Segurança do painel-pais, depois de 2FA (v1.12.0)
e auto-logout (v1.13.0). Hoje o `SessionsBlock` (em `Settings.tsx`) é um
placeholder com `ComingSoonBadge` que diz "entra junto com a tabela de sessões na
próxima migration".

**Descoberta:** essa premissa está desatualizada. O WordPress **já mantém as
sessões ativas por usuário** via `WP_Session_Tokens` (em `user_meta`
`session_tokens`), com `ip`, `ua` (user-agent), `login` (timestamp) e
`expiration` por sessão. Logo, **não precisa de migration nem tabela nova**.

## Decisões (confirmadas com o usuário)

1. **Ações:** listar as sessões ativas + **um botão "Sair de todos os outros
   aparelhos"** (`destroy_others`, mantém a atual). Sem encerrar individual no MVP.
2. **Detalhe por sessão:** aparelho amigável (navegador·SO via parse do UA) + IP
   + último acesso + selo **"Esta sessão"** na atual.
3. **Sem migration:** usa `WP_Session_Tokens` nativo.
4. **Sessão atual** identificada por casamento de campos (o `get_all()` não vem
   chaveado pelo token) — pragmatismo aceito.
5. **Endpoint** de encerrar = `POST /security/sessions/destroy-others`.
6. **Escopo:** usuário logado atual; sem premium gate.

## Arquitetura

Abordagem: **`WP_Session_Tokens` nativo**. A lógica pura (parse do UA +
normalização raw→DTO + flag da sessão atual) fica isolada e testável; a parte
acoplada ao WP (buscar/destruir tokens) é uma camada fina.

### Backend

- **`UserAgent`** (puro, `includes/Security/UserAgent.php`):
  `parse(string $ua): array{browser: string, os: string}` — regex simples
  (Chrome/Edge/Firefox/Safari/Opera + Windows/macOS/Android/iOS/Linux), com
  fallback `"Desconhecido"` quando vazio/irreconhecível. Totalmente testável.

- **`SessionPresenter`** (puro, `includes/Security/SessionPresenter.php`):
  `present(array $rawSessions, ?array $currentSession): array` — recebe a lista
  crua do `WP_Session_Tokens::get_all()` e a sessão atual (data array), devolve
  DTOs `[{ device, browser, os, ip, lastAccess, current }]`, marca `current`
  casando o data array completo, ordena por `login` desc. `device` = `"$browser ·
  $os"`. Testável com dados fake (sem WP).

- **`SessionManager`** (acoplado ao WP, fino, `includes/Security/SessionManager.php`):
  - `list(): array` → `WP_Session_Tokens::get_instance($uid)->get_all()` +
    `->get(wp_get_session_token())` (atual) → delega ao `SessionPresenter`.
  - `destroyOthers(): int` → conta as outras, chama
    `WP_Session_Tokens::get_instance($uid)->destroy_others(wp_get_session_token())`,
    devolve quantas foram encerradas.
  - `$uid = get_current_user_id()`.

- **Rotas REST** (`requireAdmin`, agem no user atual), em `SessionsController`
  (`api/Controllers/SessionsController.php`) registrado no `RestApi`:
  - `GET /security/sessions` → `{ sessions: [{ device, browser, os, ip, lastAccess, current }] }`
  - `POST /security/sessions/destroy-others` → `{ destroyed: N, sessions: [...] }`

### Frontend

- `src/api/sessions.ts` — `listSessions(): Promise<{ sessions: SessionDto[] }>` e
  `destroyOtherSessions(): Promise<{ destroyed: number; sessions: SessionDto[] }>`.
- Substitui o mock `SessionsBlock` (em `Settings.tsx`) por um componente real
  `src/components/SessionsSection.tsx`:
  - `useQuery(['sessions'])` → renderiza um card por sessão (aparelho + IP +
    último acesso + selo "Esta sessão" na `current`).
  - Botão **"Sair de todos os outros aparelhos"** com `window.confirm` → mutation
    `destroyOtherSessions` → invalida `['sessions']`.
  - Estados: carregando; lista; **só a sessão atual** (botão oculto/desabilitado);
    erro.

## Fluxo

`GET /security/sessions` → `SessionManager.list()` → `WP_Session_Tokens.get_all()`
+ atual → `SessionPresenter.present()` → DTOs → render. Clicar "Sair de todos os
outros" → confirm → `POST .../destroy-others` → `destroy_others()` → invalida a
query → lista re-renderiza só com a atual.

## Erros / edge cases

- Só 1 sessão (a atual) → botão de encerrar oculto/desabilitado.
- Não dá pra encerrar a **própria** sessão por aqui (isso é o "Sair" do menu).
- `ip`/`ua` vazios (sessões antigas/CLI) → `device`/`ip` mostram "Desconhecido".
- `wp_get_session_token()` vazio (sem cookie de sessão) → nenhuma marcada como
  atual; `destroy_others` vira no-op seguro.

## Testes

- **PHP unit:**
  - `UserAgent::parse` — Chrome/Edge/Firefox/Safari/Opera + Windows/macOS/Android/
    iOS/Linux → amigável; UA vazio/estranho → "Desconhecido".
  - `SessionPresenter::present` — marca `current` correto pelo casamento de
    campos; ordena por `login` desc; mapeia device/ip/lastAccess; lista vazia →
    `[]`.
- **vitest:** `sessions.ts` (chamadas/erros) + `SessionsSection` (lista, selo
  atual, estado "só esta sessão", confirm de encerrar + invalidação).

## Smoke manual (pós-implementação)

1. Logar em dois navegadores (ou normal + anônimo) na mesma conta.
2. No painel → Configurações → Segurança → ver **duas** sessões, com "Esta sessão"
   na atual.
3. "Sair de todos os outros aparelhos" → confirma → a outra sessão cai (no outro
   navegador, próxima ação pede login).

## Fora de escopo (YAGNI)

- Encerrar uma sessão específica individualmente.
- Tabela/tracking custom de dispositivos (o WP já provê).
- Geolocalização do IP / nomes amigáveis de dispositivo além de navegador·SO.
- Notificar por e-mail sobre novo login.

## Entrega

Feature → **v1.14.0** (minor), sem migração. Fecha a seção Segurança (último
mock). PR único contra `master`, suítes PHPUnit + vitest verdes (gate de suíte
completa antes de prod), build de zip canônico, tag/release e deploy pelo runbook
`tools/deploy/README.md`.
