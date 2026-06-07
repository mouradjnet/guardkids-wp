# Location + Safe Zones — Design

**Status:** Aprovado para implementação
**Data:** 2026-06-07
**Escopo:** Fase A do roadmap de localização familiar — persistência e consumo de **posição da criança** + CRUD de **zonas seguras**. PWA do filho compartilha geolocation **apenas em foreground**. Painel dos pais mostra mapa com última posição e gerencia zonas. **Sem geofence events, sem push, sem histórico.**

---

## 1. Motivação e contexto

O brief de funcionalidades premium pede 9 features (localização, zonas, geofencing, find-my-child, check-in, SOS, histórico, central de pedidos, push). Tentar entregar tudo de uma vez gera frankenstein. Esta Fase A entrega a **base mínima testável** sobre a qual as fases B–D vão construir: sem `wp_guardkids_locations` + `wp_guardkids_safe_zones` no banco, nada do roadmap funciona.

### Constraints técnicos que enquadram o escopo

- **PWA não tem background geolocation confiável.** iOS Safari não suporta geofencing API nem Geolocation em background. Android Chrome tem Background Sync limitada. Implicação: tracking **só funciona enquanto o app-child está visível** (Page Visibility API). UI precisa comunicar isso explicitamente.
- **Relógio do device é não-confiável.** Por isso `recorded_at` é definido no servidor no momento do POST (não vem do cliente).
- **Sem Google Maps / Mapbox.** Decisão registrada: Leaflet + OpenStreetMap (zero chave, zero billing, coerente com "self-contained" do README).
- **Opt-in obrigatório.** Localização de menores exige consentimento explícito dos pais. Setting global `location_enabled` (default off) bloqueia POST do child quando desligado — fail-closed.

### Não-objetivos desta fase

- Geofence events / alertas de entrada e saída → Fase B.
- Histórico de visitas → Fase B.
- SOS, find-my-child → Fase C.
- Web Push (VAPID) + Check-ins + expansão `requests.message` → Fase D.
- Criptografia at-rest da localização → decisão separada (custo de query/report alto, merece prós/contras dedicados).
- Rate limiting REST → decisão separada (sugestão: transients do WP).

---

## 2. Arquitetura

```
PWA child (foreground only)
  └─ locationTracker  ──► POST /child/location  { lat, lng, accuracy, battery }
                                    │  X-GuardKids-Token (existente)
                                    ▼
                          ChildSelfController::reportLocation
                                    │
                                    ▼
                          LocationRepository::insert
                                    │
                                    ▼
                       wp_guardkids_locations (append-only)

Parent SPA (cookie WP + nonce)
  ├─ Página Localização   ──► GET  /locations?child_id=X&limit=N
  └─ Página Zonas Seguras ──► CRUD /safe-zones
                                    │
                                    ▼
                  LocationController · SafeZoneController
                                    │
                                    ▼
              wp_guardkids_locations · wp_guardkids_safe_zones
```

**Princípios:**

- **Rotas novas mínimas**: 1 endpoint child (`POST /child/location`), 1 endpoint parent de leitura (`GET /locations`), 4 endpoints parent de CRUD (`/safe-zones`).
- **2 tabelas novas**: `wp_guardkids_locations` (append-only, índice composto) e `wp_guardkids_safe_zones`.
- **Setting `location_enabled`** lido em todo `POST /child/location` — sem setting=true, devolve 403 com `code=location_disabled`.
- **`recorded_at` = `current_time('mysql', true)`** (UTC), formatação final no payload usa `wp_timezone()`.
- **Sem service layer entre controller e repo** nesta fase (YAGNI; segue padrão dos existentes).

---

## 3. Schema — migration 004

Arquivo novo: `database/migrations/004_locations_and_safe_zones.php`.

```sql
CREATE TABLE wp_guardkids_locations (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  child_id    BIGINT UNSIGNED NOT NULL,
  latitude    DECIMAL(10,7)   NOT NULL,
  longitude   DECIMAL(10,7)   NOT NULL,
  accuracy    SMALLINT UNSIGNED NULL,        -- metros, do GeolocationCoordinates
  battery     TINYINT UNSIGNED NULL,         -- 0-100, NULL se Battery API indisponível
  recorded_at DATETIME        NOT NULL,      -- UTC, server-set
  created_at  DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY child_recorded (child_id, recorded_at)
) {$charsetCollate};

CREATE TABLE wp_guardkids_safe_zones (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(120)    NOT NULL,
  address        VARCHAR(255)    NULL,
  latitude       DECIMAL(10,7)   NOT NULL,
  longitude      DECIMAL(10,7)   NOT NULL,
  radius_meters  SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  created_at     DATETIME        NOT NULL,
  updated_at     DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY name (name)
) {$charsetCollate};
```

