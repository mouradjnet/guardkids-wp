# License Gating Premium — Design

- **Data:** 2026-06-08
- **Status:** Aprovado para implementação
- **Escopo:** Sistema de licença que ativa features premium no GuardKids WP. **Sem billing integrado** (chaves emitidas fora — Hotmart/Gumroad/Pix manual), **sem trial**, **sem histórico de cobrança real** na v1, **single-site** (1 chave = 1 domínio WP).
- **Fora do escopo (v1):**
  - Gateway de pagamento dentro do plugin
  - Trial automático
  - Histórico de cobrança/recibos PDF
  - Multi-domain (transferir chave entre domínios sem reativação manual)
  - Multi-seat real (limite numérico de filhos no premium)

---

## 1. Motivação

`License.tsx` (12 KB) e `Upgrade.tsx` (8 KB) estão hoje 100% mock — botões decorativos, dados de `mockData.licenseInfo`. Sem gating real, "Premium" é cosmético: qualquer pessoa que instala o plugin tem acesso a tudo (Browser seguro, Categorias, Schedule, Reports, Location). Esse design entrega o **mínimo funcional** pra:

1. Diferenciar Free × Premium em runtime (PHP + React)
2. Aceitar chaves de licença que você emite manualmente
3. Bloquear features premium quando não há licença ativa
4. Mostrar status real na UI (ativa/expirada/inexistente)

## 2. Decisões-chave

| Decisão | Escolha | Motivo |
|---|---|---|
| Storage | `wp_options.guardkids_license` (1 row, JSON) | 1 licença por instalação, não justifica tabela nova |
| Validação | **Offline** via JWT-like assinado (Ed25519) | Sem backend dedicado; chave emitida fora carrega tudo necessário |
| Domain lock | Sim — payload inclui `sub: <siteurl>` | Evita 1 chave rodando em N domínios |
| Plano | `free` ou `premium` (enum binário) | Não precisa de tiers intermediários hoje |
| Trial | Não na v1 | Free tier já existe; trial adiciona state machine |
| Quem emite | CLI `php scripts/issue-license.php …` (manual no seu PC) | MVP sem infra extra; só você roda |
| Revogação | Lista de `jti` revogados como option (`guardkids_license_revoked`) | Distribuída via update do plugin se necessário |
| Premium expirado | Bloqueia **só features novas**; dados antigos continuam visíveis | Não-destrutivo; conversão melhor pra renovar; sem state machine de grace |
| Free — filhos | Limite de **1 filho**; premium = ilimitado | Bate com mock + cria fricção limpa pra conversão (famílias com 2+ filhos precisam) |
| CTA "Upgrade" | URL configurável via setting `guardkids_upgrade_url` | Troca Hotmart→Pix→Stripe sem release; default vazio esconde botão |
| Histórico de cobrança | **Removido** da UI nesta versão | Sem backend real; mock confunde. Re-adicionar quando integrar webhook |

## 3. Schema

### 3.1 Option `guardkids_license`

```json
{
  "key_b64": "eyJhbGciOiJFZER…",   // chave completa pra reativar (sem hash)
  "payload": {
    "iss": "guardkids",            // issuer fixo
    "sub": "https://exemplo.com",  // siteurl do WP no momento da ativação
    "jti": "01HJ0K7C…",            // identificador único (revogação futura)
    "iat": 1717862400,             // emitida em (unix)
    "exp": 1749398400,             // expira em (unix)
    "plan": "premium",
    "features": ["browser","categories","schedule","reports","location","unlimited_kids","full_history"],
    "email": "djair@exemplo.com"   // só pra suporte, não validado
  },
  "activated_at": "2026-06-08 14:23:00",
  "signature_valid": true,         // recomputado no boot; cache pra evitar verify a cada request
  "last_verified_at": "2026-06-08 14:23:00"
}
```

### 3.2 Option `guardkids_license_revoked` (futuro)

```json
["01HJ0K7C…", "01HK9X2B…"]
```

Array de `jti` revogados. Plugin lê no boot; se a chave ativa estiver nessa lista, força downgrade.

## 4. Formato da chave

`<base64url(payload_json)>.<base64url(ed25519_signature)>` — mesma forma que JWT mas sem header (algoritmo é fixo Ed25519).

- **Tamanho típico:** ~280 chars (cabe em 1 textarea / clipboard sem problema).
- **Pubkey:** embarcada em `includes/License/PUBKEY.php` como constante. Pubkey Ed25519 ocupa 32 bytes → 44 chars base64.
- **Privkey:** **não vive no plugin**. Mora num arquivo local seu (`~/.guardkids/issuer.key`) usado só pelo CLI emissor.

```php
namespace GuardKids\License;

final class Verifier
{
    public const ISSUER_PUBKEY_B64 = 'MCowBQYDK2VwAyEA...';

    public function verify(string $key): ?Payload { /* sodium_crypto_sign_verify_detached */ }
}
```

## 5. REST API

Todas as rotas exigem `manage_options` (parent). Sem rotas pro child — o lado infantil não enxerga licença.

| Método | Path | Body | Resposta |
|---|---|---|---|
| GET | `/license` | — | `{plan, status, expiresAt, daysLeft, features, email, activatedAt}` |
| POST | `/license` | `{key: string}` | Mesmo shape de GET; 422 se inválida |
| DELETE | `/license` | — | `{plan: 'free'}` (libera pra ativar em outro domínio) |

**Status possíveis:**
- `none` — sem chave ativa, plano = free
- `active` — chave válida e dentro do prazo
- `expired` — chave válida em formato mas `exp < now`
- `domain_mismatch` — chave válida mas `sub` ≠ siteurl atual (raro; mostra erro pedindo nova chave)
- `revoked` — `jti` na revogação list

