# GuardKids Companion — MVP (telemetria) — Design

**Data:** 2026-06-23
**Status:** Aprovado (design) — aguardando plano de implementação
**Escopo:** App Android nativo que pareia com o GuardKids WP e reporta telemetria. **Sem enforcement de bloqueio** (fase seguinte).

## Contexto

O backend do Companion já existe e está em produção (guardkids-wp v1.8.0, deploy 2026-06-23). Ele expõe, no namespace REST `guardkids/v1`, o fluxo completo de pareamento e telemetria:

- `POST /companion/pair` (admin) — gera pairing token efêmero (10 min) + `qrPayload` JSON pro QR.
- `POST /companion/enroll` (pairing token no header) — troca o pairing token por um **session token persistente**; marca o device `active`; consome o pairing token (uso único).
- `POST /companion/sync` (session token) — reporta device info + flags de permissão; persiste em `wp_guardkids_companion_devices`.
- `POST /companion/heartbeat` (session token) — toca `last_sync`, mantém o device vivo.
- `GET /companion/status?child_id=N` (admin) — o painel lê o estado do device.

O painel dos pais (app-parent) já tem `CompanionWizard` (gera QR, faz polling de status) e `CompanionStatusCard` (mostra 5 flags + última sync). **Falta só o cliente Android** — hoje todo esse backend está testado mas sem consumidor real.

Auth (header `X-GuardKids-Companion-Token`):
- Pairing token: 64 hex, efêmero, uso único, só serve pra `/enroll`.
- Session token: 64 hex, persistente (sem expiry), usado em `sync`/`heartbeat`. Hash mora na linha do device; re-parear revoga.

Schema `companion_devices` (campos relevantes): `device_uuid`, `session_token_hash`, `device_name`, `android_version`, `companion_version`, `last_sync`, `status`, e as flags `device_owner_enabled`, `accessibility_enabled`, `device_admin_enabled`, `play_store_enabled`, `settings_locked`, `kiosk_mode`, `device_shutdown_protection`, `allowed_apps`/`blocked_apps` (JSON, reservados pro enforcement).

## Decisões de produto (alinhadas)

| Decisão | Escolha | Por quê |
|---|---|---|
| **Escopo do MVP** | Telemetria-only | Prova o pipeline ponta-a-ponta contra backend + painel já prontos; de-risca o provisioning Android pesado pra uma fase seguinte. |
| **Stack** | Kotlin nativo (Jetpack Compose) | App é Android-only por natureza; o enforcement futuro (Device Owner/Accessibility) é 100% API nativa — começar em Kotlin faz o enforcement virar extensão, não reescrita. |
| **Repo** | Novo: `guardkids-companion` (privado, `mouradjnet`) | Toolchain Gradle/Android e cadência Play Store são distintos do plugin WP. |
| **Profundidade de permissões** | Reportar status REAL (sem provisionar) | Exercita o `CompanionStatusCard` de verdade lendo o estado atual das flags, sem construir as telas-guia de provisioning (fase de enforcement). |
| **Background** | WorkManager periódico | Battery/política-friendly, sem notificação persistente. Foreground service fica pro enforcement always-on. |
| **minSdk** | 26 (Android 8+) | Cobre ~95% dos devices; WorkManager/Keystore maduros. |

## Não-objetivos (explícito — fora do MVP)

- Bloqueio efetivo de apps (allowed_apps/blocked_apps) — fase de enforcement.
- Provisioning de Device Owner / Accessibility / Device Admin (telas-guia, deep-links pras Settings).
- Kiosk mode, anti-uninstall, proteção contra desligamento, settings lock.
- Foreground service always-on / heartbeat em tempo-real.
- iOS (não há equivalente; produto é Android-only).
- Publicação na Play Store (build debug/interno basta pro MVP).

## Arquitetura

App de **camada única e enxuta** (sem Clean Architecture multicamada pra um MVP). Pacote `site.guardiaokids.companion`.

```
┌──────────────────────────── UI (Jetpack Compose) ────────────────────────────┐
│  PairingScreen (scan QR)   EnrollingScreen (loading/erro)   StatusScreen      │
└───────────────────────────────────┬──────────────────────────────────────────┘
                                     │ observa
                          ┌──────────▼───────────┐
                          │   CompanionViewModel  │  (estado de UI + orquestra)
                          └──────────┬───────────┘
            ┌────────────────────────┼────────────────────────┐
            ▼                        ▼                         ▼
   ┌─────────────────┐     ┌──────────────────┐      ┌──────────────────┐
   │   CompanionApi  │     │    TokenStore    │      │   DeviceState    │
   │ (Retrofit/OkHttp)│    │ (Encrypted prefs)│      │ (lê flags do OS) │
   └─────────────────┘     └──────────────────┘      └──────────────────┘

   Background:  HeartbeatWorker (WorkManager periódico)  +  BootReceiver (re-agenda)
```

### Componentes (cada um com propósito único)