### Convenções

- **`DECIMAL(10,7)`**: precisão ~1cm, suficiente pra qualquer raio. Evita float drift em comparação.
- **`accuracy`/`battery` NULL-able**: nem todo device entrega — Battery API foi descontinuada em Safari e parte do Firefox, mas mantemos coluna porque Chrome Android (alvo principal) suporta.
- **`recorded_at`** server-set: impede que child manipule timestamp. UTC pra evitar bugs de DST.
- **Sem `child_id` em `safe_zones`**: zonas são globais (uma "Casa" vale pra todos os filhos). Per-child fica pra fase futura se houver demanda.
- **`radius_meters SMALLINT`** (0..65535): cobre até 65km, sobra. Default 100m.

### Bump de versão

`guardkids.php` — `GUARDKIDS_DB_VERSION: 3 → 4`. Sem isso `maybeRunMigrations()` skipa (regra `[[feedback-guardkids-wp-migration-bump]]`).

### Migration runner

Segue padrão das 001/002/003 (closure recebe `$wpdb, $charsetCollate`, executa `dbDelta` em `CREATE TABLE` — idempotente).

---

## 4. Repositories

### 4.1 `LocationRepository`

`database/LocationRepository.php` — extends `Repository`.

Métodos novos além do CRUD base:

```php
public function findLastByChildId(int $childId): ?array;
public function findByChildId(int $childId, int $limit = 50): array;
```

Ambos usam `$wpdb->prepare()`. Sem service — o controller chama direto.

### 4.2 `SafeZoneRepository`

`database/SafeZoneRepository.php` — extends `Repository`.

Sem método especial; o CRUD do base é suficiente. `findAll('name', 'ASC')` é o suficiente pra listagem.

### 4.3 Testes

- `tests/Unit/Database/LocationRepositoryTest.php` (~4 cases): insert + findLast retorna registro novo, findByChildId respeita limit, findLast de child sem location retorna null, segunda inserção sobrepõe ordenação.
- `tests/Unit/Database/SafeZoneRepositoryTest.php` (~3 cases): CRUD básico, findAll ordena por name.

---

## 5. REST API

### 5.1 `POST /child/location` — child token

Em `api/Controllers/ChildSelfController.php`, novo método `reportLocation()`. Registrado em `RestApi::registerChildSelfRoutes()`.

```php
register_rest_route(self::NAMESPACE, '/child/location', [
    'methods'             => \WP_REST_Server::CREATABLE,
    'callback'            => [$controller, 'reportLocation'],
    'permission_callback' => $requireToken,
    'args'                => [
        'latitude'  => ['type' => 'number', 'required' => true, 'minimum' => -90,  'maximum' => 90],
        'longitude' => ['type' => 'number', 'required' => true, 'minimum' => -180, 'maximum' => 180],
        'accuracy'  => ['type' => 'integer', 'minimum' => 0, 'maximum' => 65535],
        'battery'   => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
    ],
]);
```

**Comportamento:**

1. Carrega setting `location_enabled` via `SettingsRepository`. Se != `true` → `WP_Error('location_disabled', 'Localização desativada pelos pais.', ['status' => 403])`.
2. Insere linha com `recorded_at = current_time('mysql', true)`.
3. Resposta: `201 { id: 42, recordedAt: '2026-06-07T15:32:00Z' }`. Apenas confirmação — sem retornar lat/lng (cliente já tem).

### 5.2 `GET /locations` — parent nonce

Em `api/Controllers/LocationController.php`, novo controller. Registrado em novo método `RestApi::registerLocationsRoutes()`.

```php
register_rest_route(self::NAMESPACE, '/locations', [
    'methods'             => \WP_REST_Server::READABLE,
    'callback'            => [$controller, 'index'],
    'permission_callback' => [self::class, 'requireManage'],
    'args'                => [
        'child_id' => ['type' => 'integer', 'required' => true],
        'limit'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 1],
    ],
]);
```

`limit=1` (default) cobre o caso "mostrar última posição"; `limit=100` cobre Fase B (histórico) sem nova rota.

Resposta:

```json
[
  {
    "id": 42,
    "childId": 7,
    "latitude": -8.0476,
    "longitude": -34.8770,
    "accuracy": 12,
    "battery": 58,
    "recordedAt": "2026-06-07T15:32:00Z"
  }
]
```

### 5.3 `/safe-zones` — CRUD parent nonce

Em `api/Controllers/SafeZoneController.php`. Registrado em `RestApi::registerSafeZonesRoutes()`.

