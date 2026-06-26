# GuardKids WP

> Controle parental web premium para WordPress — painel dos pais (SPA) + painel infantil (PWA instalável) + REST autenticada, tudo num plugin único.

[![CI](https://github.com/mouradjnet/guardkids-wp/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/mouradjnet/guardkids-wp/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/mouradjnet/guardkids-wp/branch/master/graph/badge.svg)](https://codecov.io/gh/mouradjnet/guardkids-wp)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)](composer.json)
[![WordPress 6.4+](https://img.shields.io/badge/WordPress-6.4%2B-21759B?logo=wordpress&logoColor=white)](guardkids.php)
[![Tests](https://img.shields.io/badge/tests-901%20passing-brightgreen)](#testes)
[![License: GPL-2.0+](https://img.shields.io/badge/license-GPL--2.0%2B-blue)](#licença)

## Visão geral

Plugin WordPress que gerencia controle de tela e navegação de crianças. Toda a configuração vive em 10 tabelas próprias (`wp_guardkids_*`) e é exposta via REST sob o namespace `guardkids/v1` (17 controllers, 47 rotas). Além do controle parental, cobre **segurança da conta** (2FA TOTP + códigos de recuperação, PIN infantil, sessões ativas, auto-logout), **privacidade/LGPD** (exportar + apagar dados), **notificações por email** (resumos diário/semanal) e o **GuardKids Companion** (telemetria via app Android). Dois SPAs Vite/React/TS embarcados no plugin consomem essa REST:

- **`app-parent`** ([`/painel-pais`](#rotas-publicas)) — painel responsável (desktop/mobile). Autentica via cookie do WP + nonce; exige capability `manage_options`.
- **`app-child`** ([`/painel-filho`](#rotas-publicas)) — PWA mobile-first instalável. Autentica via **token de dispositivo** (32 bytes hex) emitido pelo responsável; cada chamada manda `X-GuardKids-Token` no header.

```
┌─────────────────────┐        ┌─────────────────────┐
│  app-parent (SPA)   │        │   app-child (PWA)   │
│  Vite + React + TS  │        │  Vite + React + TS  │
│  cookie + nonce     │        │  X-GuardKids-Token  │
└──────────┬──────────┘        └──────────┬──────────┘
           │                              │
           └───────────────┬──────────────┘
                           ▼
        ┌────────────────────────────────────┐
        │ REST guardkids/v1 (17 ctrl·47 rotas)│
        │  permission_callback escopado      │
        └────────────────┬───────────────────┘
                         ▼
        ┌────────────────────────────────────┐
        │   Plugin PHP (PSR-4 self-contained)│
        │   Controllers · Repositories       │
        │   ChildAuth · MigrationRunner      │
        └────────────────┬───────────────────┘
                         ▼
                  $wpdb (10 tabelas)
```

## Stack

| Camada | Tecnologia |
|---|---|
| Plugin | PHP 8.2+, WordPress 6.4+, single-site, sem dependências de runtime |
| Auth | Cookie do WP + nonce (parent); token de dispositivo hashado SHA-256 em `wp_guardkids_settings` (child) |
| Persistência | `$wpdb->prepare()` em todas as queries; migrations versionadas via `MigrationRunner` |
| Frontend | Vite 5 + React 19 + TypeScript 6 + Tailwind 3 + TanStack Query 5 |
| PWA do child | `vite-plugin-pwa` + Workbox; ícones gerados via `@vite-pwa/assets-generator` |
| Testes | PHPUnit 9.6 (stubs minimos do WP, sem Docker) + Vitest 2 + Testing Library |
| CI | GitHub Actions (4 jobs paralelos: phpunit unit + phpunit integration + 2 vitest) |

## Estrutura do repo

```
guardkids-wp/
├── guardkids.php              # bootstrap do plugin (header + autoloader)
├── uninstall.php              # drop das tabelas + opções
├── composer.json              # só require-dev (PHPUnit)
├── api/                       # REST controllers + RestApi
│   ├── RestApi.php            # registra 47 rotas em guardkids/v1
│   └── Controllers/           # 17: Child, ChildSelf, Site, Category, Settings,
│                              # Request, Reports, Location, SafeZone, License,
│                              # Guardian, Me, Companion, Privacy, Security,
│                              # Sessions, TwoFactor
├── includes/
│   ├── Autoloader.php         # PSR-4 self-contained, 3 roots
│   ├── Plugin.php             # boot + hooks + ativação
│   ├── Auth/                  # ChildAuth (token SHA-256) + ChildPin + GuardianAuth + InviteToken
│   ├── License/               # Verifier (Ed25519) + Gate + Payload (gating premium)
│   ├── Schedule/              # avaliação de bedtime/weekday limits
│   ├── Security/              # 2FA (Totp/TwoFactorStore/TwoFactorLogin) + RecoveryCodes +
│   │                          # sessões (SessionManager/Presenter) + RateLimiter +
│   │                          # SecurityHeaders (6 headers) + RestHeaders + UserAgent
│   ├── Notifications/         # DigestData + DigestMailer (resumos diário/semanal por email)
│   ├── Privacy/               # PrivacyExporter + PrivacyEraser (LGPD)
│   ├── Maintenance/Purger.php # GC de requests/pairing antigos (cron)
│   ├── Invite/                # aceite de convite de guardião
│   └── Ui/                    # ParentApp + ChildApp + AcceptInviteApp (servem os SPAs)
├── database/
│   ├── MigrationRunner.php
│   ├── Repository.php         # base CRUD com prepare()
│   ├── {Child,Request,Site,Category,Settings,UsageEvent,Location,SafeZone,Guardian,CompanionDevice}Repository.php
│   └── migrations/            # 001..012 (DB version 12)
├── public/
│   ├── app-parent/            # SPA do responsável (Vite + React)
│   └── app-child/             # PWA infantil (Vite + React + Workbox)
├── tests/{Unit,Integration,Support}/  # PHPUnit (352 unit + 196 integration)
└── docs/superpowers/{specs,plans}/  # design + roadmap
```

## Setup dev (Windows + LocalWP)

Pré-requisitos:

- **LocalWP** rodando um site `guardkids-wp.local` (PHP 8.2, MySQL).
- **Node 20+** e **pnpm 10+** no PATH.
- **PHP 8.2 CLI** (o do LocalWP serve — fica em `~/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe`).
- **composer.phar** local (PHP global usado no `php composer.phar install`).

```powershell
# 1. Clonar e linkar como junction no LocalWP
git clone https://github.com/mouradjnet/guardkids-wp.git
cd guardkids-wp
# (PowerShell admin) — cria junction NTFS pra LocalWP enxergar como plugin
New-Item -ItemType Junction `
  -Path "$env:USERPROFILE\Local Sites\guardkids-wp\app\public\wp-content\plugins\guardkids-wp" `
  -Target (Resolve-Path .).Path

# 2. PHP deps (com extensões necessárias)
$php = "$env:APPDATA\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe"
& $php -d extension_dir="$(Split-Path $php)\ext" `
       -d extension=openssl -d extension=mbstring -d extension=zip -d extension=fileinfo `
       "$env:USERPROFILE\bin\composer.phar" install

# 3. Frontend deps + build inicial (cada app)
cd public/app-parent ; pnpm install ; pnpm build ; cd ../..
cd public/app-child  ; pnpm install ; pnpm build ; cd ../..

# 4. Ativar o plugin em http://guardkids-wp.local/wp-admin/plugins.php
```

Para integração REST funcionar fora de produção, copie `public/app-parent/.env.example` para `.env.local` e preencha com seu **Application Password** (criado em wp-admin → Users → Profile → Application Passwords).

## Rotas públicas

| URL | Audiência | Auth |
|---|---|---|
| `/painel-pais` | Responsável (admin WP) | Cookie WP + nonce; exige `manage_options` |
| `/painel-filho` | Dispositivo da criança | Token X-GuardKids-Token (pareado pelo parent) |
| `/painel-filho/sw.js` | Service Worker | Servido com `Service-Worker-Allowed: /painel-filho/` |
| `/wp-json/guardkids/v1/*` | REST | nonce ou token, dependendo da rota |

## Testes

**PHPUnit Unit (352 tests):**

```powershell
& $php -d extension_dir="$(Split-Path $php)\ext" `
       -d extension=openssl -d extension=mbstring -d extension=zip -d extension=fileinfo `
       vendor/bin/phpunit --testsuite unit
```

Cobre Repository base + subclasses (Child, Request, Site, Category, Settings, UsageEvent, Location, SafeZone, Guardian, CompanionDevice), ChildAuth + ChildPin + GuardianAuth (resolve role efetiva), InviteToken (generate + sha256 hash), MigrationRunner (idempotência + ordem), RestHeaders + SecurityHeaders (escopo de namespace + 6 headers), Schedule (ScheduleEvaluator), License (Verifier Ed25519 + Gate + gating em controllers), Security (Totp/TwoFactorStore/TwoFactorLogin, RecoveryCodes, SessionManager, RateLimiter), Notifications (DigestData/DigestMailer), Privacy (Exporter/Eraser) e os controllers REST (inclui MeController).

> No Windows local, `MigrationRunnerTest` pode falhar por causa do `glob()` sobre `C:\Windows\TEMP` (o `\W`/`\T` viram escape) — é artefato do ambiente, não do código; a CI (Linux) roda a suíte verde.

**PHPUnit Integration (MySQL real, 196 tests):**

```powershell
# 1) sobe MySQL 8 em :3307 (porta dedicada, nao colide com LocalWP)
docker compose -f docker-compose.test.yml up -d

# 2) roda a suite integration (config + env vars em phpunit-integration.xml.dist)
& $php -d extension_dir="$(Split-Path $php)\ext" `
       -d extension=mbstring -d extension=mysqli `
       vendor/bin/phpunit -c phpunit-integration.xml.dist
```

Valida contra MySQL real (não stubs) que migrations rodam idempotentes, queries dos Repositories executam corretamente e o ciclo REST → DB → response dos controllers se comporta como esperado. Cobre:

- **8 Repository tests** (60 testes): Child, Request, UsageEvent, Location, SafeZone, Site, Category, Settings — incluindo agregações reais (`SUM`/`GROUP BY DATE()`/subquery), janela diária `minutesUsedInWindow`, UNIQUE constraints, precisão `DECIMAL(10,7)` e defaults de schema.
- **Api tests** (136 testes): Child, ChildSelf (PWA infantil + auth por token), Site, Category, Settings, Request (approve/deny), Reports (KPIs/topSites/perChild), Location, SafeZone, License (Ed25519 + persistência cross-instance + rollback), Guardian (lazy-seed do current user + last_admin + self_delete guards), mais **RolePermissions** (`RestApi::requireAdmin` / `requireCollaboratorOrAbove` contra cenários reais: manage_options, collaborator/admin guardian, pending bloqueia, email fallback, random user → 403).

**Vitest app-parent (292 tests) + app-child (61 tests):**

```powershell
cd public/app-parent
pnpm test        # corrida única
pnpm test:watch  # modo watch
```

Cobre `api/client.ts` (auth dupla, parse de WP_Error), helpers (`requestDisplay`, `children`, `exportReportCsv`), diálogos (`AddChildDialog`, `PairDeviceDialog`, `PendingRequests`), navegação (`TopNav`/`SideNav`/`BottomNav`), `PremiumLock` + hook `useLicense` e todas as 12 páginas do parent (Dashboard, Children, SitesRules, TimeLimits, Approvals, Reports, Settings, License, Upgrade, Localizacao, ZonasSeguras, ProtectionMode). No `app-child`, cobre `usageTracker` + `locationTracker` e todas as 7 páginas (Home, Alerts, Blocked, Browser, PairScreen, Localizacao, Requests) — e2e Playwright opcional via `pnpm test:e2e` (depois de `pnpm test:e2e:install`).

**CI** roda os dois automaticamente em cada push/PR. Status: badge no topo.

**Coverage** é enviado pro Codecov via OIDC tokenless. Pra o dashboard processar, o repo precisa estar ativado uma vez em [app.codecov.io](https://app.codecov.io/login/gh) (login com GitHub → autorizar acesso). Depois disso o badge mostra o % real automaticamente.

## Roadmap

Documentação detalhada em [`docs/superpowers/`](docs/superpowers/):

- [Design atual](docs/superpowers/specs/2026-05-21-guardkids-wp-fundacao-design.md) — schema, autenticação, REST endpoints, segurança.
- [Plano de implementação](docs/superpowers/plans/2026-05-21-guardkids-wp-fundacao-plan.md) — fases entregues + próximos passos.

Fundação completa: schema, autenticação dupla (parent + child), 17 controllers REST (47 rotas) + endpoint `/me`, License premium (Ed25519), Schedule (bedtime/weekday + limite diário de tela opt-in), Reports, Localização (Locations + SafeZones), gestão de Guardiões da família (admin/colaborador + lazy-seed do current user), permissões por role (admin vê tudo; collaborator só Painel + Aprovações), accept-invite real (`/aceitar-convite/{token}` cria WP user + ativa guardian automaticamente).

Entregue além da fundação:

- **Segurança da conta** — 2FA TOTP + códigos de recuperação, PIN do painel infantil, sessões ativas (listar/encerrar outras), auto-logout por inatividade, rate limiting e 6 security headers globais.
- **Privacidade / LGPD** — exportar todos os dados (omite tokens) e apagar conta (zera as tabelas, preserva guardiões/licença).
- **Notificações por email** — resumos diário/semanal opt-in via cron (`DigestData` + `DigestMailer`).
- **GuardKids Companion** — pareamento + telemetria via app Android (token com expiração deslizante, kill-switch e rate-limit).

Full suite de testes: **901 testes** (352 unit + 196 integration + 292 vitest parent + 61 vitest child).

## Licença

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html). Padrão de plugins WordPress.
