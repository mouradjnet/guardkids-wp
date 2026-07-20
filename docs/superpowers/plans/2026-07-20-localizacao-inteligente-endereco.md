# Localização Inteligente por Endereço — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O responsável cadastra Locais (Casa, Escola…) com endereço geocodificado e raio; o servidor identifica automaticamente onde o filho está, mostra em linguagem natural no app e avisa por Web Push quando ele chega ou sai de um local.

**Architecture:** Avaliação server-side no gancho de ingestão do fix (`ChildSelfController::createLocation`), com máquina de confirmação anti-spam persistida em `child_place`. Classes puras (`GeoMath`, `PlaceResolver`) + orquestrador (`PlaceTracker`) + notificação reusando `GuardianNotifier`. Geocodificação via Nominatim no servidor (`Geocoder` + `GeocodeController`). Front evolui `SafeZoneDialog` (emoji + buscar endereço + raio 50/100/200) e `Localizacao.tsx` (banner de local atual).

**Tech Stack:** PHP 8.2, `$wpdb` direto, PHPUnit (unit + integration); React 19 + react-leaflet + TanStack Query, Vitest + MSW.

---

## Convenções deste projeto (ler antes)

- **Migrations:** arquivo `database/migrations/NNN_*.php` que **retorna** `static function (\wpdb $wpdb, string $charsetCollate): void`. Usar **`$wpdb->query` direto**, NUNCA `dbDelta` (causou no-op silencioso em prod na 003). Descoberta é automática por `glob` + número no nome.
- **Migration nova exige bump de `GUARDKIDS_DB_VERSION`** em `guardkids.php` **no mesmo commit**, senão `MigrationRunner` pula.
- **Repository base** (`database/Repository.php`): PK `id` auto-increment, `insert()`/`update()` cuidam de `created_at`/`updated_at`. Para PK diferente (`child_place` usa `child_id`), escrever métodos próprios via `$this->db` e `$this->table()`.
- **Rodar testes:**
  - Unit PHP: `vendor/bin/phpunit --filter <NomeDoTeste>`
  - Integração PHP: `vendor/bin/phpunit -c phpunit-integration.xml.dist --filter <NomeDoTeste>`
  - Front: `cd public/app-parent && npx vitest run <arquivo>`
- **Gate pré-commit (só no final da feature):** `vendor/bin/phpunit` (unit) + `-c phpunit-integration.xml.dist` (integração) + `cd public/app-parent && npx tsc --noEmit && npx vitest run && npm run build`.

## Interfaces e constantes (referência compartilhada)

Assinaturas que as tarefas abaixo criam/consomem — mantenha-as idênticas:

```php
// includes/Geo/GeoMath.php
GeoMath::haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float

// includes/Geo/PlaceResolver.php  — $zones: array<int, array{id:int, latitude:float, longitude:float, radius_meters:int}>
PlaceResolver::resolve(float $lat, float $lng, ?int $accuracy, array $zones): ?int  // zoneId observado ou null

// database/ChildPlaceRepository.php
ChildPlaceRepository::get(int $childId): ?array          // linha ou null
ChildPlaceRepository::upsert(int $childId, array $data): void
ChildPlaceRepository::deleteByChild(int $childId): void

// includes/Geo/PlaceTracker.php
PlaceTracker::CONFIRM_FIXES = 2
PlaceTracker::evaluate(int $childId, float $lat, float $lng, ?int $accuracy): ?array
//   retorna null (sem transição) OU:
//   ['type'=>'entered'|'left', 'zoneId'=>int, 'placeName'=>string, 'icon'=>string, 'token'=>string]

// includes/Notifications/GuardianNotifier.php  (novos métodos)
GuardianNotifier::notifyPlaceEntered(int $childId, string $placeName, string $icon, string $eventToken): void
GuardianNotifier::notifyPlaceLeft(int $childId, string $placeName, string $eventToken): void

// includes/Geo/Geocoder.php
Geocoder::geocode(string $query): ?array   // ['lat'=>float,'lng'=>float,'displayName'=>string] ou null
```

Rótulo/estado exposto ao front, por filho: `currentPlace: {zoneId:int, name:string, icon:string, since:string} | null`.

## Estrutura de arquivos

**Criar:**
- `includes/Geo/GeoMath.php` — distância Haversine (puro).
- `includes/Geo/PlaceResolver.php` — resolve zona observada (puro).
- `includes/Geo/PlaceTracker.php` — máquina de confirmação + emite transição.
- `includes/Geo/Geocoder.php` — Nominatim server-side.
- `database/ChildPlaceRepository.php` — CRUD de `child_place`.
- `database/migrations/026_child_place_and_zone_icon.php` — ALTER safe_zones + CREATE child_place.
- `api/Controllers/GeocodeController.php` — `GET /geocode`.
- `public/app-parent/src/api/geocode.ts` — cliente do `/geocode`.

**Modificar:**
- `guardkids.php` — `GUARDKIDS_DB_VERSION` 25 → 26.
- `includes/Notifications/GuardianNotifier.php` — métodos de lugar.
- `api/Controllers/ChildSelfController.php` — gancho fail-open após insert do fix.
- `api/Controllers/SafeZoneController.php` — `icon` em args/extractData/toJson.
- `api/Controllers/ChildController.php` — `currentPlace` no `toJson`; limpar `child_place` no `destroy`.
- `api/RestApi.php` — registrar rota `/geocode`.
- `public/app-parent/src/api/types.ts` — `icon` em SafeZone, `currentPlace` em Child.
- `public/app-parent/src/api/safeZones.ts` — `icon` no payload.
- `public/app-parent/src/components/SafeZoneDialog.tsx` — emoji + buscar + raio 50/100/200.
- `public/app-parent/src/pages/Localizacao.tsx` — banner de local atual.
- `public/app-parent/src/data/mockData.ts` (navItems) + `SideNav`/`BottomNav`/PageHeaders — "Zonas Seguras" → "Locais".

---

### Task 1: GeoMath (distância Haversine)

**Files:**
- Create: `includes/Geo/GeoMath.php`
- Test: `tests/Unit/Geo/GeoMathTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Geo;

use GuardKids\Geo\GeoMath;
use PHPUnit\Framework\TestCase;

final class GeoMathTest extends TestCase
{
    public function testZeroDistanceForSamePoint(): void
    {
        self::assertSame(0.0, GeoMath::haversineMeters(-8.05, -34.88, -8.05, -34.88));
    }

    public function testKnownDistanceAboutOneDegreeLatitude(): void
    {
        // 1 grau de latitude ≈ 111.19 km. Tolerância de 1 km.
        $d = GeoMath::haversineMeters(0.0, 0.0, 1.0, 0.0);
        self::assertEqualsWithDelta(111_190.0, $d, 1_000.0);
    }

    public function testShortDistanceInMeters(): void
    {
        // ~100m ao norte em Recife.
        $d = GeoMath::haversineMeters(-8.0500, -34.8800, -8.0491, -34.8800);
        self::assertEqualsWithDelta(100.0, $d, 5.0);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter GeoMathTest`
Expected: FAIL (`Class "GuardKids\Geo\GeoMath" not found`).

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace GuardKids\Geo;

/**
 * Matemática geográfica pura (sem $wpdb, sem I/O). Distância entre dois pontos
 * pela fórmula de Haversine, em metros.
 */
final class GeoMath
{
    private const EARTH_RADIUS_M = 6_371_000.0;