| Método | Rota | Auth |
|---|---|---|
| GET | `/safe-zones` | nonce |
| POST | `/safe-zones` | nonce |
| PUT | `/safe-zones/:id` | nonce |
| DELETE | `/safe-zones/:id` | nonce |

`createArgs/updateArgs`:

```php
[
    'name'          => ['type' => 'string',  'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
    'address'       => ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
    'latitude'      => ['type' => 'number',  'required' => true, 'minimum' => -90,  'maximum' => 90],
    'longitude'     => ['type' => 'number',  'required' => true, 'minimum' => -180, 'maximum' => 180],
    'radius_meters' => ['type' => 'integer', 'required' => true, 'minimum' => 10, 'maximum' => 5000],
]
```

Min 10m evita "zona pontual" inútil; max 5km cobre "vou pra escola na rua de cima" sem virar zona da cidade.

Resposta:

```json
{
  "id": 3,
  "name": "Casa",
  "address": "Rua X, 123",
  "latitude": -8.0476,
  "longitude": -34.8770,
  "radiusMeters": 100,
  "createdAt": "2026-06-07T15:30:00Z",
  "updatedAt": "2026-06-07T15:30:00Z"
}
```

### 5.4 Testes REST

- `tests/Unit/Api/ChildSelfLocationTest.php` (~5): POST com setting on insere e retorna 201; POST com setting off retorna 403; lat/lng fora de range retorna 400; accuracy/battery opcionais.
- `tests/Unit/Api/LocationControllerTest.php` (~3): GET filtra por child_id, respeita limit, retorna array vazio se sem registros.
- `tests/Unit/Api/SafeZoneControllerTest.php` (~6): CRUD completo, validação de range, sanitize de name.

---

## 6. Setting global `location_enabled`

Reaproveita `wp_guardkids_settings` (já existe desde migration 001). Sem coluna nova.

- Key: `location_enabled` (string).
- Valores aceitos: `'1'` (on) ou `''`/ausente (off).
- Exposto em `GET /settings` e editável em `PUT /settings` (rotas já existentes).
- UI: toggle em `Settings.tsx` na seção "Privacidade". Copy: "Permitir que filhos compartilhem localização" + ajuda "Quando desligado, o app-child para de enviar localização imediatamente."

Helper em `SettingsRepository`:

```php
public function isLocationEnabled(): bool
{
    return ($this->getValue('location_enabled') ?? '') === '1';
}
```

---

## 7. UI app-parent

### 7.1 Deps novas

`public/app-parent/package.json`:

```json
"leaflet": "^1.9.4",
"react-leaflet": "^4.2.1",
"@types/leaflet": "^1.9.12"
```

CSS do Leaflet importado uma vez em `main.tsx` (`import 'leaflet/dist/leaflet.css'`).

### 7.2 Tipos

`api/types.ts`:

```ts
export type LocationFix = {
  id: number;
  childId: number;
  latitude: number;
  longitude: number;
  accuracy: number | null;
  battery: number | null;
  recordedAt: string;  // ISO-8601 UTC
};

export type SafeZone = {
  id: number;
  name: string;
  address: string | null;
  latitude: number;
  longitude: number;
  radiusMeters: number;
  createdAt: string;
  updatedAt: string;
};
```

### 7.3 Clientes REST

- `api/locations.ts` — `listLocations(childId, limit?)`.
- `api/safeZones.ts` — `listSafeZones`, `createSafeZone`, `updateSafeZone`, `deleteSafeZone`.

Ambos seguem padrão de `api/children.ts` (TanStack Query + `apiFetch`).

### 7.4 Página `Localizacao.tsx`

Layout (mobile-first):

```
┌──────────────────────────────┐
│ TopNav fixo                  │
├──────────────────────────────┤
│ Localização                  │
│ Dropdown: [ Maria  ▾]        │
├──────────────────────────────┤
│                              │
│   Mapa Leaflet 60vh          │
│   Marker última posição      │
│   (popup: hora + bateria)    │
│                              │
├──────────────────────────────┤
│ Status: online (atualizado   │
│  há 2 min)                   │
│ Bateria: 58%                 │
│ Precisão: ±12m               │
└──────────────────────────────┘
```

- Dropdown popula com `listChildren`.
- Mapa carrega tiles OSM (`https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png`, atribuição obrigatória).
- "Online" = `Date.now() - Date.parse(recordedAt) < 5 * 60_000`.
- Caso `listLocations` retorne `[]`: substitui mapa por placeholder "Sem localização registrada".
- Refetch automático a cada 60s + `refetchOnWindowFocus` (mesmo padrão do schedule polling).