## 6. Gating

### 6.1 PHP — `GuardKids\License\Gate`

```php
namespace GuardKids\License;

final class Gate
{
    public function plan(): string;                  // 'free' | 'premium'
    public function can(string $featureId): bool;    // 'browser', 'categories', ...
    public function expiresAt(): ?int;
    public function daysLeft(): ?int;
}
```

Usado em controllers REST como precondição. Exemplo em `SiteController::create()`:

```php
if (! $this->gate->can('unlimited_kids') && $this->children->count() >= 1) {
    return new WP_Error('plan_limit', 'Plano Free permite 1 filho.', ['status' => 402]);
}
```

### 6.2 React — `useLicense()` hook

```ts
function useLicense(): {
  plan: 'free' | 'premium';
  status: 'none' | 'active' | 'expired';
  can: (featureId: string) => boolean;
  daysLeft: number | null;
  expiresAt: string | null;
};
```

Páginas premium chamam `if (!license.can('browser')) return <UpgradeRedirect />;` no topo. UI consistente em vez de spalhar checks.

### 6.3 Mapa feature → id

| Feature | `featureId` | Onde gate aplica |
|---|---|---|
| Navegador infantil seguro | `browser` | `app-child/Browser.tsx` + REST `/sites/whitelist` premium-only |
| Categorias inteligentes | `categories` | `app-parent/SitesRules.tsx` aba categorias |
| Rotina escolar (schedule) | `schedule` | `app-parent/TimeLimits.tsx` aba bedtime |
| Relatórios completos | `reports` | `app-parent/Reports.tsx` (free vê só 7 dias) |
| Localização + Zonas Seguras | `location` | Páginas Localizacao/ZonasSeguras + REST locations/safe-zones |
| Filhos ilimitados | `unlimited_kids` | `ChildController::create` (free trava em 1) |
| Histórico completo | `full_history` | `ReportsController` (free trava range em 7d) |

## 7. Mudanças na UI

### 7.1 `License.tsx`
- Consumir `GET /license` via TanStack Query
- Estados: `none` (mostra CTA pra Upgrade), `active` (mostra dados reais), `expired` (warning + CTA renovar)
- Form "Ativar nova chave" → `POST /license` real
- **Remover** histórico de cobrança (mockado) — não temos backend pra isso
- **Remover** "Transferir licença" — redundante com DELETE + ativar em outro domínio

### 7.2 `Upgrade.tsx`
- Esconder/desabilitar se `plan === 'premium'` ativo
- "Fazer upgrade agora" abre external link (Hotmart/checkout que você configurar)
- `planFeatures` permanece como source of truth do comparativo

### 7.3 Outras páginas
- Cada página premium importa `useLicense()` no topo
- Quando `!can(featureId)`: mostra um `<PremiumLock featureId="…" />` overlay com CTA pra Upgrade
- Componente `<PremiumLock>` consistente — não-destrutivo, deixa ver preview borrado

## 8. CLI emissor

Comando standalone (não distribuído com o plugin), só você usa:

```bash
php scripts/issue-license.php \
  --email=djair@exemplo.com \
  --domain=https://cliente.com \
  --plan=premium \
  --expires=2027-06-08 \
  --features=browser,categories,schedule,reports,location,unlimited_kids,full_history
# saída: chave base64 pra colar no painel do cliente
```

Lê privkey de `~/.guardkids/issuer.key`, monta payload, assina, imprime. Zero deps externos além de `sodium`.

## 9. Decisões resolvidas (alinhadas com o usuário em 2026-06-08)

Esta seção compila as respostas que validaram o design antes da implementação. Todas seguem o **default recomendado** apresentado no review:

1. **Emissão** — CLI manual no PC do dev (`php scripts/issue-license.php`). Sem Apps Script, sem Hotmart webhook na v1.
2. **Histórico de cobrança** — drop completo da UI; pode voltar quando houver webhook real de pagamento.
3. **Premium expirado** — bloqueia só features novas; dados antigos (reports, locations, etc.) continuam visíveis. Não-destrutivo.
4. **Free** — limite de 1 filho cadastrado.
5. **CTA "Upgrade"** — URL configurável via setting `guardkids_upgrade_url`; default vazio esconde botão.
6. **Migração de schema** — **não precisa**. Só `wp_options`, sem bump de `GUARDKIDS_DB_VERSION`.
7. **Testes existentes** — atualizar fixtures de controllers (ChildController, SiteController, etc.) no mesmo PR de gating, como step 7 do plano abaixo.

## 10. Plano de entrega (sequencial)

| # | Etapa | Risco | Reverte? |
|---|---|---|---|
| 1 | `License\Verifier` + `License\Gate` + testes unit | baixo | total |
| 2 | `LicenseController` + rotas REST + testes | baixo | total |
| 3 | CLI `scripts/issue-license.php` (fora do plugin distribuído) | nenhum (não roda em prod) | total |
| 4 | Hook `useLicense()` no app-parent + componente `<PremiumLock>` | baixo | total |
| 5 | Refactor `License.tsx` e `Upgrade.tsx` consumindo API | médio (mexe em mock-data, risco visual) | parcial |
| 6 | Gating nas 7 páginas (PremiumLock onde aplicável) + REST controllers | médio (toca código existente em produção) | parcial |
| 7 | Atualizar testes existentes pra fixture de licença premium | baixo | total |

Total estimado: 2-3h de implementação (sem step 6 aprofundado em todas as páginas).
