# GuardKids WP

> Controle parental web premium para WordPress — painel dos pais (SPA) + painel infantil (PWA instalável) + REST autenticada, tudo num plugin único.

[![CI](https://github.com/mouradjnet/guardkids-wp/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/mouradjnet/guardkids-wp/actions/workflows/ci.yml)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](composer.json)
[![WordPress 6.4+](https://img.shields.io/badge/WordPress-6.4%2B-21759B?logo=wordpress&logoColor=white)](guardkids.php)
[![Tests](https://img.shields.io/badge/tests-72%20passing-brightgreen)](#testes)
[![License: GPL-2.0+](https://img.shields.io/badge/license-GPL--2.0%2B-blue)](#licença)

## Visão geral

Plugin WordPress que gerencia controle de tela e navegação de crianças. Toda a configuração vive em 5 tabelas próprias (`wp_guardkids_*`) e é exposta via REST sob o namespace `guardkids/v1`. Dois SPAs Vite/React/TS embarcados no plugin consomem essa REST:

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
        │  REST  guardkids/v1  (16 rotas)    │
        │  permission_callback escopado      │
        └────────────────┬───────────────────┘
                         ▼
        ┌────────────────────────────────────┐
        │   Plugin PHP (PSR-4 self-contained)│
        │   Controllers · Repositories       │
        │   ChildAuth · MigrationRunner      │
        └────────────────┬───────────────────┘
                         ▼
                  $wpdb (5 tabelas)
```

## Stack

| Camada | Tecnologia |
|---|---|
| Plugin | PHP 8.1+, WordPress 6.4+, single-site, sem dependências de runtime |
| Auth | Cookie do WP + nonce (parent); token de dispositivo hashado SHA-256 em `wp_guardkids_settings` (child) |
| Persistência | `$wpdb->prepare()` em todas as queries; migrations versionadas via `MigrationRunner` |
| Frontend | Vite 5 + React 19 + TypeScript 6 + Tailwind 3 + TanStack Query 5 |
| PWA do child | `vite-plugin-pwa` + Workbox; ícones gerados via `@vite-pwa/assets-generator` |
| Testes | PHPUnit 9.6 (stubs minimos do WP, sem Docker) + Vitest 2 + Testing Library |
| CI | GitHub Actions (3 jobs paralelos: phpunit + 2 builds + vitest) |

## Estrutura do repo

```
guardkids-wp/
├── guardkids.php              # bootstrap do plugin (header + autoloader)
├── uninstall.php              # drop das tabelas + opções
├── composer.json              # só require-dev (PHPUnit)
├── api/                       # REST controllers + RestApi
│   ├── RestApi.php            # registra 16 rotas em guardkids/v1
│   └── Controllers/
├── includes/
│   ├── Autoloader.php         # PSR-4 self-contained, 3 roots
│   ├── Plugin.php             # boot + hooks + ativação
│   ├── Auth/ChildAuth.php     # token de dispositivo (SHA-256)
│   ├── Security/RestHeaders.php # nosniff + Referrer-Policy + DENY + noindex
│   └── Ui/                    # ParentApp + ChildApp (servem os SPAs)
├── database/
│   ├── MigrationRunner.php
│   ├── Repository.php         # base CRUD com prepare()
│   ├── {Child,Request,Site,Category,Settings}Repository.php
│   └── migrations/001_initial_schema.php
├── public/
│   ├── app-parent/            # SPA do responsável (Vite + React)
│   └── app-child/             # PWA infantil (Vite + React + Workbox)
├── tests/                     # PHPUnit unit tests (42)
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

**PHPUnit (42 tests, 85 assertions):**

```powershell
& $php -d extension_dir="$(Split-Path $php)\ext" `
       -d extension=openssl -d extension=mbstring -d extension=zip -d extension=fileinfo `
       vendor/bin/phpunit
```

Cobre Repository base + subclasses, ChildAuth (token + lookup), MigrationRunner (idempotência + ordem), RestHeaders (escopo de namespace).

**Vitest (30 tests):**

```powershell
cd public/app-parent
pnpm test        # corrida única
pnpm test:watch  # modo watch
```

Cobre `api/client.ts` (auth dupla, parse de WP_Error), `lib/requestDisplay.ts` (helpers), `AddChildDialog` e `PairDeviceDialog` (form + clipboard fallback).

**CI** roda os dois automaticamente em cada push/PR. Status: badge no topo.

## Roadmap

Documentação detalhada em [`docs/superpowers/`](docs/superpowers/):

- [Design atual](docs/superpowers/specs/2026-05-21-guardkids-wp-fundacao-design.md) — schema, autenticação, REST endpoints, segurança.
- [Plano de implementação](docs/superpowers/plans/2026-05-21-guardkids-wp-fundacao-plan.md) — fases entregues + próximos passos.

Itens em aberto (não bloqueantes):

- Schedule/bedtime/weekday limits (depende de migration 002 com tabela de schedule).
- Reports/usage tracking (depende de tabela de uso/atividade).
- License/billing (premium gating).
- Integration tests dos controllers contra MySQL real.

## Licença

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html). Padrão de plugins WordPress.