### 7.5 Página `ZonasSeguras.tsx`

Layout:

```
┌──────────────────────────────┐
│ Zonas Seguras       [+ Nova] │
├──────────────────────────────┤
│ ┌──────────────────────────┐ │
│ │ 🏠 Casa                  │ │
│ │ Rua X, 123 • Raio 100m   │ │
│ │ [Editar] [Excluir]       │ │
│ └──────────────────────────┘ │
│ ┌──────────────────────────┐ │
│ │ 🏫 Escola                │ │
│ │ Av. Y, 456 • Raio 200m   │ │
│ │ [Editar] [Excluir]       │ │
│ └──────────────────────────┘ │
└──────────────────────────────┘
```

Dialog `SafeZoneDialog`:

```
┌──────────────────────────────┐
│ Nova zona segura       [×]   │
├──────────────────────────────┤
│ Nome:    [_______________]   │
│ Endereço (opcional):         │
│         [_______________]    │
│                              │
│ Localização (clique no mapa):│
│ ┌──────────────────────────┐ │
│ │   Leaflet picker         │ │
│ │   click ⇒ marker move    │ │
│ └──────────────────────────┘ │
│ Lat: -8.0476  Lng: -34.8770  │
│                              │
│ Raio: [——●———————] 100m      │
│       50m            500m    │
│                              │
│           [Cancelar] [Salvar]│
└──────────────────────────────┘
```

- Click no mapa atualiza lat/lng + reposiciona marker.
- Slider raio: range input 50..500 step 10.
- Excluir: confirm modal.

### 7.6 Wiring de navegação

- `data/mockData.ts` — `PageId` ganha `'location' | 'safe-zones'`.
- `App.tsx` — adiciona 2 cases no `switch`.
- `SideNav.tsx` + `BottomNav.tsx` — adiciona itens "Localização" (ícone `location_on`) e "Zonas Seguras" (ícone `shield`). Ordem sugerida: depois de "Children".

### 7.7 Testes Vitest

- `api/locations.test.ts` (~3): serializa query, parse response, trata 4xx.
- `api/safeZones.test.ts` (~5): CRUD shape, error mapping.
- `pages/Localizacao.test.tsx` (~5): vazio, com posição, sem bateria, online/offline boundary 5min, troca de child via dropdown.
- `pages/ZonasSeguras.test.tsx` (~6): lista vazia, lista com zonas, abre dialog "Nova", abre dialog "Editar", excluir com confirm, validação de campos.

---

## 8. UI app-child

### 8.1 Tela `Localizacao.tsx`

Mobile-first, copy honesto:

```
┌──────────────────────────────┐
│      📍                      │
│  Compartilhar localização    │
│                              │
│  Sua localização aparece pro │
│  responsável apenas enquanto │
│  este app está aberto.       │
│                              │
│  [ Permitir localização ]    │
└──────────────────────────────┘
```

Após permissão, troca pra:

```
┌──────────────────────────────┐
│      ✅                      │
│  Localização ativa            │
│  Última atualização: agora   │
│  Bateria: 58%                │
│                              │
│  Mantenha este app aberto    │
│  pra continuar compartilhando│
└──────────────────────────────┘
```

### 8.2 `lib/locationTracker.ts`

Hook puro (sem React), invocado por `<App />` quando token presente:

```ts
export function startLocationTracker(opts: {
  postLocation: (fix: { lat: number; lng: number; accuracy?: number; battery?: number }) => Promise<void>;
  minIntervalMs?: number;    // default 60_000
  minDistanceM?: number;     // default 50
}): () => void;
```

Comportamento:

1. `navigator.geolocation.watchPosition(success, error, { enableHighAccuracy: true, maximumAge: 30_000 })`.
2. Throttle: só envia se `Date.now() - lastSentAt >= minIntervalMs` OU `haversine(last, current) >= minDistanceM`.
3. `document.visibilitychange` → pausa watch quando hidden, retoma quando visível.
4. Battery API opcional: `navigator.getBattery?.()` lido uma vez na inicialização e periodicamente.
5. Retorna função de cleanup pra `useEffect`.

Lógica de visibility e throttle ficam testáveis isolando `Date.now` e expondo um clock injetável simples (`{ now: () => Date.now() }`).

### 8.3 Testes Vitest

`app-child/src/lib/locationTracker.test.ts` (~5): throttle por tempo, throttle por distância, pausa em hidden, retoma em visible, cleanup unsubscribe `watchPosition`.

Página `Localizacao.test.tsx` (~2): render inicial, render após permissão.

---

## 9. Plano de testes consolidado