- **`CompanionApi`** — interface Retrofit pro `guardkids/v1`: `enroll(pairingToken)`, `sync(sessionToken, body)`, `heartbeat(sessionToken)`. Base URL vem do `qrPayload.api`. Injeta o header `X-GuardKids-Companion-Token` via interceptor.
- **`TokenStore`** — wrapper sobre `EncryptedSharedPreferences` (Keystore-backed). Guarda `sessionToken`, `deviceUuid`, `apiBaseUrl`, `childId`. Métodos: `save(...)`, `read(): Session?`, `clear()`. Nunca loga o token.
- **`DeviceState`** — função pura-ish que coleta o snapshot a reportar: `androidVersion` (`Build.VERSION.RELEASE`), `companionVersion` (`BuildConfig.VERSION_NAME`), `accessibilityEnabled` (`Settings.Secure.ENABLED_ACCESSIBILITY_SERVICES` contém o serviço), `deviceAdminEnabled` (`DevicePolicyManager.isAdminActive`), `deviceOwnerEnabled` (`isDeviceOwnerApp`), `playStoreEnabled` (Play Store instalado/habilitado). Flags de enforcement reportam `false` no MVP.
- **`QrPayload`** — data class + parser do JSON do QR (`{v, type, child, uuid, tok, api}`). Valida `type == "gk-companion-pair"` e `v == 1`.
- **`CompanionViewModel`** — máquina de estados de UI: `Unpaired → Scanning → Enrolling → (Paired | Error)`. Orquestra enroll, primeiro sync e agenda o worker.
- **`HeartbeatWorker`** — `CoroutineWorker` periódico (intervalo 30 min, flexível). Lê `TokenStore`; se há sessão, faz `heartbeat`. Se `DeviceState` mudou desde a última, faz `sync` completo. Em 401, limpa `TokenStore` e cancela o trabalho.
- **`BootReceiver`** — `BOOT_COMPLETED` re-agenda o `HeartbeatWorker` (WorkManager já persiste, mas o receiver garante após force-stop/update).

### Telas (Compose)

1. **PairingScreen** — scanner de QR via `journeyapps/zxing-android-embedded` (drop-in, menos código pro MVP; ML Kit + CameraX fica como swap futuro se precisar de scanner Compose-nativo). Texto guia. Permissão de câmera (runtime).
2. **EnrollingScreen** — spinner enquanto `enroll` + primeiro `sync` rodam; estado de erro com botão "tentar de novo".
3. **StatusScreen** — device pareado: nome (ou "Dispositivo de {child}"), última sync, lista das 5 flags com check/x, botão "Desparear" (limpa TokenStore local — o painel revoga de fato re-pareando).

## Fluxo de dados

1. **Pairing:** painel gera QR via `/pair`. App escaneia → parseia `QrPayload` → extrai `tok` (pairing), `uuid`, `api`, `child`.
2. **Enroll:** `POST {api}/companion/enroll` com `tok` no header → resposta `{sessionToken, deviceUuid}` → `TokenStore.save`. Pairing token descartado (nunca persistido).
3. **Sync inicial:** `POST {api}/companion/sync` com session token + `DeviceState` → device vira `active`; aparece no `CompanionStatusCard`.
4. **Heartbeat periódico:** `HeartbeatWorker` a cada ~30 min → `heartbeat`; `sync` quando flags mudam.
5. **Revogação:** qualquer 401 (sessão revogada via re-pareamento no painel) → `TokenStore.clear()` → UI volta pra `PairingScreen`.

## Erros e segurança

- **Token at rest:** só em `EncryptedSharedPreferences` (Keystore). Nunca em log/crash report. Pairing token vive só em memória durante o enroll.
- **Rede:** OkHttp com timeout + retry; `HeartbeatWorker` usa backoff do WorkManager. Heartbeat/sync são idempotentes no backend.
- **401 = re-pareamento limpo:** não trava o app, não loga out "sujo" — limpa estado e leva pra Pairing.
- **QR inválido/estranho:** parser rejeita `type`/`v` errados; UI mostra erro sem crashar.
- **Sem permissão de câmera:** estado de UI explicativo + deep-link pras Settings.

## Testes

- **Unit (JVM/JUnit + MockK):**
  - `QrPayload` parse (válido, type errado, v errado, JSON malformado).
  - `DeviceState` com `DevicePolicyManager`/`Settings` mockados (cada flag true/false).
  - `TokenStore` save/read/clear (com fake de prefs).
  - `CompanionViewModel` transições de estado (enroll ok → Paired; 401 → Unpaired; erro de rede → Error).
  - Mapeamento resposta da API → modelo de UI.
- **Instrumentado (mínimo):** 1 fluxo `enroll → sync` contra `MockWebServer` (valida header do token e corpo do sync).
- **Smoke manual:** backend real no LocalWP — gera QR no `CompanionWizard`, escaneia no device/emulador, confirma device `active` + flags no `CompanionStatusCard`.

## Repo e CI

- **Repo novo** `guardkids-companion` (privado, `mouradjnet`). Author: Djair Falcão. License: proprietária (não GPL — é app cliente, não derivado do WP).
- **CI** (GitHub Actions): `./gradlew testDebugUnitTest lintDebug assembleDebug` em push/PR.
- **Build:** `minSdk 26`, `targetSdk` atual, Kotlin + Compose BOM. `applicationId` `site.guardiaokids.companion`.
- **Versionamento:** começa em `0.1.0` (`versionName`) / `versionCode 1`.

## Critérios de aceite do MVP

1. Escanear o QR gerado pelo painel pareia o device (enroll → session token persistido).
2. Após parear, o device aparece `active` no `CompanionStatusCard` do painel, com versão Android/app e as 5 flags refletindo o estado real do device.
3. O heartbeat periódico mantém `last_sync` atualizando sem o app aberto em foreground (validável após ~30 min ou forçando o worker).
4. Re-parear no painel (que revoga a sessão) faz o app cair pra tela de Pairing no próximo sync/heartbeat (401 tratado).
5. Suíte unit verde + 1 teste instrumentado de enroll/sync + CI verde.

## Roadmap pós-MVP (fora deste spec)

- **Fase Enforcement:** provisioning Device Owner (QR enterprise / ADB) + Accessibility; bloqueio efetivo via `allowed_apps`/`blocked_apps`; foreground service always-on; kiosk/anti-uninstall.
- **Fase Distribuição:** Play Store (internal testing → produção), signing, política de privacidade.
