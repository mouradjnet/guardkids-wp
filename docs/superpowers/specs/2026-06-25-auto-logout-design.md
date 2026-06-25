# Auto-logout por inatividade — Design

**Data:** 2026-06-25
**Status:** Aprovado (aguardando review do spec escrito)
**Versão alvo:** v1.13.0 (minor, sem migração)

## Contexto

Próxima slice em mock da seção Segurança do painel-pais, depois da 2FA (v1.12.0).
O painel (`GuardKids\Ui\ParentApp`) é uma SPA React autenticada por cookie + nonce
do WordPress. Hoje existe um toggle **travado** `security.auto_logout` no
`Settings.tsx` ("Logout automático em 7 dias"), que é só placeholder.

Descoberta da exploração: o link **"Sair" do `SideNav` é morto** (`href="#logout"`),
ou seja, logout de verdade nem existe no SPA. A `logoutUrl` que este design
adiciona serve às duas coisas — ao auto-logout e a consertar o "Sair".

## Decisões (confirmadas com o usuário)

1. **Sentido:** timeout por **inatividade** (client-side, em minutos) — protege o
   cenário real de controle parental (pai deixa o painel aberto, criança mexe).
   NÃO é expiração de cookie em dias.
2. **Duração:** **presets escolhíveis** — toggle liga/desliga + seletor
   `5 / 15 / 30 / 60` min, **default 15**.
3. **Aviso:** modal de aviso com **contagem (~30s)** + botão "Continuar logado"
   antes de deslogar.
4. **Caveat aceito:** proteção client-side, sem enforcement server-side no MVP
   (documentado).
5. **Logout:** via `logoutUrl` injetada no `window.guardkidsApi` (também conserta
   o "Sair" do SideNav).
6. **Escopo:** só painel-pais (não o app infantil).

## Arquitetura

Abordagem: **timer de inatividade no SPA**. Quase 100% frontend; backend só expõe
a `logoutUrl`. Config (ligado + minutos) reusa o settings bag existente
(`/settings`), sem endpoint novo e sem migração.

### Backend (mínimo)

`includes/Ui/ParentApp.php` já injeta `window.guardkidsApi = { nonce, root }`.
Adicionar `logoutUrl` = `wp_logout_url(home_url('/painel-pais'))`. Após o logout,
o WP redireciona pro `/painel-pais`, que (deslogado) cai no `auth_redirect()` →
wp-login. Única mudança PHP.

### Configuração (settings bag existente)

Duas chaves, account-global, mesmo mecanismo dos toggles atuais
(`notifications.push` etc.), lidas via `GET /settings` e gravadas via `PUT /settings`:

| Chave | Tipo | Default | Notas |
|---|---|---|---|
| `security.auto_logout` | bool | false | liga/desliga |
| `security.auto_logout_minutes` | int | 15 | presets 5/15/30/60 |

Sem endpoint novo, sem migração (o bag é genérico key/value).

### Componentes (frontend, unidades isoladas)

| Unidade | Tipo | Responsabilidade | Depende de |
|---|---|---|---|
| `useIdleTimeout` | hook puro | arma timers de inatividade, escuta atividade (throttled), sincroniza entre abas via localStorage, dispara `onWarn`/`onTimeout`, reseta na atividade | timers/eventos do browser |
| `IdleWarningDialog` | componente | modal "Você será desconectado em {N}s" + "Continuar logado" / "Sair agora" | — |
| `AutoLogoutGuard` | componente | lê settings (enabled+minutes), usa o hook, renderiza o dialog, `onTimeout` → redireciona pra `logoutUrl` | `useIdleTimeout`, `IdleWarningDialog`, `/settings` |
| Settings UI | edição | troca o toggle travado por toggle real + `<select>` de presets | `SettingToggleRow` + select |
| SideNav "Sair" | edição | troca `href="#logout"` pela `logoutUrl` real | `guardkidsApi.logoutUrl` |

**`useIdleTimeout({ minutes, warningSeconds = 30, enabled, onWarn, onTimeout })`:**
- Quando `enabled`, registra listeners throttled de atividade
  (`mousemove`, `keydown`, `touchstart`, `scroll`, `click`).
- Mantém o timestamp do último uso em `localStorage` (`gk_last_activity`) e ouve
  o evento `storage` pra sincronizar entre abas (atividade numa aba reseta todas).
- Agenda: aviso em `minutes*60 − warningSeconds`; timeout em `minutes*60`.
- Qualquer atividade reseta os timers e dispensa o aviso.
- Quando `enabled` é false (ou na desmontagem), remove listeners e limpa timers.

**`AutoLogoutGuard`:** montado na raiz do layout do painel. Lê
`security.auto_logout` + `security.auto_logout_minutes` via `useQuery(['settings'])`.
Passa pro hook. `onWarn` → abre o `IdleWarningDialog` com contagem; "Continuar
logado" reseta; `onTimeout` (ou "Sair agora") → `window.location.href = logoutUrl`.

## Fluxo

`/settings` → `AutoLogoutGuard` lê enabled+minutes → hook arma timers →
inatividade atinge `minutes−30s` → `onWarn` abre o dialog com contagem → 30s sem
resposta → `onTimeout` → redireciona pra `logoutUrl` (logout WP → wp-login).
Atividade em qualquer aba reseta tudo.

## Erros / edge cases

- `enabled=false` → hook inerte (sem listeners, sem timers).
- Multi-aba: `localStorage` + evento `storage` evitam deslogar uma aba enquanto
  outra está ativa.
- Sem `logoutUrl` (improvável) → fallback pra `/wp-login.php?action=logout`.
- Sem enforcement server-side (documentado) — proteção client-side.

## Testes

- **vitest (app-parent):**
  - `useIdleTimeout` com fake timers: dispara `onWarn` e `onTimeout` nos tempos
    certos; reseta na atividade; respeita `enabled=false`; sincroniza via `storage`.
  - `IdleWarningDialog`: renderiza a contagem, "Continuar logado" reseta, "Sair
    agora" chama o callback.
  - Settings: toggle real + select de presets aparece quando ligado e grava a key.
- **PHP:** a injeção de `logoutUrl` no ParentApp é render (sem teste unitário
  dedicado) — coberta no smoke manual.

## Smoke manual (pós-implementação)

1. Ligar o auto-logout em Configurações → Segurança, escolher 5 min.
2. Deixar o painel ocioso → aos ~4m30s aparece o aviso com contagem.
3. "Continuar logado" → some e o timer reseta.
4. Deixar zerar → redireciona pro login.
5. Conferir que o "Sair" do menu lateral agora desloga de verdade.

## Fora de escopo (YAGNI)

- Enforcement server-side / rastreamento de sessão no servidor.
- Auto-logout no app infantil.
- Campo numérico livre de minutos (só presets).
- Expiração de cookie por dias (`auth_cookie_expiration`).

## Entrega

Feature → **v1.13.0** (minor), sem migração. PR único contra `master`, suítes
vitest (app-parent/child) + PHPUnit verdes (gate de suíte completa antes de prod),
build de zip canônico, tag/release e deploy pelo runbook `tools/deploy/README.md`.
