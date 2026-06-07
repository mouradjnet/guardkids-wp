# Location + Safe Zones Implementation Plan

> **For agentic workers:** Use superpowers:subagent-driven-development ou superpowers:executing-plans. Steps usam checkbox (`- [ ]`) syntax.

**Goal:** Entregar a base de localização do GuardKids — POST do PWA child gravando posição quando setting on, GET pro parent mostrar última posição num mapa Leaflet, e CRUD de zonas seguras com picker no mapa.

**Architecture:** Migration 004 cria `wp_guardkids_locations` (append-only) + `wp_guardkids_safe_zones`. `ChildSelfController::reportLocation` valida `location_enabled` em `wp_guardkids_settings` (fail-closed) e grava. `LocationController::index` e `SafeZoneController` (CRUD) servem o parent. SPA parent ganha 2 páginas com Leaflet+OSM. PWA child ganha tela de consentimento + tracker foreground-only (Page Visibility + throttle 60s/50m).

**Tech Stack:** PHP 8.1+ / WordPress 6.4+, PHPUnit 9.6, React 19 + Vite + TS + TanStack Query 5, Vitest 2, Leaflet 1.9 + react-leaflet 4.

**Spec:** `docs/superpowers/specs/2026-06-07-location-safezones-design.md`.

---

## File Structure

**Backend (PHP):**
- Create: `database/migrations/004_locations_and_safe_zones.php`
- Create: `database/LocationRepository.php`
- Create: `database/SafeZoneRepository.php`
- Create: `api/Controllers/LocationController.php`
- Create: `api/Controllers/SafeZoneController.php`
- Modify: `api/Controllers/ChildSelfController.php` — método `reportLocation()`
- Modify: `api/RestApi.php` — registra `/locations`, `/safe-zones`, `/child/location`
- Modify: `database/SettingsRepository.php` — helper `isLocationEnabled()`
- Modify: `guardkids.php` — `GUARDKIDS_DB_VERSION: 3 → 4`
- Create: `tests/Unit/Database/LocationRepositoryTest.php`
- Create: `tests/Unit/Database/SafeZoneRepositoryTest.php`
- Create: `tests/Unit/Api/ChildSelfLocationTest.php`
- Create: `tests/Unit/Api/LocationControllerTest.php`
- Create: `tests/Unit/Api/SafeZoneControllerTest.php`
- Modify: `tests/Unit/Database/MigrationRunnerTest.php` — caso 004
- Modify: `tests/Unit/Database/SettingsRepositoryTest.php` — caso `isLocationEnabled()`

**Frontend parent (`public/app-parent/`):**
- Modify: `package.json` — `leaflet`, `react-leaflet`, `@types/leaflet`
- Modify: `src/main.tsx` — `import 'leaflet/dist/leaflet.css'`
- Modify: `src/api/types.ts` — `LocationFix`, `SafeZone`
- Create: `src/api/locations.ts`
- Create: `src/api/safeZones.ts`
- Create: `src/pages/Localizacao.tsx`
- Create: `src/pages/ZonasSeguras.tsx`
- Create: `src/components/SafeZoneDialog.tsx`
- Modify: `src/data/mockData.ts` — `PageId` ganha `'location' | 'safe-zones'`
- Modify: `src/App.tsx` — 2 cases novos no switch
- Modify: `src/components/SideNav.tsx` — 2 itens novos
- Modify: `src/components/BottomNav.tsx` — 2 itens novos
- Modify: `src/pages/Settings.tsx` — toggle `location_enabled`
- Create: `src/api/locations.test.ts`
- Create: `src/api/safeZones.test.ts`
- Create: `src/pages/Localizacao.test.tsx`
- Create: `src/pages/ZonasSeguras.test.tsx`

**Frontend child (`public/app-child/`):**
- Create: `src/lib/locationTracker.ts`
- Create: `src/api/location.ts` — `postLocation(token, fix)`
- Create: `src/pages/Localizacao.tsx`
- Modify: `src/App.tsx` — registra tracker quando token presente + setting on
- Create: `src/lib/locationTracker.test.ts`
- Create: `src/pages/Localizacao.test.tsx`

**Docs:**
- Modify: `README.md` — atualizar contador de testes ao final da fase.

---

## Task 1: Migration 004 — schema novo