    public static function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_M * $c;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter GeoMathTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/Geo/GeoMath.php tests/Unit/Geo/GeoMathTest.php
git commit -m "feat(geo): GeoMath.haversineMeters (distancia em metros)"
```

---

### Task 2: PlaceResolver (zona observada)

**Files:**
- Create: `includes/Geo/PlaceResolver.php`
- Test: `tests/Unit/Geo/PlaceResolverTest.php`

Regras: um Local "casa" quando `haversine(fix, centro) <= radius_meters`. Se o fix casa em vários, vence o de **menor raio** (mais específico); empate de raio → menor distância ao centro. Se `accuracy` (metros) for **maior** que o raio de um candidato, esse candidato é ignorado (fix impreciso demais pra confiar num raio pequeno).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Geo;

use GuardKids\Geo\PlaceResolver;
use PHPUnit\Framework\TestCase;

final class PlaceResolverTest extends TestCase
{
    /** @return array<int, array{id:int, latitude:float, longitude:float, radius_meters:int}> */
    private function zones(): array
    {
        return [
            ['id' => 1, 'latitude' => -8.0500, 'longitude' => -34.8800, 'radius_meters' => 100], // Casa
            ['id' => 2, 'latitude' => -8.0500, 'longitude' => -34.8800, 'radius_meters' => 500], // Bairro (engloba a Casa)
            ['id' => 3, 'latitude' => -8.0700, 'longitude' => -34.8900, 'radius_meters' => 100], // Escola (longe)
        ];
    }

    public function testReturnsNullWhenOutsideEveryZone(): void
    {
        self::assertNull(PlaceResolver::resolve(-8.2000, -34.9500, null, $this->zones()));
    }

    public function testSmallestRadiusWinsOnOverlap(): void
    {
        // No centro exato de Casa+Bairro → vence Casa (raio 100 < 500).
        self::assertSame(1, PlaceResolver::resolve(-8.0500, -34.8800, null, $this->zones()));
    }

    public function testFallsBackToLargerZoneWhenOutsideSmaller(): void
    {
        // ~300m do centro: fora da Casa (100m), dentro do Bairro (500m).
        self::assertSame(2, PlaceResolver::resolve(-8.0473, -34.8800, null, $this->zones()));
    }

    public function testIgnoresCandidateWhenAccuracyWorseThanRadius(): void
    {
        // No centro da Casa, mas accuracy 300m > raio 100m da Casa → Casa ignorada;
        // Bairro (500m) ainda vale porque 300 <= 500.
        self::assertSame(2, PlaceResolver::resolve(-8.0500, -34.8800, 300, $this->zones()));
    }

    public function testReturnsNullWhenAccuracyWorseThanAllRadii(): void
    {
        self::assertNull(PlaceResolver::resolve(-8.0500, -34.8800, 600, $this->zones()));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter PlaceResolverTest`
Expected: FAIL (`Class "GuardKids\Geo\PlaceResolver" not found`).

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace GuardKids\Geo;

/**
 * Resolve em qual Local (safe_zone) um fix GPS caiu. Puro: recebe o fix e a
 * lista de zonas, devolve o id da zona observada ou null (fora de tudo).
 *
 * Sobreposição: vence o menor raio (mais específico); empate → menor distância.
 * accuracy > raio: candidato ignorado (fix impreciso demais pra um raio pequeno).
 */
final class PlaceResolver
{
    /**
     * @param array<int, array{id:int, latitude:float, longitude:float, radius_meters:int}> $zones
     */
    public static function resolve(float $lat, float $lng, ?int $accuracy, array $zones): ?int
    {
        $best = null; // ['id'=>int,'radius'=>int,'dist'=>float]

        foreach ($zones as $zone) {
            $radius = (int) $zone['radius_meters'];
            if ($accuracy !== null && $accuracy > $radius) {
                continue; // impreciso demais pra confiar neste raio
            }
            $dist = GeoMath::haversineMeters($lat, $lng, (float) $zone['latitude'], (float) $zone['longitude']);
            if ($dist > $radius) {
                continue; // fora deste Local
            }
            if (
                $best === null
                || $radius < $best['radius']
                || ($radius === $best['radius'] && $dist < $best['dist'])
            ) {
                $best = ['id' => (int) $zone['id'], 'radius' => $radius, 'dist' => $dist];
            }
        }

        return $best['id'] ?? null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter PlaceResolverTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/Geo/PlaceResolver.php tests/Unit/Geo/PlaceResolverTest.php
git commit -m "feat(geo): PlaceResolver resolve zona observada (menor raio vence, accuracy>raio ignora)"
```

---

### Task 3: Migration 026 + bump DB_VERSION

**Files:**
- Create: `database/migrations/026_child_place_and_zone_icon.php`
- Modify: `guardkids.php` (linha `define('GUARDKIDS_DB_VERSION', 25);`)
- Test: `tests/Unit/Database/MigrationRunnerTest.php` (já existe; adiciona uma asserção da presença do arquivo 026)

- [ ] **Step 1: Write the failing test**

Adicione este método ao final de `tests/Unit/Database/MigrationRunnerTest.php` (antes da última `}` da classe):

```php
    public function testMigration026FileExistsAndIsCallable(): void
    {
        $path = dirname(__DIR__, 3) . '/database/migrations/026_child_place_and_zone_icon.php';
        self::assertFileExists($path);
        $factory = require $path;
        self::assertIsCallable($factory);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testMigration026FileExistsAndIsCallable`
Expected: FAIL (`assertFileExists` falha — arquivo não existe).

- [ ] **Step 3: Write the migration**

Criar `database/migrations/026_child_place_and_zone_icon.php`:

```php
<?php

declare(strict_types=1);

/**
 * Migration 026 — Localização Inteligente por Endereço.
 *
 * safe_zones ganha `icon` (emoji opcional do Local). child_place guarda o
 * estado de geofencing por filho (local atual confirmado + candidato pendente).
 *
 * $wpdb->query direto (nunca dbDelta — no-op silencioso na 003). ALTER guardado
 * por SHOW COLUMNS pra ser idempotente mesmo se rodar de novo.
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $zones = $wpdb->prefix . 'guardkids_safe_zones';
    $place = $wpdb->prefix . 'guardkids_child_place';

    $hasIcon = $wpdb->get_var(
        $wpdb->prepare('SHOW COLUMNS FROM ' . $zones . ' LIKE %s', 'icon')
    );
    if ($hasIcon === null) {
        $wpdb->query("ALTER TABLE {$zones} ADD COLUMN icon VARCHAR(32) NULL AFTER name;");
    }

    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS {$place} (
            child_id        BIGINT UNSIGNED   NOT NULL,
            current_zone_id BIGINT UNSIGNED   NULL,
            current_since   DATETIME          NULL,
            pending_zone_id BIGINT UNSIGNED   NULL,
            pending_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            pending_since   DATETIME          NULL,
            updated_at      DATETIME          NOT NULL,
            PRIMARY KEY (child_id)
        ) {$charsetCollate};"
    );
};
```

- [ ] **Step 4: Bump DB_VERSION**

Em `guardkids.php`, trocar:

```php
define('GUARDKIDS_DB_VERSION', 25);
```

por:

```php
define('GUARDKIDS_DB_VERSION', 26);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter testMigration026FileExistsAndIsCallable`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/026_child_place_and_zone_icon.php guardkids.php tests/Unit/Database/MigrationRunnerTest.php
git commit -m "feat(db): migration 026 — safe_zones.icon + tabela child_place (DB v26)"
```

---

### Task 4: ChildPlaceRepository

**Files:**
- Create: `database/ChildPlaceRepository.php`
- Test: `tests/Unit/Database/ChildPlaceRepositoryTest.php`

PK é `child_id` (não `id`), então get/upsert/delete são próprios. `upsert` faz INSERT ... ON DUPLICATE KEY UPDATE via `$wpdb->query`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\ChildPlaceRepository;
use PHPUnit\Framework\TestCase;

final class ChildPlaceRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];
            /** @var array<int, string> */
            public array $queries = [];

            public function __construct()
            {
            }

            public function prepare($query, ...$args)
            {
                $flat = $args[0] ?? null;
                if (is_array($flat)) {
                    $args = $flat;
                }
                return vsprintf(str_replace(['%d', '%s'], ['%d', "'%s'"], (string) $query), $args);
            }

            public function get_row($query = null, $output = ARRAY_A, $y = 0)
            {
                if (preg_match('/child_id = (\d+)/', (string) $query, $m) === 1) {
                    return $this->rows[(int) $m[1]] ?? null;
                }
                return null;
            }

            public function query($query)
            {
                $this->queries[] = (string) $query;
                // Simula o upsert: extrai child_id e current_zone_id do VALUES.
                if (preg_match('/child_place.*VALUES \((\d+),\s*(\d+|NULL)/s', (string) $query, $m) === 1) {
                    $this->rows[(int) $m[1]] = [
                        'child_id'        => (int) $m[1],
                        'current_zone_id' => $m[2] === 'NULL' ? null : (int) $m[2],
                    ];
                }
                return 1;
            }

            public function delete($table, $where, $where_format = null)
            {
                unset($this->rows[(int) $where['child_id']]);
                return 1;
            }
        };
    }

