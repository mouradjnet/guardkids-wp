# Hardening do token do Companion — Design

**Data:** 2026-06-25
**Status:** Aprovado (aguardando review do spec escrito)
**Versão alvo:** v1.15.0 (minor, com migration — DB v10→v11)

## Contexto

Saída da auditoria de segurança do fluxo de auth do Companion (`CompanionController`).
O fluxo é sólido (tokens de 256 bits hasheados com sha256, pairing token efêmero
10min + uso único, sem SQLi, sem IDOR), mas tem 3 lacunas de ciclo de vida:

- **#1 (Médio):** o **session token é persistente sem expiração**; só o
  re-pareamento revoga. Token vazado (root/backup/malware no device da criança)
  vale para sempre → permite spoofar status de proteção (`accessibility_enabled`,
  `kiosk_mode`, etc.) indefinidamente.
- **#2 (Baixo):** sem **rate-limit** nos endpoints públicos (`enroll`/`sync`/
  `heartbeat`, `permission_callback => '__return_true'`).
- **#3 (Baixo):** pairing tokens expirados **não são limpos** (acumulam na
  settings table).

O app Android (`mouradjnet/guardkids-companion`) já está vivo em prod, então o
design **não muda o contrato da API** — é transparente pro cliente.

## Decisões (confirmadas com o usuário)

1. **Expiração = janela deslizante + kill-switch.** Cada sync/heartbeat renova o
   expiry; device ativo nunca expira (transparente pro app); ocioso N dias expira.
   O atacante que segue sincronizando é coberto pelo kill-switch.
2. **Storage = coluna na tabela** (`session_expires_at`), não settings bag — o
   `session_token_hash` já mora no device; o expiry fica do lado (fonte única).
3. **Janela = 30 dias** (constante ajustável).
4. **Kill-switch = endpoint + UI** (a tela de status do Companion já existe no
   painel: `CompanionStatusCard`).

## Arquitetura

Tudo no backend WP + um botão no painel. Sem mudança no contrato consumido pelo
app Android.

### Migration (v10→v11)

`database/migrations/011_*.php` adiciona `session_expires_at DATETIME NULL` em
`{prefix}guardkids_companion_devices`. Bump `GUARDKIDS_DB_VERSION` 10→11 em
`guardkids.php` no **mesmo commit** (senão `maybeRunMigrations` skipa — ver
[[feedback_guardkids_wp_migration_bump]]). Devices legados ficam `NULL` →
tratados como "sem expiry" (aceitos) → a próxima sync grava um expiry fresco.
Migração suave: ninguém é deslogado.

### Componentes

| Unidade | Mudança | Responsabilidade |
|---|---|---|
| `database/migrations/011_*.php` (criar) | nova | coluna `session_expires_at` |
| `database/CompanionDeviceRepository.php` (modificar) | `touchSync` grava o expiry deslizante; `revokeSession(id)` novo | persistência |
| `api/Controllers/CompanionController.php` (modificar) | enroll grava expiry; `authenticateSession` checa expiry; rate-limit; `revoke()`; GC do pairing | auth + lifecycle |
| `api/RestApi.php` (modificar) | registra `POST /companion/revoke` (requireAdmin) | rota |
| `includes/Maintenance/Purger.php` (modificar) | sweep `companion_token:*` vencidos | GC periódico |
| `public/app-parent/src/api/companion.ts` (modificar) | `revokeCompanion(childId)` | client |
| `public/app-parent/src/components/CompanionStatusCard.tsx` (modificar) | botão "Revogar dispositivo" | UI |

### #1 — Expiração por janela deslizante

- **`enroll`** (ao gravar `session_token_hash`): grava também
  `session_expires_at = now + 30d`.
- **`authenticateSession`**: depois de achar o device pelo hash, rejeita se
  `session_expires_at` **não-NULL e < now** → `401 "Sessão expirada. Refaça o
  pareamento."`. `NULL` (legado) → aceita.