**Files:**
- Create: `database/migrations/004_locations_and_safe_zones.php`
- Modify: `tests/Unit/Database/MigrationRunnerTest.php`
- Modify: `guardkids.php:22`

- [ ] **Step 1.1: Criar migration 004**

Closure recebe `$wpdb, $charsetCollate`. Define `$prefix = $wpdb->prefix . 'guardkids_'`. Roda `dbDelta` em 2 `CREATE TABLE`:
- `{$prefix}locations` conforme schema da spec §3.
- `{$prefix}safe_zones` conforme schema da spec §3.

- [ ] **Step 1.2: Bump `GUARDKIDS_DB_VERSION` 3 → 4** em `guardkids.php` linha 22.

- [ ] **Step 1.3: Caso novo em `MigrationRunnerTest`** — confirma `004_locations_and_safe_zones.php` é descoberta junto com 001/002/003.

- [ ] **Step 1.4: Rodar PHPUnit** — esperado: suite verde com 1 case a mais.

- [ ] **Step 1.5: Commit**

```
feat(db): migration 004 adiciona locations + safe_zones
```

---

## Task 2: Repositories

**Files:**
- Create: `database/LocationRepository.php`
- Create: `database/SafeZoneRepository.php`
- Create: `tests/Unit/Database/LocationRepositoryTest.php`
- Create: `tests/Unit/Database/SafeZoneRepositoryTest.php`

- [ ] **Step 2.1: `LocationRepository`** — `extends Repository`. Override `table()` retorna `'guardkids_locations'`. Métodos:
  - `findLastByChildId(int $childId): ?array` — `$wpdb->prepare("SELECT * FROM {table} WHERE child_id = %d ORDER BY recorded_at DESC LIMIT 1", $childId)`.
  - `findByChildId(int $childId, int $limit = 50): array` — mesmo padrão com `LIMIT %d`.

- [ ] **Step 2.2: `SafeZoneRepository`** — `extends Repository`. Override `table()` retorna `'guardkids_safe_zones'`. CRUD do base suficiente. Adicionar override `findAll(string $orderBy = 'name', string $direction = 'ASC')` se base não suportar default por param.

- [ ] **Step 2.3: Testes Location** — herdar de `RepositoryTestCase` (ver `RepositoryTest.php` pra padrão). 4 cases: insert + findLast, findByChildId respeita limit, findLast retorna null em child sem registro, ordem desc por `recorded_at`.

- [ ] **Step 2.4: Testes SafeZone** — 3 cases: CRUD básico, findAll ordena por name asc, delete remove.

- [ ] **Step 2.5: Rodar PHPUnit** — verify: 7 cases novos passam.

- [ ] **Step 2.6: Commit**

```
feat(db): LocationRepository + SafeZoneRepository
```

---

## Task 3: SettingsRepository helper + setting wire-up

**Files:**
- Modify: `database/SettingsRepository.php`
- Modify: `tests/Unit/Database/SettingsRepositoryTest.php`

- [ ] **Step 3.1: `isLocationEnabled(): bool`** — retorna `($this->getValue('location_enabled') ?? '') === '1'`.

- [ ] **Step 3.2: Teste** — `setValue('location_enabled', '1') ⇒ isLocationEnabled() === true`; sem set ou `''` ⇒ `false`; `'0'` ⇒ `false`.

- [ ] **Step 3.3: Commit**

```
feat(settings): isLocationEnabled() helper
```

---

## Task 4: ChildSelfController::reportLocation

**Files:**
- Modify: `api/Controllers/ChildSelfController.php`
- Modify: `api/RestApi.php`
- Create: `tests/Unit/Api/ChildSelfLocationTest.php`

- [ ] **Step 4.1: Injetar `SettingsRepository` e `LocationRepository`** no `__construct` do `ChildSelfController` (padrão do projeto: instanciar direto, sem DI).

- [ ] **Step 4.2: Método `reportLocation(WP_REST_Request $req)`** — pseudocódigo:
  ```
  if (!$settings->isLocationEnabled()) return WP_Error('location_disabled', ..., 403)
  $childId = $this->auth->resolveChildId($req); // já existe
  $now = current_time('mysql', true);
  $id = $location->insert([
    'child_id' => $childId,
    'latitude' => $req['latitude'],
    'longitude' => $req['longitude'],
    'accuracy' => $req['accuracy'] ?? null,
    'battery'  => $req['battery'] ?? null,
    'recorded_at' => $now,
    'created_at'  => $now,
  ]);
  return response 201 { id, recordedAt: $now }
  ```

