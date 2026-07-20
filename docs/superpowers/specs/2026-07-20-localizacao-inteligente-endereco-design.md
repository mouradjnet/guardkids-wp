# Localização Inteligente por Endereço — Design

Data: 2026-07-20
Feature: reverse-geofencing com rótulo em linguagem natural + notificação de entrada/saída.

## Problema

Hoje as "Zonas Seguras" do guardkids-wp existem mas são **decorativas**: o responsável
clica no mapa pra desenhar um círculo, e nada compara a posição real do filho contra elas
— "quem compara é o olho, não o servidor" (`Localizacao.tsx:257-260`). Não há
geocodificação (o campo "Endereço" é só um rótulo de texto, não vira coordenada) nem
geofencing. O responsável precisa interpretar um ponto no mapa.

O objetivo: o responsável cadastra **Locais importantes** (Casa, Casa da Avó, Escola,
Escolinha de Futebol, Clínica, Aula de Música) com nome, endereço e raio; o app identifica
automaticamente onde o filho está e mostra em linguagem natural ("📍 João está na Casa da
Avó"), avisando por push quando ele chega ou sai de um local.

## Decisões (fechadas no brainstorming)

1. **Avaliação no servidor + Web Push** de entrada/saída (reusa a infra VAPID existente).
2. **Endereço → mapa:** buscar endereço move o pino; o ponto no mapa continua sendo a
   verdade; o responsável ajusta clicando/arrastando.
3. **Geocodificador:** Nominatim/OpenStreetMap, chamado **no servidor** (grátis, sem chave).
4. **Anti-spam:** confirmação antes de avisar — só declara transição depois de N fixes
   seguidos no mesmo estado (tremor isolado do GPS não dispara).
5. Renomear "Zonas Seguras" → **"Locais"** na UI (a tabela `safe_zones` continua por baixo).
6. Emoji opcional por Local. `currentPlace` vem do servidor (mesma fonte da notificação).

## Contexto técnico relevante (estado atual)

- `wp_guardkids_safe_zones` (migration 004): `id, name, address (VARCHAR 255 NULL),
  latitude/longitude (DECIMAL 10,7), radius_meters (SMALLINT default 100), created_at,
  updated_at`. Zonas são **globais** (uma "Casa" vale pra todos os filhos — modelo
  uma-família-por-site). Sem coluna de ícone.
- `wp_guardkids_locations` (migration 004): fixes **append-only** (`child_id, latitude,
  longitude, accuracy (SMALLINT NULL), battery, recorded_at, created_at`), índice
  `(child_id, recorded_at)`.
- Ingestão do fix: `ChildSelfController::createLocation` (~linha 411) — `POST`
  autenticado por token do filho, gated por `isLocationEnabled()`, rate-limited. Retorna 201.
  **Este é o ponto de gancho da avaliação server-side.**
- Push: `includes/Notifications/` tem `GuardianNotifier`, `PushSender`, `WebPush/` (VAPID).
  `ChildSelfController` **já dispara push pro responsável** em outros eventos — a plumbing
  existe e será reusada.
- Front pais: `pages/Localizacao.tsx` (mapa + último fix), `pages/ZonasSeguras.tsx` +
  `components/SafeZoneDialog.tsx` (cadastro: wizard com nome, endereço-texto, mapa clicável,
  raio 100/250/500/1000). `MapClickHandler` já move o pino no clique.
- `DB_VERSION` atual = 25. Migration nova exige bump de `GUARDKIDS_DB_VERSION` em
  `guardkids.php` no mesmo commit (senão `maybeRunMigrations` pula).

## Arquitetura

Fluxo ponta a ponta:

```
Aparelho do filho ──POST /child/location──> ChildSelfController::createLocation
                                              │ 1. insere o fix (como hoje)
                                              │ 2. PlaceTracker::evaluate(childId, lat, lng, accuracy)
                                              │       ├─ resolve o Local observado (Haversine)
                                              │       ├─ máquina de confirmação vs child_place
                                              │       └─ se confirmou transição → evento
                                              │ 3. se houve transição → GuardianNotifier (push)
                                              ▼
                        wp_guardkids_child_place (estado atual por filho)
                                              ▲
App dos pais ──GET (children/location)────────┘  currentPlace: {name, icon, since} | null
   └─ banner "📍 João está na Casa da Avó · desde 14:30"
```

### Unidades e responsabilidades

- **`GeoMath`** (puro, estático): `haversineMeters(lat1,lng1,lat2,lng2): float`. Sem deps.
- **`PlaceResolver`** (puro): dado um fix (lat/lng/accuracy) + lista de Locais, devolve o
  `zoneId` observado ou `null`. Regras: dentro do raio (Haversine ≤ radius); sobreposição →
  **menor raio vence**, desempate pelo centro mais próximo; se `accuracy > radius` do
  candidato, ignora esse candidato (fix impreciso demais). Sem I/O.
- **`PlaceTracker`**: orquestra. Lê a linha de `child_place`, aplica a máquina de
  confirmação com o `observed` do `PlaceResolver`, persiste o novo estado e **retorna** o
  evento de transição (ou `null`). Depende de `ChildPlaceRepository` + `SafeZoneRepository`.
- **`ChildPlaceRepository`**: CRUD da tabela `child_place` (get por child, upsert).
- **`Geocoder`**: `geocode(query): ?array{lat,lng,displayName}`. Chama Nominatim via
  `wp_remote_get` (User-Agent identificado, `countrycodes=br`, `format=jsonv2`, `limit=1`),
  com cache transient por query normalizada. Retorna `null` se nada encontrado / erro.
- **`GeocodeController`**: `GET /guardkids/v1/geocode?q=` (permissão `manage_options`,
  rate-limited), delega ao `Geocoder`.

### Máquina de confirmação (dentro do `PlaceTracker`)

Estado por filho em `child_place`: `current_zone_id` (confirmado, NULL=fora),
`current_since`, `pending_zone_id`, `pending_count`, `pending_since`.

A cada fix, com `observed` (zoneId ou null):

- `observed == current_zone_id` → estável: zera o pending. Nada acontece.
- `observed != current_zone_id`:
  - `observed == pending_zone_id` → `pending_count++`. Se `pending_count >= CONFIRM_FIXES`
    (constante, começa em **2**): **commit** — `current_zone_id = observed`,
    `current_since = now`, zera pending, e **emite transição**.
  - senão → novo candidato: `pending_zone_id = observed`, `pending_count = 1`,
    `pending_since = now`. Sem transição ainda.

Evento de transição emitido no commit:
- `current` novo != null → `entered` (chegou em `<Local>`).
- `current` novo == null → `left` (saiu de `<Local anterior>`).
- (mudança direta A→B conta como `entered` em B; a mensagem foca no destino.)

`CONFIRM_FIXES` é uma constante nomeada, ajustável num só lugar.

### Notificação

No commit de transição, `PlaceTracker` (ou o controller) chama `GuardianNotifier` pros
responsáveis com push ativo:
- `entered` → título "GuardKids", corpo **"📍 <Filho> chegou na <Local>"** (com o emoji do
  Local se houver), `url: /painel-pais`.
- `left` → corpo **"<Filho> saiu da <Local>"**.

Respeita o toggle `notifications.push` e as subscriptions existentes (mesmo caminho dos
outros pushes do `ChildSelfController`). Sem subscription ativa = sem push (silencioso).

### Resiliência

`PlaceTracker::evaluate` roda dentro de try/catch no controller: **qualquer exceção na
avaliação/notificação NÃO pode derrubar o insert do fix nem retornar erro ao aparelho** — o
fix já foi salvo e o 201 sai normalmente (fail-open, mesma filosofia de fallback do projeto).

## Modelo de dados

**Migration 026** (`database/migrations/026_child_place_and_zone_icon.php`), bump
`GUARDKIDS_DB_VERSION` 25 → 26 no mesmo commit.

```sql
ALTER TABLE wp_guardkids_safe_zones ADD COLUMN icon VARCHAR(32) NULL AFTER name;

CREATE TABLE wp_guardkids_child_place (
    child_id        BIGINT UNSIGNED NOT NULL,
    current_zone_id BIGINT UNSIGNED NULL,
    current_since   DATETIME        NULL,
    pending_zone_id BIGINT UNSIGNED NULL,
    pending_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    pending_since   DATETIME        NULL,
    updated_at      DATETIME        NOT NULL,
    PRIMARY KEY (child_id)
);
```

- `child_place` tem no máximo 1 linha por filho (upsert). Sem FK formal (padrão do plugin
  é `$wpdb` direto sem constraints); a limpeza ao excluir filho entra no `ChildController::destroy`
  (hoje já há dívida de tokens órfãos ali — tratar `child_place` junto).
- Raio: o schema (`SMALLINT`) já cobre 50/100/200; muda só as opções da UI.

## API

- **`GET /guardkids/v1/geocode?q=<endereço>`** — `manage_options`, rate-limited.
  Resposta: `{ "lat": -8.05, "lng": -34.88, "displayName": "..." }` ou 404 `not_found`.
- **`currentPlace` no payload existente:** a resposta de localização/children passa a
  incluir, por filho: `currentPlace: { zoneId, name, icon, since } | null`. Fonte:
  `child_place` + `safe_zones`. (Definir na implementação se vai no `ChildController::toJson`
  ou num campo do `LocationController` — decisão de acoplamento, não de design; o requisito é
  que a tela leia do servidor, não recompute no cliente.)
- **Cadastro de Local:** os endpoints de `SafeZoneController` (create/update) passam a
  aceitar `icon` (opcional, string curta). `address/latitude/longitude/radius_meters` como hoje.

## Front-end (app dos pais)

- **Menu/UI:** "Zonas Seguras" → **"Locais"** (texto/label; o `PageId` interno pode
  continuar `safe-zones` pra não quebrar roteamento — renome é de cópia).
- **`SafeZoneDialog`:**
  - seletor de **emoji** opcional (lista preset: 🏠 👵 🏫 ⚽ 🏥 🎹 + "nenhum").
  - campo **Endereço** ganha botão **"Buscar"** → chama `GET /geocode` → `setLat/setLng` +
    recentraliza o mapa; pai ajusta com o `MapClickHandler` existente.
  - opções de **raio: 50 / 100 / 200 m** (substitui 100/250/500/1000).
- **`Localizacao.tsx`:** banner acima do mapa com o `currentPlace`:
  - dentro: **"📍 <Filho> está na <Local> · desde <hora>"** (emoji do Local).
  - fora: **"<Filho> está fora dos locais cadastrados"**.
  - Reusa o marcador de avatar já existente (v1.36.12).

## Estratégia de testes

- **Unit (PHP):**
  - `GeoMath::haversineMeters` — distâncias conhecidas.
  - `PlaceResolver` — dentro/fora; sobreposição (menor raio vence + desempate por centro);
    `accuracy > radius` ignora o candidato.
  - `PlaceTracker` (máquina de confirmação, com repos stubados) — blip isolado NÃO commita;
    N fixes seguidos commitam e emitem evento; A→B; A→fora; estável não emite.
  - `Geocoder` — parse de resposta jsonv2 do Nominatim; resposta vazia → null; erro HTTP → null.
- **Integração (PHP):** `POST /child/location` aciona o `PlaceTracker`; a transição persiste
  em `child_place`; o push é enfileirado (mock/spy do `PushSender`); fix não muda estado
  quando accuracy ruim.
- **Front (vitest):** botão "Buscar" chama geocode e move o pino (MSW); banner renderiza os
  três casos (dentro/fora/sem locais); opções de raio 50/100/200; seletor de emoji.

## Fora de escopo (YAGNI)

- Histórico de lugares / linha do tempo ("esteve na Escola das 8h às 12h").
- Zonas por-filho (o modelo segue global/por-família).
- Geofencing preditivo, ETA, rotas.
- Google Geocoding (só se o Nominatim se mostrar insuficiente na prática).
- Notificação por e-mail de entrada/saída (só Web Push nesta feature).

## Riscos e mitigações

- **Cadência dos fixes** desconhecida → `CONFIRM_FIXES=2` pode confirmar rápido ou devagar
  demais dependendo do heartbeat. Mitigação: constante nomeada, ajustável; revisar após smoke.
- **Política do Nominatim** (1 req/s, User-Agent) → cache transient + rate-limit + uso baixo
  (cadastro esporádico) mantêm dentro do limite.
- **Precisão do GPS** vs raios pequenos (50m) → regra "accuracy > radius ignora" evita falso
  positivo; pai pode aumentar o raio.
- **Edge cache da Hostinger** (visto no license server) → o `/geocode` deve sair com
  `no-store`; é `manage_options` e não deve ser cacheado pelo edge, mas garantir headers.