| Camada | Arquivo | Cases novos | Foco |
|---|---|---|---|
| Migration | `tests/Unit/Database/MigrationRunnerTest.php` | +1 | 004 cria as 2 tabelas; idempotente |
| Repo | `tests/Unit/Database/LocationRepositoryTest.php` (novo) | ~4 | insert + findLast/findByChildId |
| Repo | `tests/Unit/Database/SafeZoneRepositoryTest.php` (novo) | ~3 | CRUD + findAll ordenado |
| Controller child | `tests/Unit/Api/ChildSelfLocationTest.php` (novo) | ~5 | setting on/off, range, opt fields |
| Controller parent | `tests/Unit/Api/LocationControllerTest.php` (novo) | ~3 | filter child_id, limit, vazio |
| Controller parent | `tests/Unit/Api/SafeZoneControllerTest.php` (novo) | ~6 | CRUD + validação + sanitize |
| Settings | `tests/Unit/Database/SettingsRepositoryTest.php` (existente) | +1 | `isLocationEnabled()` |
| Lib parent | `public/app-parent/src/api/locations.test.ts` (novo) | ~3 | client REST |
| Lib parent | `public/app-parent/src/api/safeZones.test.ts` (novo) | ~5 | client REST CRUD |
| Page parent | `public/app-parent/src/pages/Localizacao.test.tsx` (novo) | ~5 | estados de mapa + online/offline |
| Page parent | `public/app-parent/src/pages/ZonasSeguras.test.tsx` (novo) | ~6 | lista, dialog, picker, validação |
| Lib child | `public/app-child/src/lib/locationTracker.test.ts` (novo) | ~5 | throttle + visibility |
| Page child | `public/app-child/src/pages/Localizacao.test.tsx` (novo) | ~2 | render inicial + após permissão |

**Total novo:** ~49 testes. Base esperada após Fase A: **~313 testes** (vs 264 atuais — assumindo Fase 8 contou 45 e já está merged).

---

## 10. Critérios de sucesso

Fase A está "pronta" quando:

1. **Migration 004 aplicada**: as 2 tabelas existem; `GUARDKIDS_DB_VERSION=4`; rerun da migration é no-op.
2. **`POST /child/location`**: aceita lat/lng com setting on → 201 + linha em `locations`; setting off → 403 com `code=location_disabled`.
3. **`GET /locations?child_id=X`**: devolve array ordenado decrescente por `recorded_at`, respeita `limit`.
4. **`/safe-zones` CRUD**: cria/lista/edita/exclui com validação de range; `radius_meters` clampado em 10..5000.
5. **Setting global**: `Settings.tsx` tem toggle "Permitir compartilhamento de localização" → reflete em `wp_guardkids_settings.location_enabled`.
6. **Página Localização (parent)**: dropdown de criança, mapa OSM com marker + popup, status online/offline derivado de `recordedAt`.
7. **Página Zonas Seguras (parent)**: lista de zonas, dialog CRUD com picker Leaflet, slider de raio.
8. **PWA child**: tela explícita de consentimento → permissão Geolocation → tracker envia POSTs enquanto visível; pausa quando hidden.
9. **Suíte verde**: `phpunit` + `pnpm test` em ambos os apps + `pnpm build` ambos sem warning.
10. **Smoke manual em `guardkids-wp.local`**:
    - Liga `location_enabled` em Configurações.
    - Cria zona "Casa" no parent → marker + círculo de 100m aparecem.
    - Autoriza geo no PWA child → última posição aparece no parent em <60s.
    - Fecha aba do PWA → status volta pra "offline" após 5 min.

---

## 11. Fora de escopo (explícito)

Itens deliberadamente excluídos desta fase:

- **Geofence events** (`enter`/`exit` de zonas) → Fase B; depende deste schema.
- **Histórico de visitas com filtros hoje/semana/mês** → Fase B.
- **SOS, "Encontrar meu filho"** → Fase C.
- **VAPID push, Check-ins, expansão `requests.message`/`approved_until`** → Fase D.
- **Per-child safe zones** (relação N:M com `children`): YAGNI até alguém pedir.
- **Endereço → lat/lng automático** (geocoding): exige API externa; fora do "self-contained".
- **Background tracking real** (fora de PWA): apontado pra Fase 5+ via wrapper nativo, se houver demanda.
- **Criptografia at-rest** de `latitude`/`longitude`: decisão separada com trade-off explícito.
- **Rate limiting REST**: decisão separada (sugestão futura: transients WP).
- **Mapa offline / cache de tiles**: vector tiles ou offline-first ficam de fora; tiles online OSM são suficientes pra MVP.