- [ ] **Step 4.3: Registrar rota** em `RestApi::registerChildSelfRoutes()` conforme spec §5.1.

- [ ] **Step 4.4: Testes (~5)** seguindo padrão de `ChildSelfMeScheduleTest`:
  - setting on + lat/lng válidos → 201, retorna `id` + `recordedAt`.
  - setting off → 403, `code=location_disabled`.
  - lat fora de range → 400 (validação WP nativa via args).
  - accuracy null → ok.
  - battery null → ok.

- [ ] **Step 4.5: Rodar PHPUnit** — verify verde.

- [ ] **Step 4.6: Commit**

```
feat(api): POST /child/location com setting fail-closed
```

---

## Task 5: LocationController (parent GET)

**Files:**
- Create: `api/Controllers/LocationController.php`
- Modify: `api/RestApi.php` — método novo `registerLocationsRoutes`
- Create: `tests/Unit/Api/LocationControllerTest.php`

- [ ] **Step 5.1: `LocationController::index`** — args `child_id` (required int), `limit` (default 1, max 100). Retorna `array_map(toJson, $repo->findByChildId(...))`.

- [ ] **Step 5.2: `toJson` privado** — converte snake_case → camelCase; `recordedAt` em ISO-8601 UTC com `gmdate('Y-m-d\TH:i:s\Z', strtotime($row['recorded_at']))`.

- [ ] **Step 5.3: Registrar** em `RestApi::registerLocationsRoutes()` chamado do `registerRoutes()`.

- [ ] **Step 5.4: Testes (~3)** — filter por child_id, respeita limit, vazio retorna `[]`.

- [ ] **Step 5.5: Commit**

```
feat(api): GET /locations pra parent
```

---

## Task 6: SafeZoneController (CRUD parent)

**Files:**
- Create: `api/Controllers/SafeZoneController.php`
- Modify: `api/RestApi.php` — `registerSafeZonesRoutes`
- Create: `tests/Unit/Api/SafeZoneControllerTest.php`

- [ ] **Step 6.1: Controller** — `index`, `create`, `update`, `destroy`. `createArgs`/`updateArgs` conforme spec §5.3. `toJson` com `radiusMeters` em camelCase + `createdAt`/`updatedAt` UTC.

- [ ] **Step 6.2: Registrar rotas** — `/safe-zones` (GET, POST), `/safe-zones/:id` (PUT, DELETE).

- [ ] **Step 6.3: Testes (~6)** — CRUD completo, validação 400 em radius < 10 e > 5000, sanitize_text_field em name.

- [ ] **Step 6.4: Commit**

```
feat(api): /safe-zones CRUD
```

---

## Task 7: SPA parent — deps Leaflet + clients REST + types

**Files:**
- Modify: `public/app-parent/package.json`
- Modify: `public/app-parent/src/main.tsx`
- Modify: `public/app-parent/src/api/types.ts`
- Create: `public/app-parent/src/api/locations.ts`
- Create: `public/app-parent/src/api/safeZones.ts`
- Create: `public/app-parent/src/api/locations.test.ts`
- Create: `public/app-parent/src/api/safeZones.test.ts`

- [ ] **Step 7.1: pnpm install** das deps Leaflet conforme spec §7.1.

- [ ] **Step 7.2: `main.tsx`** — adicionar `import 'leaflet/dist/leaflet.css'` no topo.

- [ ] **Step 7.3: Tipos `LocationFix` + `SafeZone`** conforme spec §7.2.

- [ ] **Step 7.4: `api/locations.ts`** — `listLocations(childId: number, limit = 1)`. Segue padrão de `children.ts` (usa `apiFetch` + types do `client.ts`).

- [ ] **Step 7.5: `api/safeZones.ts`** — 4 funções (list/create/update/delete).

- [ ] **Step 7.6: Testes Vitest** — espelha `children.test.ts` (mock de `apiFetch`, assert request shape + parse).

- [ ] **Step 7.7: Commit**