    public function testGetReturnsNullWhenAbsent(): void
    {
        self::assertNull((new ChildPlaceRepository())->get(7));
    }

    public function testUpsertThenGet(): void
    {
        $repo = new ChildPlaceRepository();
        $repo->upsert(7, [
            'current_zone_id' => 3,
            'current_since'   => '2026-07-20 14:00:00',
            'pending_zone_id' => null,
            'pending_count'   => 0,
            'pending_since'   => null,
        ]);
        $row = $repo->get(7);
        self::assertNotNull($row);
        self::assertSame(3, (int) $row['current_zone_id']);
    }

    public function testDeleteByChild(): void
    {
        $repo = new ChildPlaceRepository();
        $repo->upsert(7, ['current_zone_id' => 3, 'current_since' => '2026-07-20 14:00:00', 'pending_zone_id' => null, 'pending_count' => 0, 'pending_since' => null]);
        $repo->deleteByChild(7);
        self::assertNull($repo->get(7));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter ChildPlaceRepositoryTest`
Expected: FAIL (`Class "GuardKids\Database\ChildPlaceRepository" not found`).

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace GuardKids\Database;

/**
 * Estado de geofencing por filho (uma linha por child_id). PK é child_id, então
 * não herda o insert/update do Repository base (que assume `id`).
 */
final class ChildPlaceRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'child_place';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $childId): ?array
    {
        $sql = $this->db->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE child_id = %d LIMIT 1',
            $childId,
        );
        $row = $this->db->get_row($sql, ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * INSERT ... ON DUPLICATE KEY UPDATE. $data: current_zone_id, current_since,
     * pending_zone_id, pending_count, pending_since (updated_at é injetado aqui).
     *
     * @param array<string, mixed> $data
     */
    public function upsert(int $childId, array $data): void
    {
        $now = current_time('mysql', true);
        $czid = $data['current_zone_id'] ?? null;
        $pzid = $data['pending_zone_id'] ?? null;

        $sql = $this->db->prepare(
            'INSERT INTO ' . $this->table() . ' '
            . '(child_id, current_zone_id, current_since, pending_zone_id, pending_count, pending_since, updated_at) '
            . 'VALUES (%d, ' . ($czid === null ? 'NULL' : '%d') . ', ' . ($data['current_since'] === null ? 'NULL' : '%s') . ', '
            . ($pzid === null ? 'NULL' : '%d') . ', %d, ' . ($data['pending_since'] === null ? 'NULL' : '%s') . ', %s) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'current_zone_id = VALUES(current_zone_id), current_since = VALUES(current_since), '
            . 'pending_zone_id = VALUES(pending_zone_id), pending_count = VALUES(pending_count), '
            . 'pending_since = VALUES(pending_since), updated_at = VALUES(updated_at)',
            ...array_values(array_filter([
                $childId,
                $czid,
                $data['current_since'],
                $pzid,
                (int) $data['pending_count'],
                $data['pending_since'],
                $now,
            ], static fn ($v) => $v !== null)),
        );
        $this->db->query($sql);
    }

    public function deleteByChild(int $childId): void
    {
        $this->db->delete($this->table(), ['child_id' => $childId]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter ChildPlaceRepositoryTest`
Expected: PASS (3 tests).

> Nota de integração: a `upsert` usa placeholders condicionais para lidar com NULLs (o `%d`/`%s` do `wpdb->prepare` não aceita NULL). Cobrir o caminho real de banco no teste de integração da Task 6.

- [ ] **Step 5: Commit**

```bash
git add database/ChildPlaceRepository.php tests/Unit/Database/ChildPlaceRepositoryTest.php
git commit -m "feat(db): ChildPlaceRepository (get/upsert/deleteByChild, PK child_id)"
```

---

### Task 5: PlaceTracker (máquina de confirmação)

**Files:**
- Create: `includes/Geo/PlaceTracker.php`
- Test: `tests/Unit/Geo/PlaceTrackerTest.php`

Máquina, a cada fix com `observed` (zoneId ou null):
- `observed == current` → estável: zera pending. Retorna null.
- `observed != current`:
  - `observed == pending` → `pending_count++`; se `>= CONFIRM_FIXES` → commit (current=observed, zera pending) e retorna transição.
  - senão → novo pending (count=1). Retorna null.
- Transição: `entered` se novo current != null; `left` se == null. `token` = timestamp do commit (torna o dedup da notificação único por transição).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Geo;

use GuardKids\Database\ChildPlaceRepository;
use GuardKids\Database\SafeZoneRepository;
use GuardKids\Geo\PlaceTracker;
use PHPUnit\Framework\TestCase;

final class PlaceTrackerTest extends TestCase
{
    private function tracker(): PlaceTracker
    {
        // Zonas: 1=Escola (centro -8.05,-34.88, r100). SafeZoneRepository::findAll é usado.
        $zones = new class () extends SafeZoneRepository {
            public function __construct()
            {
            }
            public function findAll(string $orderBy = 'id', string $direction = 'ASC'): array
            {
                return [
                    ['id' => 1, 'name' => 'Escola', 'icon' => '🏫', 'latitude' => -8.0500, 'longitude' => -34.8800, 'radius_meters' => 100],
                    ['id' => 2, 'name' => 'Casa',   'icon' => '🏠', 'latitude' => -8.0700, 'longitude' => -34.8900, 'radius_meters' => 100],
                ];
            }
        };
        // child_place em memória.
        $place = new class () extends ChildPlaceRepository {
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];
            public function __construct()
            {
            }
            public function get(int $childId): ?array
            {
                return $this->rows[$childId] ?? null;
            }
            public function upsert(int $childId, array $data): void
            {
                $this->rows[$childId] = $data + ['child_id' => $childId];
            }
        };
        return new PlaceTracker($zones, $place);
    }

    public function testSingleFixDoesNotConfirm(): void
    {
        // 1 fix dentro da Escola: pending=1, sem transição (CONFIRM_FIXES=2).
        self::assertNull($this->tracker()->evaluate(9, -8.0500, -34.8800, null));
    }

    public function testTwoConsecutiveFixesConfirmEntered(): void
    {
        $t = $this->tracker();
        self::assertNull($t->evaluate(9, -8.0500, -34.8800, null));       // pending
        $ev = $t->evaluate(9, -8.0500, -34.8800, null);                   // confirma
        self::assertIsArray($ev);
        self::assertSame('entered', $ev['type']);
        self::assertSame(1, $ev['zoneId']);
        self::assertSame('Escola', $ev['placeName']);
    }

    public function testStableStateEmitsNothing(): void
    {
        $t = $this->tracker();
        $t->evaluate(9, -8.0500, -34.8800, null);
        $t->evaluate(9, -8.0500, -34.8800, null); // confirmou entrada
        self::assertNull($t->evaluate(9, -8.0500, -34.8800, null)); // estável
    }

    public function testLeavingToOutsideConfirmsLeft(): void
    {
        $t = $this->tracker();
        $t->evaluate(9, -8.0500, -34.8800, null);
        $t->evaluate(9, -8.0500, -34.8800, null); // dentro da Escola (confirmado)
        self::assertNull($t->evaluate(9, -8.2000, -34.9500, null)); // fora: pending
        $ev = $t->evaluate(9, -8.2000, -34.9500, null);             // confirma saída
        self::assertIsArray($ev);
        self::assertSame('left', $ev['type']);
        self::assertSame('Escola', $ev['placeName']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter PlaceTrackerTest`
Expected: FAIL (`Class "GuardKids\Geo\PlaceTracker" not found`).

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace GuardKids\Geo;

use GuardKids\Database\ChildPlaceRepository;
use GuardKids\Database\SafeZoneRepository;

/**
 * Orquestra o geofencing por filho: resolve a zona observada, aplica a máquina
 * de confirmação (anti-spam) sobre child_place e emite a transição confirmada.
 *
 * Puro no sentido de que I/O é só pelos repos injetados; a lógica de estado é
 * testável com repos fakes.
 */
final class PlaceTracker
{
    /** Fixes seguidos no mesmo estado antes de confirmar uma transição. */
    public const CONFIRM_FIXES = 2;

    public function __construct(
        private readonly SafeZoneRepository $zones,
        private readonly ChildPlaceRepository $places,
    ) {
    }

    /**
     * @return array{type:string, zoneId:int, placeName:string, icon:string, token:string}|null
     */
    public function evaluate(int $childId, float $lat, float $lng, ?int $accuracy): ?array
    {
        $zoneRows = $this->zones->findAll('id', 'ASC');
        $observed = PlaceResolver::resolve($lat, $lng, $accuracy, $zoneRows);

        $state = $this->places->get($childId);
        $current = isset($state['current_zone_id']) && $state['current_zone_id'] !== null
            ? (int) $state['current_zone_id']
            : null;
        $pending = isset($state['pending_zone_id']) && $state['pending_zone_id'] !== null
            ? (int) $state['pending_zone_id']
            : null;
        $pendingCount = (int) ($state['pending_count'] ?? 0);
        $currentSince = $state['current_since'] ?? null;

        // Estável: observado == atual. Zera qualquer candidato.
        if ($observed === $current) {
            $this->places->upsert($childId, [
                'current_zone_id' => $current,
                'current_since'   => $currentSince,
                'pending_zone_id' => null,
                'pending_count'   => 0,
                'pending_since'   => null,
            ]);
            return null;
        }

        // Candidato diferente do atual.
        if ($observed === $pending) {
            $pendingCount++;
        } else {
            $pending = $observed;
            $pendingCount = 1;
        }

        if ($pendingCount < self::CONFIRM_FIXES) {
            $this->places->upsert($childId, [
                'current_zone_id' => $current,
                'current_since'   => $currentSince,
                'pending_zone_id' => $pending,
                'pending_count'   => $pendingCount,
                'pending_since'   => current_time('mysql', true),
            ]);
            return null;
        }

        // Confirma a transição.
        $now = current_time('mysql', true);
        $leftZoneId = $current;
        $this->places->upsert($childId, [
            'current_zone_id' => $observed,
            'current_since'   => $now,
            'pending_zone_id' => null,
            'pending_count'   => 0,
            'pending_since'   => null,
        ]);

        if ($observed !== null) {
            $zone = $this->zoneById($zoneRows, $observed);
            return [
                'type'      => 'entered',
                'zoneId'    => $observed,
                'placeName' => (string) ($zone['name'] ?? ''),
                'icon'      => (string) ($zone['icon'] ?? ''),
                'token'     => $now,
            ];
        }

        $zone = $this->zoneById($zoneRows, (int) $leftZoneId);
        return [
            'type'      => 'left',
            'zoneId'    => (int) $leftZoneId,
            'placeName' => (string) ($zone['name'] ?? ''),
            'icon'      => (string) ($zone['icon'] ?? ''),
            'token'     => $now,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $zoneRows
     * @return array<string, mixed>
     */
    private function zoneById(array $zoneRows, int $id): array
    {
        foreach ($zoneRows as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }
        return [];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter PlaceTrackerTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/Geo/PlaceTracker.php tests/Unit/Geo/PlaceTrackerTest.php
git commit -m "feat(geo): PlaceTracker maquina de confirmacao (CONFIRM_FIXES=2) + emite transicao"
```

---

### Task 6: GuardianNotifier — notificação de lugar

**Files:**
- Modify: `includes/Notifications/GuardianNotifier.php`
- Test: `tests/Unit/Notifications/GuardianNotifierPlaceTest.php`

Copy: para evitar concordância de gênero/artigo ("na Escola" vs "no Parque"), usar **"chegou em: <Local>"** e **"saiu de: <Local>"**. Dedup por token único (o timestamp da transição) → múltiplas entradas/saídas no mesmo dia notificam normalmente.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Notifications;

use GuardKids\Database\GuardianPushDedupRepository;
use GuardKids\Database\ChildRepository;
use GuardKids\Notifications\GuardianNotifier;
use GuardKids\Notifications\WebPush\PushSender;
use PHPUnit\Framework\TestCase;

final class GuardianNotifierPlaceTest extends TestCase
{
    /** @var array<int, array{title:string, body:string}> */
    private array $sent = [];

    private function notifier(): GuardianNotifier
    {
        $test = $this;
        $dedup = new class () extends GuardianPushDedupRepository {
            /** @var array<string, bool> */
            public array $keys = [];
            public function __construct()
            {
            }
            public function createIfAbsent(string $key): bool
            {
                if (isset($this->keys[$key])) {
                    return false;
                }
                $this->keys[$key] = true;
                return true;
            }
        };
        $children = new class () extends ChildRepository {
            public function __construct()
            {
            }
            public function findById(int $id): ?array
            {
                return ['id' => $id, 'name' => 'João'];
            }
        };
        $sender = new class ($test) extends PushSender {
            public function __construct(private object $t)
            {
            }
            public function sendToGuardians(string $title, string $body): void
            {
                ($this->t)->record($title, $body);
            }
        };
        return new GuardianNotifier($dedup, $children, $sender);
    }

    public function record(string $title, string $body): void
    {
        $this->sent[] = ['title' => $title, 'body' => $body];
    }

    public function testEnteredSendsWithIcon(): void
    {
        $this->sent = [];
        $this->notifier()->notifyPlaceEntered(9, 'Escola', '🏫', '2026-07-20 14:00:00');
        self::assertCount(1, $this->sent);
        self::assertStringContainsString('João', $this->sent[0]['title']);
        self::assertStringContainsString('Escola', $this->sent[0]['title']);
        self::assertStringContainsString('🏫', $this->sent[0]['title']);
    }

    public function testLeftSends(): void
    {
        $this->sent = [];
        $this->notifier()->notifyPlaceLeft(9, 'Escola', '2026-07-20 15:00:00');
        self::assertCount(1, $this->sent);
        self::assertStringContainsString('saiu', mb_strtolower($this->sent[0]['title']));
    }

    public function testSameTokenDedupes(): void
    {
        $this->sent = [];
        $n = $this->notifier();
        $n->notifyPlaceEntered(9, 'Escola', '🏫', '2026-07-20 14:00:00');
        $n->notifyPlaceEntered(9, 'Escola', '🏫', '2026-07-20 14:00:00'); // mesmo token → suprimido
        self::assertCount(1, $this->sent);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter GuardianNotifierPlaceTest`
Expected: FAIL (`Call to undefined method ...::notifyPlaceEntered()`).

- [ ] **Step 3: Add the methods**

Em `includes/Notifications/GuardianNotifier.php`, adicionar antes da última `}` da classe:

```php
    public function notifyPlaceEntered(int $childId, string $placeName, string $icon, string $eventToken): void
    {
        if ($childId === 0 || $placeName === '') {
            return;
        }
        $badge = $icon !== '' ? $icon . ' ' : '📍 ';
        $this->emit(
            'place:in:' . $childId . ':' . $eventToken,
            $badge . $this->childName($childId) . ' chegou em: ' . $placeName,
            'Toque para ver no mapa.',
        );
    }

    public function notifyPlaceLeft(int $childId, string $placeName, string $eventToken): void
    {
        if ($childId === 0 || $placeName === '') {
            return;
        }
        $this->emit(
            'place:out:' . $childId . ':' . $eventToken,
            $this->childName($childId) . ' saiu de: ' . $placeName,
            'Toque para ver no mapa.',
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter GuardianNotifierPlaceTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/Notifications/GuardianNotifier.php tests/Unit/Notifications/GuardianNotifierPlaceTest.php
git commit -m "feat(notify): GuardianNotifier.notifyPlaceEntered/Left (dedup por token da transicao)"
```

---

### Task 7: Gancho no ChildSelfController + notificação (fail-open)

**Files:**
- Modify: `api/Controllers/ChildSelfController.php` (método `createLocation`, ~linha 411)
- Test: `tests/Integration/Api/ChildSelfControllerLocationPlaceTest.php`

O insert do fix já existe. Depois dele, avaliar o `PlaceTracker` e, se houver transição, notificar — **tudo dentro de try/catch**, para que nenhum erro de geofencing derrube o 201 do fix.

- [ ] **Step 1: Write the failing integration test**

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration\Api;

use GuardKids\Database\ChildPlaceRepository;
use GuardKids\Database\SafeZoneRepository;
use GuardKids\Tests\Integration\IntegrationTestCase;

final class ChildSelfControllerLocationPlaceTest extends IntegrationTestCase
{
    public function testTwoFixesInsideZonePersistCurrentPlace(): void
    {
        // Pré-condições: um filho pareado + uma zona "Escola". Reusar os helpers
        // do IntegrationTestCase (ver outros testes de ChildSelf para o padrão de
        // criar filho + token + habilitar localização).
        $childId = $this->createPairedChildWithLocationEnabled();
        $zoneId = (new SafeZoneRepository())->insert([
            'name' => 'Escola', 'address' => null,
            'latitude' => -8.0500, 'longitude' => -34.8800, 'radius_meters' => 100,
        ]);

        // Dois fixes seguidos no centro da Escola.
        $this->postChildLocation($childId, -8.0500, -34.8800);
        $this->postChildLocation($childId, -8.0500, -34.8800);

        $place = (new ChildPlaceRepository())->get($childId);
        self::assertNotNull($place);
        self::assertSame($zoneId, (int) $place['current_zone_id']);
    }

    public function testGeofenceErrorDoesNotBreakFixInsert(): void
    {
        // Mesmo sem nenhuma zona cadastrada, o POST do fix deve responder 201.
        $childId = $this->createPairedChildWithLocationEnabled();
        $status = $this->postChildLocation($childId, -8.0500, -34.8800);
        self::assertSame(201, $status);
    }
}
```

> Se os helpers `createPairedChildWithLocationEnabled()` / `postChildLocation()` não existirem no `IntegrationTestCase`, copie o padrão de setup de um teste existente de `ChildSelfController` (criação de filho, emissão de token via `ChildAuth`, `SettingsRepository` habilitando localização, e chamada ao controller com `WP_REST_Request`). Adapte os nomes.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ChildSelfControllerLocationPlaceTest`
Expected: FAIL (o `child_place` não é populado ainda; `testTwoFixes...` falha em `assertNotNull`).

- [ ] **Step 3: Wire the tracker into the controller**

Em `api/Controllers/ChildSelfController.php`:

Adicionar os imports no topo (junto aos outros `use`):

```php
use GuardKids\Database\ChildPlaceRepository;
use GuardKids\Geo\PlaceTracker;
use GuardKids\Notifications\GuardianNotifier;
```

No método `createLocation`, **logo após** o bloco `if ($id === 0) { ... }` e **antes** do `return new WP_REST_Response(...)`, inserir:

```php
        // Geofencing: avalia o Local e notifica transições. Fail-open — qualquer
        // erro aqui NÃO pode derrubar o fix já salvo (o 201 sai normalmente).
        try {
            $tracker = new PlaceTracker(new SafeZoneRepository(), new ChildPlaceRepository());
            $event = $tracker->evaluate(
                $childId,
                (float) $req->get_param('latitude'),
                (float) $req->get_param('longitude'),
                is_numeric($accuracy) ? (int) $accuracy : null,
            );
            if ($event !== null) {
                $notifier = new GuardianNotifier();
                if ($event['type'] === 'entered') {
                    $notifier->notifyPlaceEntered($childId, $event['placeName'], $event['icon'], $event['token']);
                } else {
                    $notifier->notifyPlaceLeft($childId, $event['placeName'], $event['token']);
                }
            }
        } catch (\Throwable $e) {
            error_log('[GuardKids] geofence falhou (fix salvo mesmo assim): ' . $e->getMessage());
        }
```

Garantir que `use GuardKids\Database\SafeZoneRepository;` esteja presente no topo (adicionar se faltar).

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ChildSelfControllerLocationPlaceTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add api/Controllers/ChildSelfController.php tests/Integration/Api/ChildSelfControllerLocationPlaceTest.php
git commit -m "feat(location): ChildSelfController aciona PlaceTracker + notifica transicao (fail-open)"
```

---

### Task 8: SafeZoneController — campo icon

**Files:**
- Modify: `api/Controllers/SafeZoneController.php` (`createArgs`, `extractData`, `toJson`)
- Test: `tests/Unit/Api/SafeZoneControllerTest.php` (existe; adicionar caso de icon) — se não existir, criar.

- [ ] **Step 1: Write the failing test**

Adicionar a `tests/Unit/Api/SafeZoneControllerTest.php` (ou criar o arquivo seguindo o padrão dos outros testes de controller — anon `wpdb`; ver `CategoryControllerTest`):

```php
    public function testCreateAcceptsAndReturnsIcon(): void
    {
        $req = new \WP_REST_Request();
        $req->set_param('name', 'Escola');
        $req->set_param('icon', '🏫');
        $req->set_param('latitude', -8.05);
        $req->set_param('longitude', -34.88);
        $req->set_param('radius_meters', 100);

        $res = (new \GuardKids\Api\Controllers\SafeZoneController())->create($req);
        $data = $res->get_data();
        self::assertSame('🏫', $data['icon']);
    }
```

> Se `SafeZoneControllerTest` não existir, criar o arquivo com o setup de `wpdb` fake que devolve a linha inserida em `get_row` (copiar de `tests/Unit/Api/CategoryControllerTest.php`, que segue exatamente esse padrão) e adaptar `denyIfFree` (Gate) para permitir — passar um `Gate` fake que retorna `true` em `can('location')`, ou o padrão já usado nos testes existentes de SafeZone.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testCreateAcceptsAndReturnsIcon`
Expected: FAIL (`icon` ausente no data / índice indefinido).

- [ ] **Step 3: Add icon to the three spots**

Em `api/Controllers/SafeZoneController.php`:

`createArgs()` — adicionar após a entrada `'name'`:

```php
            'icon' => [
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
            ],
```

`extractData()` — adicionar ao array retornado:

```php
            'icon' => (function () use ($req) {
                $icon = $req->get_param('icon');
                return is_string($icon) && $icon !== '' ? $icon : null;
            })(),
```

`toJson()` — adicionar ao array retornado:

```php
            'icon' => $row['icon'] ?? null,
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter testCreateAcceptsAndReturnsIcon`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add api/Controllers/SafeZoneController.php tests/Unit/Api/SafeZoneControllerTest.php
git commit -m "feat(safe-zones): campo icon (emoji do Local) em create/update/toJson"
```

---

### Task 9: currentPlace no ChildController + limpeza no destroy

**Files:**
- Modify: `api/Controllers/ChildController.php` (`toJson` e `destroy`)
- Test: `tests/Unit/Api/ChildControllerCurrentPlaceTest.php`

`toJson` passa a incluir `currentPlace` (do `child_place` + `safe_zones`). `destroy` apaga a linha de `child_place` do filho.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\ChildController;
use PHPUnit\Framework\TestCase;

final class ChildControllerCurrentPlaceTest extends TestCase
{
    protected function setUp(): void
    {
        // wpdb fake: child_place(child_id=5) aponta pra zona 3 = "Casa da Avó".
        $GLOBALS['wpdb'] = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public function __construct()
            {
            }
            public function prepare($query, ...$args)
            {
                $flat = $args[0] ?? null;
                if (is_array($flat)) {
                    $args = $flat;
                }
                return vsprintf(str_replace(['%d', '%s'], ['%d', "'%s'"], (string) $query), $args);
            }
            public function get_row($query = null, $output = ARRAY_A, $y = 0)
            {
                if (str_contains((string) $query, 'child_place')) {
                    return ['child_id' => 5, 'current_zone_id' => 3, 'current_since' => '2026-07-20 14:00:00'];
                }
                if (preg_match('/safe_zones WHERE id = 3/', (string) $query) === 1) {
                    return ['id' => 3, 'name' => 'Casa da Avó', 'icon' => '👵'];
                }
                return null;
            }
        };
    }

    public function testToJsonIncludesCurrentPlace(): void
    {
        $row = ['id' => 5, 'slug' => 'ana', 'name' => 'Ana', 'age' => 8, 'avatar_url' => null];
        $json = (new ChildController())->toJsonForTest($row);
        self::assertIsArray($json['currentPlace']);
        self::assertSame('Casa da Avó', $json['currentPlace']['name']);
        self::assertSame('👵', $json['currentPlace']['icon']);
    }
}
```

> `toJson` é `private`. Adicione um wrapper de teste `public function toJsonForTest(array $row): array { return $this->toJson($row); }` no `ChildController` **apenas se** não houver já um caminho público que exercite o `toJson` (os testes existentes de `ChildController` provavelmente já chamam `index`/`create` que passam por `toJson` — nesse caso, prefira asserir via esse caminho e remova o wrapper). Verifique `tests/Unit/Api/ChildControllerTest.php` antes de adicionar o wrapper.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter ChildControllerCurrentPlaceTest`
Expected: FAIL (`currentPlace` ausente).

- [ ] **Step 3: Implement**

Em `api/Controllers/ChildController.php`:

Adicionar import: `use GuardKids\Database\ChildPlaceRepository;` e `use GuardKids\Database\SafeZoneRepository;` (se faltarem).

No `toJson(array $row)`, adicionar ao array retornado a chave:

```php
            'currentPlace' => $this->currentPlace((int) ($row['id'] ?? 0)),
```

E adicionar o método privado:

```php
    /**
     * @return array{zoneId:int, name:string, icon:string, since:string}|null
     */
    private function currentPlace(int $childId): ?array
    {
        if ($childId === 0) {
            return null;
        }
        $place = (new ChildPlaceRepository())->get($childId);
        $zoneId = isset($place['current_zone_id']) && $place['current_zone_id'] !== null
            ? (int) $place['current_zone_id']
            : 0;
        if ($zoneId === 0) {
            return null;
        }
        $zone = (new SafeZoneRepository())->findById($zoneId);
        if ($zone === null) {
            return null;
        }
        return [
            'zoneId' => $zoneId,
            'name'   => (string) ($zone['name'] ?? ''),
            'icon'   => (string) ($zone['icon'] ?? ''),
            'since'  => (string) ($place['current_since'] ?? ''),
        ];
    }
```

No `destroy(...)`, após apagar o filho (e antes do retorno de sucesso), adicionar:

```php
        (new ChildPlaceRepository())->deleteByChild($childId);
```

(usar a variável de id do filho já existente no método — confira o nome local, provavelmente `$id` ou `$childId`).

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter ChildControllerCurrentPlaceTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add api/Controllers/ChildController.php tests/Unit/Api/ChildControllerCurrentPlaceTest.php
git commit -m "feat(children): currentPlace no payload + limpa child_place no destroy"
```

---

### Task 10: Geocoder (Nominatim server-side)

**Files:**
- Create: `includes/Geo/Geocoder.php`
- Test: `tests/Unit/Geo/GeocoderTest.php`

O HTTP real do Nominatim é encapsulado num método protegido `fetch()`, sobrescrito no teste (não bate na rede). `geocode()` parseia a resposta jsonv2 e cacheia via transient.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Geo;

use GuardKids\Geo\Geocoder;
use PHPUnit\Framework\TestCase;

final class GeocoderTest extends TestCase
{
    public function testParsesFirstResult(): void
    {
        $geocoder = new class () extends Geocoder {
            protected function fetch(string $query): ?string
            {
                return '[{"lat":"-8.0501","lon":"-34.8811","display_name":"Rua X, Recife"}]';
            }
            protected function cacheGet(string $key): mixed
            {
                return false;
            }
            protected function cacheSet(string $key, mixed $value): void
            {
            }
        };
        $r = $geocoder->geocode('Rua X, Recife');
        self::assertNotNull($r);
        self::assertEqualsWithDelta(-8.0501, $r['lat'], 0.0001);
        self::assertEqualsWithDelta(-34.8811, $r['lng'], 0.0001);
        self::assertSame('Rua X, Recife', $r['displayName']);
    }

    public function testReturnsNullOnEmptyResult(): void
    {
        $geocoder = new class () extends Geocoder {
            protected function fetch(string $query): ?string
            {
                return '[]';
            }
            protected function cacheGet(string $key): mixed
            {
                return false;
            }
            protected function cacheSet(string $key, mixed $value): void
            {
            }
        };
        self::assertNull($geocoder->geocode('inexistente'));
    }

    public function testReturnsNullOnHttpError(): void
    {
        $geocoder = new class () extends Geocoder {
            protected function fetch(string $query): ?string
            {
                return null;
            }
            protected function cacheGet(string $key): mixed
            {
                return false;
            }
            protected function cacheSet(string $key, mixed $value): void
            {
            }
        };
        self::assertNull($geocoder->geocode('qualquer'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter GeocoderTest`
Expected: FAIL (`Class "GuardKids\Geo\Geocoder" not found`).

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace GuardKids\Geo;

/**
 * Geocodificação via Nominatim/OpenStreetMap, no servidor. HTTP isolado em
 * fetch() (sobrescrito nos testes); cache via transient respeita a política de
 * uso (1 req/s) e evita rechamar o mesmo endereço.
 */
class Geocoder
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';
    private const CACHE_TTL = 604800; // 7 dias

    /**
     * @return array{lat:float, lng:float, displayName:string}|null
     */
    public function geocode(string $query): ?array
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        $cacheKey = 'gk_geocode_' . md5(mb_strtolower($query));
        $cached = $this->cacheGet($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $raw = $this->fetch($query);
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || $decoded === [] || ! isset($decoded[0]['lat'], $decoded[0]['lon'])) {
            return null;
        }

        $result = [
            'lat'         => (float) $decoded[0]['lat'],
            'lng'         => (float) $decoded[0]['lon'],
            'displayName' => (string) ($decoded[0]['display_name'] ?? ''),
        ];
        $this->cacheSet($cacheKey, $result);
        return $result;
    }

    /**
     * HTTP real. Retorna o corpo (JSON) ou null em erro. Protegido: sobrescrito
     * nos testes.
     */
    protected function fetch(string $query): ?string
    {
        $url = self::ENDPOINT . '?' . http_build_query([
            'q'            => $query,
            'format'       => 'jsonv2',
            'limit'        => 1,
            'countrycodes' => 'br',
        ]);
        $response = wp_remote_get($url, [
            'timeout' => 8,
            'headers' => [
                // Política do Nominatim: User-Agent identificado.
                'User-Agent' => 'GuardKids-WP/1.0 (+https://guardiaokids.site)',
                'Accept'     => 'application/json',
            ],
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $body = wp_remote_retrieve_body($response);
        return $body !== '' ? $body : null;
    }

    protected function cacheGet(string $key): mixed
    {
        return get_transient($key);
    }

    protected function cacheSet(string $key, mixed $value): void
    {
        set_transient($key, $value, self::CACHE_TTL);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter GeocoderTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/Geo/Geocoder.php tests/Unit/Geo/GeocoderTest.php
git commit -m "feat(geo): Geocoder via Nominatim server-side (cache transient, fetch isolado)"
```

---

### Task 11: GeocodeController + rota

**Files:**
- Create: `api/Controllers/GeocodeController.php`
- Modify: `api/RestApi.php` (novo `registerGeocodeRoute()` chamado em `registerRoutes()`)
- Test: `tests/Unit/Api/GeocodeControllerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\GeocodeController;
use GuardKids\Geo\Geocoder;
use PHPUnit\Framework\TestCase;

final class GeocodeControllerTest extends TestCase
{
    public function testReturnsCoordinatesForQuery(): void
    {
        $geocoder = new class () extends Geocoder {
            public function geocode(string $query): ?array
            {
                return ['lat' => -8.05, 'lng' => -34.88, 'displayName' => 'Recife'];
            }
        };
        $req = new \WP_REST_Request();
        $req->set_param('q', 'Recife');

        $res = (new GeocodeController($geocoder))->index($req);
        self::assertSame(-8.05, $res->get_data()['lat']);
    }

    public function testNotFoundReturns404(): void
    {
        $geocoder = new class () extends Geocoder {
            public function geocode(string $query): ?array
            {
                return null;
            }
        };
        $req = new \WP_REST_Request();
        $req->set_param('q', 'zzz');

        $res = (new GeocodeController($geocoder))->index($req);
        self::assertInstanceOf(\WP_Error::class, $res);
        self::assertSame(404, $res->get_error_data()['status']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter GeocodeControllerTest`
Expected: FAIL (`Class "GuardKids\Api\Controllers\GeocodeController" not found`).

- [ ] **Step 3: Create the controller**

```php
<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Geo\Geocoder;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /guardkids/v1/geocode?q=<endereço> — converte endereço em coordenada via
 * Nominatim (server-side). Só admin; resposta no-store (não cachear no edge).
 */
final class GeocodeController
{
    private readonly Geocoder $geocoder;

    public function __construct(?Geocoder $geocoder = null)
    {
        $this->geocoder = $geocoder ?? new Geocoder();
    }

    public function index(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $q = trim((string) $req->get_param('q'));
        if ($q === '') {
            return new WP_Error('missing_query', 'Informe um endereço.', ['status' => 400]);
        }

        $result = $this->geocoder->geocode($q);
        if ($result === null) {
            return new WP_Error('not_found', 'Endereço não encontrado.', ['status' => 404]);
        }

        $res = new WP_REST_Response($result, 200);
        $res->header('Cache-Control', 'no-store');
        return $res;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function indexArgs(): array
    {
        return [
            'q' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
        ];
    }
}
```

- [ ] **Step 4: Register the route**

Em `api/RestApi.php`:

Adicionar o import no topo: `use GuardKids\Api\Controllers\GeocodeController;`

Em `registerRoutes()`, adicionar a chamada junto às outras (ex.: após `$this->registerSafeZonesRoutes();`):

```php
        $this->registerGeocodeRoute();
```

Adicionar o método privado (seguindo o padrão de `registerSafeZonesRoutes`):

```php
    private function registerGeocodeRoute(): void
    {
        $controller = new GeocodeController();

        register_rest_route(self::NAMESPACE, '/geocode', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$controller, 'index'],
                'permission_callback' => [self::class, 'requireAdmin'],
                'args'                => $controller->indexArgs(),
            ],
        ]);
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter GeocodeControllerTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add api/Controllers/GeocodeController.php api/RestApi.php tests/Unit/Api/GeocodeControllerTest.php
git commit -m "feat(api): GET /guardkids/v1/geocode (Nominatim, admin, no-store)"
```

---

### Task 12: Front — tipos + cliente geocode + safeZones.icon

**Files:**
- Modify: `public/app-parent/src/api/types.ts`
- Create: `public/app-parent/src/api/geocode.ts`
- Modify: `public/app-parent/src/api/safeZones.ts`
- Test: `public/app-parent/src/api/geocode.test.ts`

- [ ] **Step 1: Write the failing test**

```ts
import { describe, expect, it, vi } from 'vitest';
import { geocodeAddress } from './geocode';
import * as client from './client';

describe('geocodeAddress', () => {
  it('retorna lat/lng do endpoint', async () => {
    vi.spyOn(client, 'apiFetch').mockResolvedValue({ lat: -8.05, lng: -34.88, displayName: 'Recife' });
    const r = await geocodeAddress('Recife');
    expect(r).toEqual({ lat: -8.05, lng: -34.88, displayName: 'Recife' });
  });
});
```

> Ajuste o import de `apiFetch` ao que `src/api/client.ts` realmente exporta (visto na Task de client: `apiFetch<T>(path, init?)`). Se o spy em módulo não funcionar pelo bundler, use MSW como nos outros testes de api — copie o padrão de `src/api/safeZones.test.ts` se existir.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd public/app-parent && npx vitest run src/api/geocode.test.ts`
Expected: FAIL (módulo `./geocode` não existe).

- [ ] **Step 3: Implement types + client**

Em `src/api/types.ts`:

Adicionar ao type `SafeZone` (localizar a definição existente) o campo:

```ts
  icon: string | null;
```

Adicionar ao type `Child` o campo:

```ts
  currentPlace: { zoneId: number; name: string; icon: string; since: string } | null;
```

Criar `src/api/geocode.ts`:

```ts
import { apiFetch } from './client';

export type GeocodeResult = { lat: number; lng: number; displayName: string };

export function geocodeAddress(query: string): Promise<GeocodeResult> {
  return apiFetch<GeocodeResult>(`/geocode?q=${encodeURIComponent(query)}`);
}
```

Em `src/api/safeZones.ts`: incluir `icon` no payload de create/update (localizar as funções `createSafeZone`/`updateSafeZone` e adicionar `icon: string | null` ao tipo do input, propagando ao corpo enviado).

- [ ] **Step 4: Run test to verify it passes**

Run: `cd public/app-parent && npx vitest run src/api/geocode.test.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add public/app-parent/src/api/types.ts public/app-parent/src/api/geocode.ts public/app-parent/src/api/safeZones.ts public/app-parent/src/api/geocode.test.ts
git commit -m "feat(front): tipos currentPlace/icon + cliente geocodeAddress"
```

---

### Task 13: Front — SafeZoneDialog (emoji + buscar endereço + raio 50/100/200)

**Files:**
- Modify: `public/app-parent/src/components/SafeZoneDialog.tsx`
- Test: `public/app-parent/src/components/SafeZoneDialog.test.tsx` (existe; adicionar casos) ou criar.

- [ ] **Step 1: Write the failing test**

```tsx
import { describe, expect, it, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { SafeZoneDialog } from './SafeZoneDialog';
import * as geo from '../api/geocode';

function wrap(ui: React.ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

describe('SafeZoneDialog — endereço', () => {
  it('botão Buscar geocodifica e não quebra', async () => {
    vi.spyOn(geo, 'geocodeAddress').mockResolvedValue({ lat: -8.05, lng: -34.88, displayName: 'Recife' });
    wrap(<SafeZoneDialog open mode="create" initial={null} onClose={() => {}} />);
    // avança pro passo do endereço (ajuste conforme o wizard: clicar num template)
    fireEvent.click(screen.getByText(/Casa/i));
    fireEvent.change(screen.getByLabelText(/Endereço/i), { target: { value: 'Recife' } });
    fireEvent.click(screen.getByRole('button', { name: /Buscar/i }));
    await waitFor(() => expect(geo.geocodeAddress).toHaveBeenCalledWith('Recife'));
  });

  it('oferece raios 50, 100 e 200', () => {
    wrap(<SafeZoneDialog open mode="create" initial={null} onClose={() => {}} />);
    fireEvent.click(screen.getByText(/Casa/i));
    expect(screen.getByText('50 m')).toBeInTheDocument();
    expect(screen.getByText('100 m')).toBeInTheDocument();
    expect(screen.getByText('200 m')).toBeInTheDocument();
  });
});
```

> Ajuste os seletores ao markup real do wizard (passos/labels). O objetivo das asserções é fixo: botão "Buscar" chama `geocodeAddress`; opções de raio são 50/100/200.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd public/app-parent && npx vitest run src/components/SafeZoneDialog.test.tsx`
Expected: FAIL (sem botão "Buscar" / raios antigos).

- [ ] **Step 3: Implement**

Em `src/components/SafeZoneDialog.tsx`:

1. Trocar as opções de raio:

```ts
const RADIUS_OPTIONS = [50, 100, 200] as const;
```

2. Adicionar estado do ícone e a lista de presets no topo do componente:

```ts
const ICON_PRESETS = ['🏠', '👵', '🏫', '⚽', '🏥', '🎹'] as const;
```

Dentro de `SafeZoneDialog`, junto aos outros `useState`:

```ts
const [icon, setIcon] = useState<string>('');
const [geocoding, setGeocoding] = useState(false);
const [geoError, setGeoError] = useState<string | null>(null);
```

No `useEffect` de reset (open/edit), setar `icon`:

```ts
// no ramo edit:
setIcon(initial.icon ?? '');
// no ramo create:
setIcon('');
```

3. No `mutation.mutate({...})` (submit), incluir `icon`:

```ts
icon: icon || null,
```

E adicionar `icon: string | null;` ao tipo do input da `mutationFn`.

4. Na etapa de posição/endereço (`StepPosition`), adicionar o botão "Buscar" ao lado do campo Endereço. Handler dentro do componente pai, passado como prop:

```ts
async function handleGeocode() {
  const q = address.trim();
  if (!q) return;
  setGeoError(null);
  setGeocoding(true);
  try {
    const r = await geocodeAddress(q);
    setLat(r.lat);
    setLng(r.lng);
  } catch {
    setGeoError('Endereço não encontrado. Ajuste o pino no mapa.');
  } finally {
    setGeocoding(false);
  }
}
```

Importar no topo: `import { geocodeAddress } from '../api/geocode';`

No JSX do campo Endereço (`StepPosition`), adicionar o botão (passando `onSearch`, `searching`, `error` como props do StepPosition):

```tsx
<button type="button" onClick={onSearch} disabled={searching} className={/* estilo secundário existente */}>
  {searching ? 'Buscando…' : 'Buscar'}
</button>
{error && <p className="text-label-sm text-error">{error}</p>}
```

5. Adicionar um seletor de emoji simples na etapa do nome (`StepName`), passando `icon`/`onIconChange` como props:

```tsx
<div className="flex gap-2">
  <button type="button" onClick={() => onIconChange('')} aria-pressed={icon === ''}>—</button>
  {ICON_PRESETS.map((e) => (
    <button key={e} type="button" onClick={() => onIconChange(e)} aria-pressed={icon === e}>{e}</button>
  ))}
</div>
```

Fie as props novas (`icon`, `onIconChange`, `onSearch`, `searching`, `error`) nas assinaturas dos sub-componentes `StepName`/`StepPosition` conforme o padrão já usado (o componente já passa `address`/`onAddressChange`).

- [ ] **Step 4: Run test to verify it passes**

Run: `cd public/app-parent && npx vitest run src/components/SafeZoneDialog.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add public/app-parent/src/components/SafeZoneDialog.tsx public/app-parent/src/components/SafeZoneDialog.test.tsx
git commit -m "feat(front): SafeZoneDialog — emoji, botao Buscar endereco, raio 50/100/200"
```

---

### Task 14: Front — banner de local atual na Localização

**Files:**
- Modify: `public/app-parent/src/pages/Localizacao.tsx`
- Test: `public/app-parent/src/pages/Localizacao.test.tsx` (existe; adicionar casos)

Banner acima do mapa: dentro → "📍 <Filho> está na <Local> · desde <hora>"; fora → "<Filho> está fora dos locais cadastrados". Fonte: `child.currentPlace` (já vem do servidor).

- [ ] **Step 1: Write the failing test**

```tsx
// Adicionar ao Localizacao.test.tsx (seguir o setup de QueryClient + MSW já existente no arquivo).
it('mostra o local atual quando currentPlace vem preenchido', async () => {
  // Mockar listChildren para devolver um filho com currentPlace.
  // (Reusar o helper/handler do arquivo; garantir currentPlace: {name:'Casa da Avó', icon:'👵', since:'2026-07-20T14:00:00Z', zoneId:3})
  renderLocalizacao();
  expect(await screen.findByText(/está na Casa da Avó/i)).toBeInTheDocument();
});

it('mostra "fora dos locais" quando currentPlace é null', async () => {
  renderLocalizacao(); // filho com currentPlace: null
  expect(await screen.findByText(/fora dos locais cadastrados/i)).toBeInTheDocument();
});
```

> Adapte ao padrão do arquivo (handlers MSW/mocks já existentes). O que importa: renderiza o nome do Local quando `currentPlace != null` e o texto de fallback quando `null`.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd public/app-parent && npx vitest run src/pages/Localizacao.test.tsx`
Expected: FAIL (texto do banner não existe).

- [ ] **Step 3: Implement**

Em `src/pages/Localizacao.tsx`, dentro de `LocalizacaoContent`, quando há `child` selecionado, renderizar o banner antes do `<LocationMap>`:

```tsx
{child !== null && <CurrentPlaceBanner child={child} />}
```

E adicionar o componente:

```tsx
function CurrentPlaceBanner({ child }: { child: Child }) {
  const place = child.currentPlace;
  const since = place ? new Date(place.since).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) : '';
  return (
    <div role="status" className="glass-panel flex items-center gap-3 rounded-2xl px-4 py-3">
      <span className="text-2xl">{place?.icon || '📍'}</span>
      <p className="text-body-md text-on-surface">
        {place
          ? <><strong>{child.name}</strong> está na <strong>{place.name}</strong> · desde {since}</>
          : <><strong>{child.name}</strong> está fora dos locais cadastrados</>}
      </p>
    </div>
  );
}
```

Garantir que `Child` está importado (já está: `import type { Child, ... } from '../api/types'`).

- [ ] **Step 4: Run test to verify it passes**

Run: `cd public/app-parent && npx vitest run src/pages/Localizacao.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add public/app-parent/src/pages/Localizacao.tsx public/app-parent/src/pages/Localizacao.test.tsx
git commit -m "feat(front): banner de local atual (currentPlace) na Localizacao"
```

---

### Task 15: Front — renomear "Zonas Seguras" → "Locais"

**Files:**
- Modify: `public/app-parent/src/data/mockData.ts` (label em `navItems`)
- Modify: `public/app-parent/src/pages/ZonasSeguras.tsx` (título/subtítulo do `PageHeader`)
- Grep para achar toda ocorrência de rótulo visível.

Renome é **só de texto visível**. O `PageId` interno `safe-zones` fica (não quebra roteamento).

- [ ] **Step 1: Localizar as ocorrências de rótulo**

Run: `cd public/app-parent && grep -rn "Zonas Seguras" src/`
Expected: lista de labels (navItems, PageHeader, possivelmente PremiumLock/textos).

- [ ] **Step 2: Trocar cada rótulo visível**

Substituir o texto visível `Zonas Seguras` → `Locais` em cada arquivo listado (label do menu, título da página). NÃO trocar o identificador `'safe-zones'` (PageId), nem nomes de arquivo/rota. Ajustar subtítulos que digam "zona segura" para "local" onde fizer sentido (ex.: PageHeader subtitle).

- [ ] **Step 3: Verificar build e testes de front**

Run: `cd public/app-parent && npx tsc --noEmit && npx vitest run`
Expected: PASS (nenhum teste dependia do texto antigo; se algum asserir "Zonas Seguras", atualizar para "Locais").

- [ ] **Step 4: Commit**

```bash
git add public/app-parent/src/
git commit -m "feat(front): renomeia UI 'Zonas Seguras' -> 'Locais'"
```

---

### Task 16: Gate final + rebuild do dist

**Files:** nenhum novo — verificação de fechamento.

- [ ] **Step 1: Suíte PHP unit**

Run: `vendor/bin/phpunit`
Expected: PASS (todas, incluindo as novas de Geo/Notifications/Api/Database).

- [ ] **Step 2: Suíte PHP integração**

Run: `vendor/bin/phpunit -c phpunit-integration.xml.dist`
Expected: PASS.

- [ ] **Step 3: Front tsc + vitest + build**

Run: `cd public/app-parent && npx tsc --noEmit && npx vitest run && npm run build`
Expected: PASS; `dist/` reconstruído com a feature (o deploy usa o dist buildado).

- [ ] **Step 4: Commit (se o build gerou algo versionado)**

O `dist/` é gitignored — nada a commitar dele. Se algum snapshot/lock mudou, commitar. Caso contrário, nada a fazer.

---

## Self-Review

**1. Cobertura da spec:**
- Avaliação server-side + push → Tasks 5, 6, 7. ✅
- Endereço move o pino, mapa é a verdade, ajuste manual → Task 13 (botão Buscar + `MapClickHandler` existente). ✅
- Nominatim server-side → Tasks 10, 11. ✅
- Confirmação anti-spam (N fixes) → Task 5 (`CONFIRM_FIXES`). ✅
- Renome "Locais" + emoji → Tasks 8 (backend icon), 13 (emoji UI), 15 (renome). ✅
- `currentPlace` do servidor + banner → Tasks 9, 14. ✅
- Modelo de dados (migration 026, icon, child_place, DB v26) → Tasks 3, 4. ✅
- Regras: menor raio vence, accuracy>raio ignora → Task 2. ✅
- Limpeza de child_place no destroy → Task 9. ✅
- Testes (unit/integração/front) → cada task tem TDD; Task 16 fecha o gate. ✅

**2. Placeholders:** as notas "ajuste ao markup real / copie o padrão de X" são orientações de adaptação a padrões existentes, não lacunas de design — o comportamento assertado é fixo em cada teste. Sem TBD/TODO de lógica.

**3. Consistência de tipos:** `PlaceTracker::evaluate` retorna `{type,zoneId,placeName,icon,token}` (Task 5) e é exatamente o consumido na Task 7. `currentPlace` tem a mesma forma em PHP (Task 9), types.ts (Task 12) e banner (Task 14). `ChildPlaceRepository::get/upsert/deleteByChild` (Task 4) casam com os usos em Tasks 5, 7, 9. `Geocoder::geocode` (Task 10) casa com o controller (Task 11) e o cliente `geocodeAddress` (Task 12).

## Deploy (após implementação e gate verdes)

Bump de versão + deploy seguem o processo já usado na v1.36.12 (ver memória / `docs`): bump `Version` + `GUARDKIDS_VERSION` em `guardkids.php`, **a migração 026 roda sozinha em prod** no load (DB v25→26), rebuild do `dist`, swap via SSH (guardkids.php + `public/app-parent/dist/`), smoke. Diferente da v1.36.12, **esta tem migração** — validar `guardkids_db_version = 26` em prod após o deploy.