- **`touchSync`** (sync/heartbeat): renova `session_expires_at = now + 30d` junto
  com `last_sync`.

Constante `SESSION_TTL_DAYS = 30` no controller/repo.

### #1b — Kill-switch (revogação por admin)

- **`POST /companion/revoke`** (`requireAdmin`, arg `child_id`) →
  `CompanionDeviceRepository::revokeSession(deviceId)` que faz
  `update(id, ['session_token_hash' => null, 'session_expires_at' => null,
  'status' => 'revoked'])`. Próximo sync do device → `401` (o app já trata como
  "refaça o pareamento", sem mudança de contrato).
- **UI:** botão "Revogar dispositivo" no `CompanionStatusCard` (quando
  `paired === true`), com `window.confirm`, chamando `revokeCompanion(childId)` e
  invalidando a query de status.

### #2 — Rate-limit

Reusar `includes/Security/RateLimiter` no início de `enroll`/`sync`/`heartbeat`,
chaveado pelo `device_uuid` quando há device, ou por um identificador estável no
enroll (ex.: hash do pairing token) — `429` ao exceder (mesmo padrão do
`ChildSelfController`). Cap generoso (ex.: 60/min) pra não atrapalhar heartbeat
legítimo.

### #3 — GC dos pairing tokens

- `authenticatePairing`: ao detectar expirado, `deleteByKey($key)` antes do 401.
- `Purger`: sweep removendo `companion_token:*` com `expiresAt` vencido (junto da
  rotina diária que já existe).

## Fluxo

Pareamento e enroll inalterados (enroll passa a gravar o expiry). Sync/heartbeat
renovam o expiry. `authenticateSession` barra token expirado. Admin revoga →
hash/expiry zerados → device cai no 401 e re-pareia. Purger limpa pairing tokens
vencidos.

## Erros / edge cases

- Device legado (`session_expires_at = NULL`) → aceito; primeira sync grava o
  expiry. Sem deslogar ninguém no deploy.
- Revogação de child sem device pareado → `404`/no-op idempotente.
- Token expirado **e** revogado → 401 em ambos (fail-closed).
- Rate-limit best-effort (transient WP, não atômico) — aceitável pra DoS.

## Testes

- **PHPUnit (controller, com fakes de repo/RateLimiter):**
  - `authenticateSession`: rejeita expirado; aceita NULL (legado); aceita válido.
  - `enroll`: grava `session_expires_at` ~now+30d.
  - `sync`/`heartbeat`: renovam o expiry.
  - `revoke`: zera hash+expiry, status `revoked`; token para de autenticar.
  - rate-limit: 429 ao exceder.
  - `authenticatePairing`: pairing expirado é deletado + 401.
- **PHPUnit (migration):** `MigrationRunner` cobre a 011 (idempotência/ordem,
  padrão das migrations existentes).
- **vitest (app-parent):** `companion.ts` (`revokeCompanion`) + `CompanionStatusCard`
  (botão Revogar aparece quando pareado, confirma e chama a API).

## Smoke manual (pós-deploy)

1. Parear um device (ou usar o existente) → status "pareado" no painel.
2. **Revogar dispositivo** → o device, na próxima sync, recebe 401 e pede re-pareamento.
3. Confirmar (via SSH `wp eval`/DB) que `session_expires_at` é preenchido na sync
   e renovado.

## Fora de escopo (YAGNI)

- Rotação de token a cada sync (mudaria o contrato do app Android).
- Teto absoluto de sessão (atrito de re-pareamento recorrente).
- Binding do token a fingerprint do device.
- Mudanças no app Android (o design é transparente pra ele).

## Entrega

Feature → **v1.15.0** (minor, **com migration** DB v11). PR único contra `master`,
suítes PHPUnit + vitest verdes + CI (integração roda a migration real), build de
zip canônico, tag/release e deploy via SSH (atenção: **tem migration** —
conferir `wp option get guardkids_db_version` = 11 após o deploy).