```
feat(parent): clients REST + Leaflet deps
```

---

## Task 8: SPA parent — página Localização

**Files:**
- Create: `public/app-parent/src/pages/Localizacao.tsx`
- Create: `public/app-parent/src/pages/Localizacao.test.tsx`

- [ ] **Step 8.1: Componente** — `useQuery(['children'])` pra dropdown; `useQuery(['location', childId, 1])` pro mapa, `refetchInterval: 60_000`, `refetchOnWindowFocus: true`.

- [ ] **Step 8.2: Layout** — header com `<PageHeader>` (já existe), `<select>` de criança, container do mapa 60vh com `<MapContainer>` + `<TileLayer>` + `<Marker>` + `<Popup>`. Atribuição OSM obrigatória.

- [ ] **Step 8.3: Derivar `online`** — `(Date.now() - Date.parse(lastFix.recordedAt)) < 5*60*1000`.

- [ ] **Step 8.4: Estado vazio** — placeholder "Sem localização registrada".

- [ ] **Step 8.5: Testes (~5)** seguindo padrão de `Children.test.tsx` (`QueryClientProvider` no helper, `vi.mock` do api). Casos: vazio, com posição, sem bateria (mostra "—"), online/offline boundary 5min, troca de child via dropdown.

- [ ] **Step 8.6: Commit**

```
feat(parent): página Localização
```

---

## Task 9: SPA parent — página Zonas Seguras + dialog

**Files:**
- Create: `public/app-parent/src/pages/ZonasSeguras.tsx`
- Create: `public/app-parent/src/pages/ZonasSeguras.test.tsx`
- Create: `public/app-parent/src/components/SafeZoneDialog.tsx`

- [ ] **Step 9.1: Página** — lista cards de zonas via `useQuery(['safe-zones'])`. Botão "+ Nova" abre dialog em modo create. Cards têm "Editar" (abre dialog em modo edit) e "Excluir" (confirm modal).

- [ ] **Step 9.2: `SafeZoneDialog`** — segue padrão de `AddChildDialog.tsx`. Props `{ open, mode: 'create'|'edit', initial?: SafeZone, onClose }`.
  - Input nome (required), input endereço (opt).
  - Picker Leaflet: `<MapContainer>` com `useMapEvents({ click: e => setLatLng(e.latlng) })`. Marker no `latLng` atual.
  - Slider raio 50..500 step 10. Default 100.
  - `useMutation(createSafeZone|updateSafeZone)` + `invalidateQueries(['safe-zones'])`.

- [ ] **Step 9.3: Testes (~6)** — lista vazia, lista com zonas, abre dialog create, abre dialog edit pré-populado, exclui com confirm, validação nome vazio.

- [ ] **Step 9.4: Commit**

```
feat(parent): página Zonas Seguras + dialog com picker
```

---

## Task 10: SPA parent — Settings toggle + nav wiring

**Files:**
- Modify: `public/app-parent/src/data/mockData.ts`
- Modify: `public/app-parent/src/App.tsx`
- Modify: `public/app-parent/src/components/SideNav.tsx`
- Modify: `public/app-parent/src/components/BottomNav.tsx`
- Modify: `public/app-parent/src/pages/Settings.tsx`
- Modify: `public/app-parent/src/pages/Settings.test.tsx`

- [ ] **Step 10.1: `PageId`** — adicionar `'location' | 'safe-zones'`.

- [ ] **Step 10.2: `App.tsx`** — 2 cases novos no switch (`Localizacao`, `ZonasSeguras`).

- [ ] **Step 10.3: `SideNav` + `BottomNav`** — adicionar itens após "Children". Icons MD: `location_on`, `shield`.

- [ ] **Step 10.4: `Settings.tsx`** — seção "Privacidade" com toggle "Permitir compartilhamento de localização" ligado ao setting `location_enabled` via `useMutation(updateSettings)`.

- [ ] **Step 10.5: Testes** — atualizar `Settings.test.tsx` pra cobrir toggle (1 case novo); `BottomNav.test.tsx`/`SideNav.test.tsx` pra refletir items novos.

- [ ] **Step 10.6: Commit**

```
feat(parent): nav + settings toggle pra location_enabled
```

---

## Task 11: PWA child — locationTracker

**Files:**
- Create: `public/app-child/src/lib/locationTracker.ts`
- Create: `public/app-child/src/lib/locationTracker.test.ts`
- Create: `public/app-child/src/api/location.ts`

- [ ] **Step 11.1: `api/location.ts`** — `postLocation(token, fix)` segue padrão de `usageTracker.ts` existente.

- [ ] **Step 11.2: `locationTracker.ts`** — função `startLocationTracker(opts)` retornando cleanup. Internals:
  - Estado: `lastSentAt: number`, `lastFix: {lat,lng} | null`.
  - `navigator.geolocation.watchPosition` com `enableHighAccuracy: true`.
  - Em sucesso: se `now - lastSentAt >= 60_000 OR haversine(lastFix, current) >= 50`, chama `postLocation` e atualiza state.
  - `document.addEventListener('visibilitychange')` — `clearWatch` quando hidden, recria quando visible.
  - `navigator.getBattery?.()` cached.

- [ ] **Step 11.3: Testes (~5)** — usar `vi.useFakeTimers` + mockar `navigator.geolocation` e `document.hidden`. Casos: throttle por tempo (não envia antes de 60s), throttle por distância (envia se >50m), pausa em hidden, retoma em visible, cleanup chama clearWatch.

- [ ] **Step 11.4: Commit**

```
feat(child): locationTracker foreground-only com throttle
```

---

## Task 12: PWA child — tela Localização + wire-up no App

**Files:**
- Create: `public/app-child/src/pages/Localizacao.tsx`
- Create: `public/app-child/src/pages/Localizacao.test.tsx`
- Modify: `public/app-child/src/App.tsx`

- [ ] **Step 12.1: `pages/Localizacao.tsx`** — 2 estados: pré-permissão (botão "Permitir localização" → `navigator.geolocation.getCurrentPosition` triggering prompt) e pós-permissão (ativo, mostra última info).

- [ ] **Step 12.2: `App.tsx`** — registra `startLocationTracker` em `useEffect` quando token presente + `me.location_enabled` true (ou tenta sempre e deixa o 403 limpar). Cleanup no unmount.

- [ ] **Step 12.3: Testes (~2)** — render inicial, render após permissão.

- [ ] **Step 12.4: Commit**

```
feat(child): tela Localização + wire-up no App
```

---

## Task 13: Full suite + manual smoke

- [ ] **Step 13.1: PHPUnit verde** — `phpunit` no root, esperado ~285 cases (264 baseline + ~21 novos).

- [ ] **Step 13.2: Vitest parent verde** — `cd public/app-parent && pnpm test`.

- [ ] **Step 13.3: Vitest child verde** — `cd public/app-child && pnpm test`.

- [ ] **Step 13.4: Build parent** — `cd public/app-parent && pnpm build`. Sem warning de bundle size > 500KB (Leaflet add ~150KB gz; total esperado < 500KB).

- [ ] **Step 13.5: Build child** — `cd public/app-child && pnpm build`.

- [ ] **Step 13.6: Smoke manual em `http://guardkids-wp.local/painel-pais/`:**
  - Configurações → ligar "Permitir compartilhamento de localização".
  - Zonas Seguras → criar "Casa" clicando no mapa, raio 100m → ver card.
  - Localização → dropdown criança → mapa renderiza placeholder "Sem localização".
  - Abrir `/painel-filho/` com token pareado → autorizar geolocation → ver tela "Localização ativa".
  - Voltar pro parent → mapa mostra marker em <60s (refetch interval).
  - Fechar aba child → aguardar 5min → status vira "offline".

- [ ] **Step 13.7: Atualizar README** — contador de testes ao final.

- [ ] **Step 13.8: Commit final**

```
docs(readme): contador de testes pós Fase A
```

---

## Sequenciamento e dependências

- **1 → 2 → 3 → 4 → 5 → 6** (backend serializado: schema, repo, settings, controllers).
- **7 → 8 → 9 → 10** (parent SPA serializado, mas 8 e 9 podem ser paralelos se quiser).
- **11 → 12** (child PWA).
- **13** depende de tudo.

Tasks 4-6 ficam bloqueadas até 1-3 verdes (precisa do schema). Tasks 8-10 dependem de 7. Task 12 depende de 11.
