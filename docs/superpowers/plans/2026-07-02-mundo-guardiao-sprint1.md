# Mundo Guardião Sprint 1 (infra) — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Criar a infraestrutura do módulo "Mundo Guardião" (banco, REST, repositórios, navegação e telas vazias nos dois apps) sem tocar em nada existente e sem lógica de conteúdo.

**Architecture:** 5 tabelas `content_*` (migração 016) + 5 Repositories (padrão do projeto) + `ContentController` (6 endpoints + summary). Nav nova nos dois apps + `ContentDashboard` (pais, zerado) e tela `Mundo` estática (filho, 7 cards). 5 componentes React no app-filho.

**Tech Stack:** PHP 8.2 (`$wpdb`), PHPUnit 9.6. React 19 + TS + Vitest 2 + TanStack Query 5.

**Spec:** `docs/superpowers/specs/2026-07-02-mundo-guardiao-sprint1-design.md`

**Ambiente:** branch `feat/mundo-guardiao-sprint1`. PHPUnit:
```bash
PHP="/c/Users/mysho/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe"
EXT="$("$PHP" -r 'echo dirname(PHP_BINARY);')/ext"
RUN(){ "$PHP" -d extension_dir="$EXT" -d extension=openssl -d extension=mbstring -d extension=sodium \
  vendor/bin/phpunit -c phpunit.xml.dist --testsuite unit "$@"; }
```
App-parent/child: `cd public/app-<x> && pnpm test <arquivo>` / `pnpm exec tsc -b`.

---

## File Structure

**Backend novo:** `database/migrations/016_content_module.php`; `database/{ContentCategoryRepository,ContentRepository,FavoriteRepository,RecommendationRepository,HistoryRepository}.php`; `api/Controllers/ContentController.php`.
**Backend modificado:** `guardkids.php` (DB v16), `uninstall.php`, `api/RestApi.php`.
**app-parent:** `src/data/mockData.ts` (PageId+navItems), `src/App.tsx`, `src/api/content.ts`, `src/pages/ContentDashboard.tsx`.
**app-child:** `src/data/mockData.ts` (PageId), `src/components/BottomNav.tsx`, `src/components/Header.tsx`, `src/App.tsx`, `src/pages/Mundo.tsx`, `src/components/{CategoryCard,ContentCard,FavoriteButton,RecommendationCard,EmptyState}.tsx`, `src/api/content.ts`, `src/api/types.ts`.

---

## Task 1: Migração 016 + tabelas + bump DB v16 + uninstall

**Files:** Create `database/migrations/016_content_module.php`; Modify `guardkids.php`, `uninstall.php`.

- [ ] **Step 1: Criar a migração**

`database/migrations/016_content_module.php`:
```php
<?php

declare(strict_types=1);

/**
 * Migration 016 — módulo Mundo Guardião (infra). 5 tabelas content_*.
 * Nomeadas com prefixo content_ pra não colidir com guardkids_categories
 * (categorias de sites, já existente).
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $p = $wpdb->prefix . 'guardkids_';

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}content_categories (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(64) NOT NULL,
        name VARCHAR(120) NOT NULL,
        icon VARCHAR(48) NULL,
        description VARCHAR(255) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY slug_unq (slug)
    ) {$charsetCollate};");

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}content_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        category_id BIGINT UNSIGNED NULL,
        title VARCHAR(160) NOT NULL,
        description VARCHAR(255) NULL,
        url VARCHAR(512) NULL,
        type VARCHAR(32) NOT NULL DEFAULT 'link',
        thumbnail VARCHAR(512) NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY category (category_id)
    ) {$charsetCollate};");

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}content_favorites (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        child_id BIGINT UNSIGNED NOT NULL,
        content_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY child_content (child_id, content_id),
        KEY child (child_id)
    ) {$charsetCollate};");

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}content_recommendations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        child_id BIGINT UNSIGNED NOT NULL,
        content_id BIGINT UNSIGNED NOT NULL,
        guardian_id BIGINT UNSIGNED NULL,
        note VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY child (child_id)
    ) {$charsetCollate};");

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}content_history (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        child_id BIGINT UNSIGNED NOT NULL,
        content_id BIGINT UNSIGNED NOT NULL,
        action VARCHAR(32) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY child_created (child_id, created_at)
    ) {$charsetCollate};");
};
```

- [ ] **Step 2: Bump DB version** — `guardkids.php`: `define('GUARDKIDS_DB_VERSION', 15);` → `16`.

- [ ] **Step 3: uninstall** — em `uninstall.php`, adicionar ao array `$tables`:
```php
    $wpdb->prefix . 'guardkids_content_categories',
    $wpdb->prefix . 'guardkids_content_items',
    $wpdb->prefix . 'guardkids_content_favorites',
    $wpdb->prefix . 'guardkids_content_recommendations',
    $wpdb->prefix . 'guardkids_content_history',
```

- [ ] **Step 4: Rodar MigrationRunnerTest** — `RUN --filter MigrationRunnerTest` → PASS (Windows pode falso-falhar por glob; CI valida).

- [ ] **Step 5: Commit**
```bash
git add database/migrations/016_content_module.php guardkids.php uninstall.php
git commit -m "feat(db): migração 016 — tabelas content_* + bump DB v16"
```

---

## Task 2: 5 Repositories

**Files:** Create os 5 repos em `database/`; Test `tests/Unit/Database/ContentRepositoriesTest.php`.

- [ ] **Step 1: Teste que falha**

`tests/Unit/Database/ContentRepositoriesTest.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\ContentCategoryRepository;
use GuardKids\Database\ContentRepository;
use GuardKids\Database\FavoriteRepository;
use GuardKids\Database\HistoryRepository;
use GuardKids\Database\RecommendationRepository;
use PHPUnit\Framework\TestCase;

final class ContentRepositoriesTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<string, array<int, array<string, mixed>>> por tabela */
            public array $t = [];

            public function __construct()
            {
            }

            public function prepare($query, ...$args)
            {
                $flat = $args[0] ?? null;
                if (is_array($flat)) {
                    $args = $flat;
                }
                return vsprintf(str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%F'], (string) $query), $args);
            }

            private function tableOf(string $sql): string
            {
                preg_match('/guardkids_(content_[a-z_]+)/', $sql, $m);
                return $m[1] ?? '';
            }

            public function insert($table, $data, $format = null)
            {
                $name = $this->tableOf((string) $table);
                $this->t[$name] ??= [];
                $id = count($this->t[$name]) + 1;
                $this->insert_id = $id;
                $this->t[$name][$id] = array_merge(['id' => $id], $data);
                return 1;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $name = $this->tableOf((string) $sql);
                $rows = array_values($this->t[$name] ?? []);
                if (preg_match('/category_id = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['category_id'] ?? 0) === (int) $m[1]));
                }
                if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['child_id'] ?? 0) === (int) $m[1]));
                }
                return $rows;
            }

            public function get_var($sql, $x = 0, $y = 0)
            {
                if (str_contains((string) $sql, 'COUNT(*)')) {
                    return (string) count($this->t[$this->tableOf((string) $sql)] ?? []);
                }
                return null;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testCategoryCreateAllCount(): void
    {
        $repo = new ContentCategoryRepository();
        $repo->create(['slug' => 'games', 'name' => 'Jogos', 'icon' => 'x', 'sort_order' => 1]);
        self::assertCount(1, $repo->all());
        self::assertSame(1, $repo->count());
    }

    public function testContentCreateFindByCategory(): void
    {
        $repo = new ContentRepository();
        $repo->create(['category_id' => 5, 'title' => 'A']);
        $repo->create(['category_id' => 9, 'title' => 'B']);
        self::assertSame(2, $repo->count());
        self::assertCount(1, $repo->findByCategory(5));
    }

    public function testFavoriteAddFindByChildCount(): void
    {
        $repo = new FavoriteRepository();
        $repo->add(1, 10);
        $repo->add(2, 10);
        self::assertSame(2, $repo->count());
        self::assertCount(1, $repo->findByChild(1));
    }

    public function testRecommendationAddCount(): void
    {
        $repo = new RecommendationRepository();
        $repo->add(1, 10, 7, 'olha isso');
        self::assertSame(1, $repo->count());
        self::assertCount(1, $repo->all());
    }

    public function testHistoryAddCount(): void
    {
        $repo = new HistoryRepository();
        $repo->add(1, 10, 'open');
        self::assertSame(1, $repo->count());
    }
}
```

- [ ] **Step 2: Rodar e falhar** — `RUN --filter ContentRepositoriesTest` → FAIL (classes inexistentes).

- [ ] **Step 3: Implementar os 5 repos**

`database/ContentCategoryRepository.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class ContentCategoryRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'content_categories';
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->findAll('sort_order', 'ASC');
    }

    public function count(): int
    {
        return (int) $this->db->get_var('SELECT COUNT(*) FROM ' . $this->table());
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $ok = $this->db->insert($this->table(), $data + ['created_at' => current_time('mysql', true)]);
        return $ok === false ? 0 : (int) $this->db->insert_id;
    }
}
```

`database/ContentRepository.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class ContentRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'content_items';
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->findAll('id', 'DESC');
    }

    /** @return array<int, array<string, mixed>> */
    public function findByCategory(int $categoryId): array
    {
        return $this->findWhere(['category_id' => $categoryId], 'id', 'DESC');
    }

    public function count(): int
    {
        return (int) $this->db->get_var('SELECT COUNT(*) FROM ' . $this->table());
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $ok = $this->db->insert($this->table(), $data + ['created_at' => current_time('mysql', true)]);
        return $ok === false ? 0 : (int) $this->db->insert_id;
    }
}
```

`database/FavoriteRepository.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class FavoriteRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'content_favorites';
    }

    /** @return array<int, array<string, mixed>> */
    public function findByChild(int $childId): array
    {
        return $this->findWhere(['child_id' => $childId], 'id', 'DESC');
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->findAll('id', 'DESC');
    }

    public function count(): int
    {
        return (int) $this->db->get_var('SELECT COUNT(*) FROM ' . $this->table());
    }

    public function add(int $childId, int $contentId): int
    {
        $ok = $this->db->insert($this->table(), [
            'child_id'   => $childId,
            'content_id' => $contentId,
            'created_at' => current_time('mysql', true),
        ]);
        return $ok === false ? 0 : (int) $this->db->insert_id;
    }
}
```

`database/RecommendationRepository.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class RecommendationRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'content_recommendations';
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->findAll('id', 'DESC');
    }

    public function count(): int
    {
        return (int) $this->db->get_var('SELECT COUNT(*) FROM ' . $this->table());
    }

    public function add(int $childId, int $contentId, ?int $guardianId, ?string $note): int
    {
        $ok = $this->db->insert($this->table(), [
            'child_id'    => $childId,
            'content_id'  => $contentId,
            'guardian_id' => $guardianId,
            'note'        => $note,
            'created_at'  => current_time('mysql', true),
        ]);
        return $ok === false ? 0 : (int) $this->db->insert_id;
    }
}
```

`database/HistoryRepository.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Database;

final class HistoryRepository extends Repository
{
    protected function tableSuffix(): string
    {
        return 'content_history';
    }

    public function count(): int
    {
        return (int) $this->db->get_var('SELECT COUNT(*) FROM ' . $this->table());
    }

    public function add(int $childId, int $contentId, string $action): int
    {
        $ok = $this->db->insert($this->table(), [
            'child_id'   => $childId,
            'content_id' => $contentId,
            'action'     => $action,
            'created_at' => current_time('mysql', true),
        ]);
        return $ok === false ? 0 : (int) $this->db->insert_id;
    }
}
```

- [ ] **Step 4: Rodar e passar** — `RUN --filter ContentRepositoriesTest` → PASS (5 testes).

- [ ] **Step 5: Commit**
```bash
git add database/Content*.php database/FavoriteRepository.php database/RecommendationRepository.php database/HistoryRepository.php tests/Unit/Database/ContentRepositoriesTest.php
git commit -m "feat(content): 5 repositories do Mundo Guardião"
```

---

## Task 3: ContentController + rotas

**Files:** Create `api/Controllers/ContentController.php`; Modify `api/RestApi.php`; Test `tests/Unit/Api/ContentControllerTest.php`.

- [ ] **Step 1: Teste que falha**

`tests/Unit/Api/ContentControllerTest.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\ContentController;
use GuardKids\Auth\ChildAuth;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ContentControllerTest extends TestCase
{
    private \wpdb $wpdb;
    private string $token = '';

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<string, string> */
            public array $settings = [];
            /** @var array<string, array<int, array<string, mixed>>> */
            public array $t = [];

            public function __construct()
            {
            }

            public function prepare($query, ...$args)
            {
                $flat = $args[0] ?? null;
                if (is_array($flat)) {
                    $args = $flat;
                }
                return vsprintf(str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%F'], (string) $query), $args);
            }

            public function get_var($sql, $x = 0, $y = 0)
            {
                if (preg_match("/setting_key = '([^']+)'/", (string) $sql, $m) === 1) {
                    if (str_contains((string) $sql, 'SELECT id')) {
                        return isset($this->settings[$m[1]]) ? '1' : null;
                    }
                    return $this->settings[$m[1]] ?? null;
                }
                if (str_contains((string) $sql, 'COUNT(*)') && preg_match('/guardkids_(content_[a-z_]+)/', (string) $sql, $m) === 1) {
                    return (string) count($this->t[$m[1]] ?? []);
                }
                return null;
            }

            public function get_results($sql, $output = OBJECT)
            {
                if (preg_match('/guardkids_(content_[a-z_]+)/', (string) $sql, $m) === 1) {
                    return array_values($this->t[$m[1]] ?? []);
                }
                return [];
            }

            public function insert($table, $data, $format = null)
            {
                if (str_contains((string) $table, 'guardkids_settings')) {
                    $this->settings[$data['setting_key']] = (string) $data['value'];
                    return 1;
                }
                if (preg_match('/guardkids_(content_[a-z_]+)/', (string) $table, $m) === 1) {
                    $this->t[$m[1]] ??= [];
                    $id = count($this->t[$m[1]]) + 1;
                    $this->insert_id = $id;
                    $this->t[$m[1]][$id] = array_merge(['id' => $id], $data);
                    return 1;
                }
                return 0;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
        $GLOBALS['gk_current_user_id'] = 7;
        $issued = (new ChildAuth())->issueToken(1, 'tablet');
        $this->token = $issued['token'];
    }

    private function tokenReq(string $method, string $route): WP_REST_Request
    {
        $req = new WP_REST_Request($method, $route);
        $req->set_header('X-GuardKids-Token', $this->token);
        return $req;
    }

    public function testCategoriesReturnsArray(): void
    {
        $res = (new ContentController())->categories(new WP_REST_Request('GET', '/content/categories'));
        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertSame([], $res->get_data());
    }

    public function testSummaryCountsZeroWithNullSync(): void
    {
        $res = (new ContentController())->summary(new WP_REST_Request('GET', '/content/summary'));
        $data = $res->get_data();
        self::assertSame(0, $data['contents']);
        self::assertSame(0, $data['categories']);
        self::assertSame(0, $data['favorites']);
        self::assertSame(0, $data['recommendations']);
        self::assertNull($data['lastSync']);
    }

    public function testCreateRecommendationInserts(): void
    {
        $req = new WP_REST_Request('POST', '/content/recommendations');
        $req->set_param('child_id', 1);
        $req->set_param('content_id', 10);
        $req->set_param('note', 'olha');
        $res = (new ContentController())->createRecommendation($req);
        self::assertSame(201, $res->get_status());
        self::assertNotEmpty($this->wpdb->t['content_recommendations']);
    }

    public function testAddFavoriteUsesChildIdFromToken(): void
    {
        $req = $this->tokenReq('POST', '/content/favorites');
        $req->set_param('content_id', 10);
        $res = (new ContentController())->addFavorite($req);
        self::assertSame(201, $res->get_status());
        $row = $this->wpdb->t['content_favorites'][1];
        self::assertSame(1, (int) $row['child_id']);
    }

    public function testAddFavorite401WithoutToken(): void
    {
        $req = new WP_REST_Request('POST', '/content/favorites');
        $req->set_param('content_id', 10);
        $res = (new ContentController())->addFavorite($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(401, $res->get_error_data()['status']);
    }
}
```

- [ ] **Step 2: Rodar e falhar** — `RUN --filter ContentControllerTest` → FAIL.

- [ ] **Step 3: Implementar o controller**

`api/Controllers/ContentController.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Auth\ChildAuth;
use GuardKids\Database\ContentCategoryRepository;
use GuardKids\Database\ContentRepository;
use GuardKids\Database\FavoriteRepository;
use GuardKids\Database\HistoryRepository;
use GuardKids\Database\RecommendationRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Endpoints do módulo Mundo Guardião. Leitura/gestão pelos pais (admin) e
 * POST de favorito pela criança (token). Sprint 1: infra, sem curadoria.
 */
final class ContentController
{
    private readonly ContentCategoryRepository $categoriesRepo;
    private readonly ContentRepository $contentRepo;
    private readonly FavoriteRepository $favorites;
    private readonly RecommendationRepository $recommendations;
    private readonly HistoryRepository $history;
    private readonly ChildAuth $auth;

    public function __construct()
    {
        $this->categoriesRepo  = new ContentCategoryRepository();
        $this->contentRepo     = new ContentRepository();
        $this->favorites       = new FavoriteRepository();
        $this->recommendations = new RecommendationRepository();
        $this->history         = new HistoryRepository();
        $this->auth            = new ChildAuth();
    }

    public function categories(WP_REST_Request $req): WP_REST_Response
    {
        return rest_ensure_response(array_map(static fn (array $r): array => [
            'id'          => (int) $r['id'],
            'slug'        => (string) ($r['slug'] ?? ''),
            'name'        => (string) ($r['name'] ?? ''),
            'icon'        => $r['icon'] ?? null,
            'description' => $r['description'] ?? null,
        ], $this->categoriesRepo->all()));
    }

    public function contents(WP_REST_Request $req): WP_REST_Response
    {
        return rest_ensure_response(array_map(static fn (array $r): array => [
            'id'          => (int) $r['id'],
            'categoryId'  => isset($r['category_id']) ? (int) $r['category_id'] : null,
            'title'       => (string) ($r['title'] ?? ''),
            'description' => $r['description'] ?? null,
            'url'         => $r['url'] ?? null,
            'type'        => (string) ($r['type'] ?? 'link'),
            'thumbnail'   => $r['thumbnail'] ?? null,
        ], $this->contentRepo->all()));
    }

    public function favoritesList(WP_REST_Request $req): WP_REST_Response
    {
        return rest_ensure_response(array_map(static fn (array $r): array => [
            'id'        => (int) $r['id'],
            'childId'   => (int) ($r['child_id'] ?? 0),
            'contentId' => (int) ($r['content_id'] ?? 0),
            'createdAt' => $r['created_at'] ?? null,
        ], $this->favorites->all()));
    }

    public function recommendationsList(WP_REST_Request $req): WP_REST_Response
    {
        return rest_ensure_response(array_map(static fn (array $r): array => [
            'id'        => (int) $r['id'],
            'childId'   => (int) ($r['child_id'] ?? 0),
            'contentId' => (int) ($r['content_id'] ?? 0),
            'note'      => $r['note'] ?? null,
            'createdAt' => $r['created_at'] ?? null,
        ], $this->recommendations->all()));
    }

    public function summary(WP_REST_Request $req): WP_REST_Response
    {
        return rest_ensure_response([
            'contents'        => $this->contentRepo->count(),
            'categories'      => $this->categoriesRepo->count(),
            'favorites'       => $this->favorites->count(),
            'recommendations' => $this->recommendations->count(),
            'lastSync'        => null,
        ]);
    }

    public function createRecommendation(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId   = (int) $req->get_param('child_id');
        $contentId = (int) $req->get_param('content_id');
        if ($childId === 0 || $contentId === 0) {
            return new WP_Error('invalid_payload', 'child_id e content_id obrigatórios.', ['status' => 422]);
        }
        $note = $req->get_param('note');
        $id = $this->recommendations->add(
            $childId,
            $contentId,
            (int) get_current_user_id(),
            is_string($note) ? $note : null,
        );
        if ($id === 0) {
            return new WP_Error('db_error', 'Não foi possível salvar.', ['status' => 500]);
        }
        return new WP_REST_Response(['id' => $id], 201);
    }

    public function addFavorite(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        $contentId = (int) $req->get_param('content_id');
        if ($contentId === 0) {
            return new WP_Error('invalid_payload', 'content_id obrigatório.', ['status' => 422]);
        }
        $id = $this->favorites->add($childId, $contentId);
        if ($id === 0) {
            return new WP_Error('db_error', 'Não foi possível salvar.', ['status' => 500]);
        }
        return new WP_REST_Response(['id' => $id], 201);
    }
}
```

- [ ] **Step 4: Registrar rotas** — em `api/RestApi.php`, adicionar `$this->registerContentRoutes();` ao final de `registerRoutes()`, e o método (mirror de `registerPrivacyRoutes`):
```php
    private function registerContentRoutes(): void
    {
        $controller = new ContentController();

        $adminGet = static fn (string $path, string $cb) => register_rest_route(self::NAMESPACE, $path, [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, $cb],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);
        $adminGet('/content/categories', 'categories');
        $adminGet('/content', 'contents');
        $adminGet('/content/favorites', 'favoritesList');
        $adminGet('/content/recommendations', 'recommendationsList');
        $adminGet('/content/summary', 'summary');

        register_rest_route(self::NAMESPACE, '/content/recommendations', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'createRecommendation'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);

        register_rest_route(self::NAMESPACE, '/content/favorites', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'addFavorite'],
            'permission_callback' => (new ChildAuth())->requireToken(),
        ]);
    }
```
Adicionar os imports no topo do `RestApi.php`: `use GuardKids\Api\Controllers\ContentController;` e (se ainda não houver) `use GuardKids\Auth\ChildAuth;`.

> Nota: `GET` e `POST` em `/content/recommendations` coexistem (métodos diferentes) — o WP resolve por método.

- [ ] **Step 5: Rodar e passar** — `RUN --filter ContentControllerTest` → PASS (6 testes). Depois `RUN` (suíte inteira) → PASS.

- [ ] **Step 6: Commit**
```bash
git add api/Controllers/ContentController.php api/RestApi.php tests/Unit/Api/ContentControllerTest.php
git commit -m "feat(api): ContentController (6 endpoints + summary)"
```

---

## Task 4: app-parent — nav "Conteúdo Infantil" + ContentDashboard

**Files:** Modify `public/app-parent/src/data/mockData.ts`, `src/App.tsx`; Create `src/api/content.ts`, `src/pages/ContentDashboard.tsx`, `src/pages/ContentDashboard.test.tsx`.

- [ ] **Step 1: PageId + navItem**

Em `public/app-parent/src/data/mockData.ts`: adicionar `| 'content'` ao union `PageId` (após `'sites-rules'` ou em qualquer posição do union). E em `navItems`, inserir logo após a linha de `sites-rules`:
```ts
  { id: 'content' as PageId, label: 'Conteúdo Infantil', icon: 'auto_stories' },
```

- [ ] **Step 2: api + tipos**

`public/app-parent/src/api/content.ts`:
```ts
import { apiFetch } from './client';

export type ContentSummary = {
  contents: number;
  categories: number;
  favorites: number;
  recommendations: number;
  lastSync: string | null;
};

export function getContentSummary(): Promise<ContentSummary> {
  return apiFetch<ContentSummary>('/content/summary');
}
```

- [ ] **Step 3: Teste que falha**

`public/app-parent/src/pages/ContentDashboard.test.tsx`:
```tsx
import { screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { ContentDashboard } from './ContentDashboard';

const getContentSummary = vi.fn();
vi.mock('../api/content', () => ({
  getContentSummary: () => getContentSummary(),
}));

describe('ContentDashboard', () => {
  afterEach(() => getContentSummary.mockReset());

  it('mostra métricas zeradas e "Nunca" na sincronização', async () => {
    getContentSummary.mockResolvedValueOnce({
      contents: 0, categories: 0, favorites: 0, recommendations: 0, lastSync: null,
    });
    renderWithClient(<ContentDashboard />);
    expect(await screen.findByText('Nenhum conteúdo cadastrado')).toBeInTheDocument();
    expect(screen.getByText('Nunca')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /adicionar conteúdo/i })).toBeDisabled();
  });
});
```
> Se `../test/queryClient` não existir no app-parent, criar um helper `renderWithClient` idêntico ao do app-child (`QueryClientProvider` com `retry:false`).

- [ ] **Step 4: Rodar e falhar** — `cd public/app-parent && pnpm test ContentDashboard` → FAIL.

- [ ] **Step 5: Implementar a página**

`public/app-parent/src/pages/ContentDashboard.tsx`:
```tsx
import { useQuery } from '@tanstack/react-query';
import { getContentSummary } from '../api/content';

function Metric({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="rounded-2xl border border-outline-variant bg-surface p-4 shadow-sm">
      <div className="text-3xl font-bold text-primary">{value}</div>
      <div className="text-label-md text-on-surface-variant">{label}</div>
    </div>
  );
}

export function ContentDashboard() {
  const query = useQuery({ queryKey: ['content', 'summary'], queryFn: getContentSummary });
  const s = query.data;

  return (
    <main className="flex-1 space-y-6 p-6">
      <div>
        <h1 className="font-display text-headline-lg text-on-background">Conteúdo Infantil</h1>
        <p className="text-body-md text-on-surface-variant">
          O Mundo Guardião do seu filho. Cadastre conteúdos seguros para ele explorar.
        </p>
      </div>

      <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
        <Metric label="Conteúdos" value={s?.contents ?? 0} />
        <Metric label="Categorias" value={s?.categories ?? 0} />
        <Metric label="Favoritos" value={s?.favorites ?? 0} />
        <Metric label="Recomendações" value={s?.recommendations ?? 0} />
      </div>

      <div className="rounded-2xl border border-outline-variant bg-surface p-4 shadow-sm">
        <span className="text-label-md text-on-surface-variant">Última sincronização: </span>
        <span className="font-semibold text-on-surface">{s?.lastSync ?? 'Nunca'}</span>
      </div>

      <div className="flex flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed border-outline-variant p-10 text-center">
        <span className="material-symbols-outlined text-5xl text-outline">inventory_2</span>
        <p className="text-label-lg font-semibold text-on-surface">Nenhum conteúdo cadastrado</p>
        <button
          type="button"
          disabled
          className="rounded-xl bg-primary px-5 py-2.5 text-label-md font-semibold text-white opacity-60"
        >
          Adicionar Conteúdo
        </button>
      </div>
    </main>
  );
}
```

- [ ] **Step 6: Rotear no App**

Em `public/app-parent/src/App.tsx`: importar `import { ContentDashboard } from './pages/ContentDashboard';` e adicionar no switch do `PageRenderer`:
```tsx
    case 'content':
      return <ContentDashboard />;
```

- [ ] **Step 7: Rodar e passar + tsc**
```bash
cd public/app-parent && pnpm test ContentDashboard && pnpm exec tsc -b
```
Expected: PASS + TS limpo. Rodar a suíte inteira do app-parent (`pnpm test`) pra garantir que o nav novo não quebrou `SideNav`/`App` tests (se algum teste asserta a contagem de navItems, atualizar).

- [ ] **Step 8: Commit**
```bash
git add public/app-parent/src/data/mockData.ts public/app-parent/src/App.tsx public/app-parent/src/api/content.ts public/app-parent/src/pages/ContentDashboard.tsx public/app-parent/src/pages/ContentDashboard.test.tsx
git commit -m "feat(app-parent): aba Conteúdo Infantil + ContentDashboard zerado"
```

---

## Task 5: app-child — 5 componentes React

**Files:** Create `src/components/{CategoryCard,ContentCard,FavoriteButton,RecommendationCard,EmptyState}.tsx` + testes de `CategoryCard` e `EmptyState`; Create `src/api/types.ts` adições e `src/api/content.ts`.

- [ ] **Step 1: Tipos**

Em `public/app-child/src/api/types.ts`, adicionar:
```ts
export type ContentCategory = {
  id: number;
  slug: string;
  name: string;
  icon: string | null;
  description: string | null;
};

export type Content = {
  id: number;
  categoryId: number | null;
  title: string;
  description: string | null;
  url: string | null;
  type: string;
  thumbnail: string | null;
};

export type Favorite = { id: number; childId: number; contentId: number; createdAt: string | null };

export type Recommendation = {
  id: number;
  childId: number;
  contentId: number;
  note: string | null;
  createdAt: string | null;
};
```

- [ ] **Step 2: api addFavorite**

`public/app-child/src/api/content.ts`:
```ts
import { apiFetch } from './client';

/** Building block: o filho favorita um conteúdo (não wired na UI no Sprint 1). */
export function addFavorite(contentId: number): Promise<{ id: number }> {
  return apiFetch<{ id: number }>('/content/favorites', {
    method: 'POST',
    body: JSON.stringify({ content_id: contentId }),
  });
}
```

- [ ] **Step 3: Testes que falham (CategoryCard + EmptyState)**

`public/app-child/src/components/CategoryCard.test.tsx`:
```tsx
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { CategoryCard } from './CategoryCard';

describe('CategoryCard', () => {
  it('renderiza ícone, nome, descrição e contador', () => {
    render(<CategoryCard icon="school" name="Aprender" description="Conteúdos educativos" count={0} />);
    expect(screen.getByText('Aprender')).toBeInTheDocument();
    expect(screen.getByText('Conteúdos educativos')).toBeInTheDocument();
    expect(screen.getByText('0')).toBeInTheDocument();
  });

  it('mostra estado vazio quando count é 0', () => {
    render(<CategoryCard icon="school" name="Aprender" description="x" count={0} />);
    expect(screen.getByText(/em breve/i)).toBeInTheDocument();
  });
});
```

`public/app-child/src/components/EmptyState.test.tsx`:
```tsx
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { EmptyState } from './EmptyState';

describe('EmptyState', () => {
  it('renderiza a mensagem', () => {
    render(<EmptyState icon="public" message="Seu mundo será preenchido pelo papai." />);
    expect(screen.getByText('Seu mundo será preenchido pelo papai.')).toBeInTheDocument();
  });
});
```

- [ ] **Step 4: Rodar e falhar** — `cd public/app-child && pnpm test CategoryCard EmptyState` → FAIL.

- [ ] **Step 5: Implementar os 5 componentes**

`public/app-child/src/components/EmptyState.tsx`:
```tsx
import { Icon } from './Icon';

type EmptyStateProps = { icon: string; message: string };

export function EmptyState({ icon, message }: EmptyStateProps) {
  return (
    <div className="flex flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-outline-variant bg-surface-container-low p-8 text-center">
      <Icon name={icon} className="text-4xl text-primary" filled />
      <p className="text-label-md font-semibold text-on-surface-variant">{message}</p>
    </div>
  );
}
```

`public/app-child/src/components/CategoryCard.tsx`:
```tsx
import { Icon } from './Icon';

type CategoryCardProps = {
  icon: string;
  name: string;
  description: string;
  count: number;
};

export function CategoryCard({ icon, name, description, count }: CategoryCardProps) {
  return (
    <div className="glass-panel flex flex-col gap-2 rounded-2xl p-4 shadow-ambient">
      <div className="flex items-center justify-between">
        <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary-container text-on-primary-container">
          <Icon name={icon} className="text-2xl" filled />
        </div>
        <span className="rounded-full bg-surface-variant px-2 py-0.5 text-label-sm font-bold text-on-surface-variant">
          {count}
        </span>
      </div>
      <div className="font-display text-label-md font-bold text-on-surface">{name}</div>
      <div className="text-label-sm text-on-surface-variant">{description}</div>
      {count === 0 && (
        <div className="mt-1 text-label-sm italic text-on-surface-variant/70">Em breve…</div>
      )}
    </div>
  );
}
```

`public/app-child/src/components/ContentCard.tsx`:
```tsx
import type { Content } from '../api/types';
import { Icon } from './Icon';

type ContentCardProps = { content: Content };

/** Placeholder de item de conteúdo (Sprint 1: sem dados). */
export function ContentCard({ content }: ContentCardProps) {
  return (
    <div className="glass-panel flex items-center gap-3 rounded-2xl p-3 shadow-ambient">
      <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-secondary-container text-on-secondary-container">
        <Icon name="play_circle" className="text-xl" filled />
      </div>
      <div className="flex-1">
        <div className="text-label-md font-semibold text-on-surface">{content.title}</div>
        {content.description && (
          <div className="text-label-sm text-on-surface-variant">{content.description}</div>
        )}
      </div>
    </div>
  );
}
```

`public/app-child/src/components/FavoriteButton.tsx`:
```tsx
import { Icon } from './Icon';

type FavoriteButtonProps = { active?: boolean; onToggle?: () => void };

/** Coração toggle (Sprint 1: visual, sem wire aos dados). */
export function FavoriteButton({ active = false, onToggle }: FavoriteButtonProps) {
  return (
    <button
      type="button"
      onClick={onToggle}
      aria-label={active ? 'Desfavoritar' : 'Favoritar'}
      className="rounded-full p-2 text-on-surface-variant transition-colors hover:bg-surface-variant/50"
    >
      <Icon name="favorite" className={active ? 'text-error' : ''} filled={active} />
    </button>
  );
}
```

`public/app-child/src/components/RecommendationCard.tsx`:
```tsx
import type { Recommendation } from '../api/types';
import { Icon } from './Icon';

type RecommendationCardProps = { recommendation: Recommendation };

/** Placeholder de recomendação dos pais (Sprint 1: sem dados). */
export function RecommendationCard({ recommendation }: RecommendationCardProps) {
  return (
    <div className="glass-panel flex items-center gap-3 rounded-2xl border border-primary/20 bg-primary/5 p-3 shadow-ambient">
      <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-primary">
        <Icon name="recommend" className="text-xl" filled />
      </div>
      <div className="flex-1">
        <div className="text-label-md font-semibold text-on-surface">Indicado pelos pais</div>
        {recommendation.note && (
          <div className="text-label-sm text-on-surface-variant">{recommendation.note}</div>
        )}
      </div>
    </div>
  );
}
```

- [ ] **Step 6: Rodar e passar** — `pnpm test CategoryCard EmptyState` → PASS.

- [ ] **Step 7: Commit**
```bash
git add public/app-child/src/components/CategoryCard.tsx public/app-child/src/components/ContentCard.tsx public/app-child/src/components/FavoriteButton.tsx public/app-child/src/components/RecommendationCard.tsx public/app-child/src/components/EmptyState.tsx public/app-child/src/components/CategoryCard.test.tsx public/app-child/src/components/EmptyState.test.tsx public/app-child/src/api/types.ts public/app-child/src/api/content.ts
git commit -m "feat(app-child): 5 componentes do Mundo + tipos + api addFavorite"
```

---

## Task 6: app-child — tela Mundo + nav

**Files:** Modify `src/data/mockData.ts` (PageId), `src/components/BottomNav.tsx`, `src/components/Header.tsx`, `src/App.tsx`; Create `src/pages/Mundo.tsx` + `src/pages/Mundo.test.tsx`.

- [ ] **Step 1: PageId + BottomNav + Header**

Em `public/app-child/src/data/mockData.ts`: adicionar `'mundo'` ao union `PageId`:
```ts
export type PageId = 'home' | 'mundo' | 'browser' | 'requests' | 'blocked' | 'alerts' | 'location';
```

Em `src/components/BottomNav.tsx`, adicionar o item `mundo` como 2º do array `items`:
```ts
  { id: 'home', label: 'Início', icon: 'home', filled: true },
  { id: 'mundo', label: 'Mundo', icon: 'public' },
  { id: 'browser', label: 'Navegar', icon: 'travel_explore' },
  { id: 'location', label: 'Localização', icon: 'location_on' },
  { id: 'requests', label: 'Pedidos', icon: 'task_alt' },
  { id: 'alerts', label: 'Alertas', icon: 'notifications_active', badge: true },
```

Em `src/components/Header.tsx`, no mapa `titles`, adicionar:
```ts
  mundo: 'Mundo Guardião',
```

- [ ] **Step 2: Teste que falha**

`public/app-child/src/pages/Mundo.test.tsx`:
```tsx
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Mundo } from './Mundo';

describe('Mundo', () => {
  it('renderiza os 7 cards de seção', () => {
    render(<Mundo />);
    for (const name of ['Jogos', 'Aprender', 'Criar', 'Desafios', 'Favoritos', 'Indicados pelos Pais', 'Conquistas']) {
      expect(screen.getByText(name)).toBeInTheDocument();
    }
  });

  it('mostra a mensagem de mundo vazio', () => {
    render(<Mundo />);
    expect(screen.getByText('Seu mundo será preenchido pelo papai.')).toBeInTheDocument();
  });
});
```

- [ ] **Step 3: Rodar e falhar** — `pnpm test Mundo` → FAIL.

- [ ] **Step 4: Implementar a tela**

`public/app-child/src/pages/Mundo.tsx`:
```tsx
import { CategoryCard } from '../components/CategoryCard';
import { EmptyState } from '../components/EmptyState';

const sections = [
  { icon: 'sports_esports', name: 'Jogos', description: 'Jogos seguros pra se divertir' },
  { icon: 'school', name: 'Aprender', description: 'Conteúdos educativos' },
  { icon: 'palette', name: 'Criar', description: 'Solte a imaginação' },
  { icon: 'emoji_events', name: 'Desafios', description: 'Missões pra completar' },
  { icon: 'favorite', name: 'Favoritos', description: 'O que você mais gosta' },
  { icon: 'recommend', name: 'Indicados pelos Pais', description: 'Escolhidos pra você' },
  { icon: 'military_tech', name: 'Conquistas', description: 'Suas medalhas' },
];

export function Mundo() {
  return (
    <main className="flex flex-1 flex-col gap-stack-md px-container-padding-mobile py-stack-md">
      <div className="grid grid-cols-2 gap-3">
        {sections.map((s) => (
          <CategoryCard key={s.name} icon={s.icon} name={s.name} description={s.description} count={0} />
        ))}
      </div>
      <EmptyState icon="public" message="Seu mundo será preenchido pelo papai." />
    </main>
  );
}
```

- [ ] **Step 5: Rotear no App**

Em `public/app-child/src/App.tsx`: importar `import { Mundo } from './pages/Mundo';` e adicionar no switch do `PageRenderer`:
```tsx
    case 'mundo':
      return <Mundo />;
```

- [ ] **Step 6: Rodar e passar + suíte + tsc**
```bash
pnpm test Mundo && pnpm test && pnpm exec tsc -b
```
Expected: Mundo PASS; suíte inteira verde (se `BottomNav.test` asserta contagem de itens, atualizar pra 6); TS limpo.

- [ ] **Step 7: Commit**
```bash
git add public/app-child/src/data/mockData.ts public/app-child/src/components/BottomNav.tsx public/app-child/src/components/Header.tsx public/app-child/src/App.tsx public/app-child/src/pages/Mundo.tsx public/app-child/src/pages/Mundo.test.tsx
git commit -m "feat(app-child): aba Mundo (tela estática 7 cards + empty state)"
```

---

## Task 7: Verificação completa + release + deploy

- [ ] **Step 1: Suítes completas**

PHP: `RUN` → verde. App-parent e app-child:
```bash
cd public/app-parent && pnpm test && pnpm exec tsc -b && pnpm build
cd ../app-child && pnpm test && pnpm exec tsc -b && pnpm build && pnpm test:e2e
```
Expected: tudo verde.

- [ ] **Step 2: PR + CI**
```bash
git push -u origin feat/mundo-guardiao-sprint1
gh pr create --base master --head feat/mundo-guardiao-sprint1 \
  --title "feat: Mundo Guardião Sprint 1 (infraestrutura)" \
  --body "Infra do módulo: 5 tabelas content_* (migração 016, DB v16), 5 repositories, ContentController (6 endpoints + summary), aba Conteúdo Infantil (pais) + Mundo (filho, estática 7 cards), 5 componentes React. Sem lógica de conteúdo. Nada existente alterado. Spec/plano em docs/superpowers/."
```
Acompanhar CI 4 jobs. **Integration** roda a migração 016 em MySQL real.

- [ ] **Step 3: Merge squash**
```bash
gh pr merge <N> --squash --delete-branch
git checkout master && git pull --ff-only
```

- [ ] **Step 4: Bump versão + tag + release** — em `guardkids.php` bumpar `Version:` e `GUARDKIDS_VERSION` pra `1.27.0`, commit `chore(release): v1.27.0 — Mundo Guardião Sprint 1`, tag `v1.27.0`, push, zip:
```bash
"$PHP" -d extension_dir="$EXT" -d extension=zip scripts/build-release-zip.php
gh release create v1.27.0 --title "v1.27.0 — Mundo Guardião (infra)" \
  --notes "<resumo>" "C:/Users/mysho/OneDrive/Documentos/guardkids-wp/guardkids-wp-1.27.0.zip"
```

- [ ] **Step 5: Deploy SSH + smoke (confirmar DB v16)**
```bash
scp -o BatchMode=yes -P 65002 "<zip>" u217136411@82.25.73.253:~/
ssh -o BatchMode=yes -p 65002 u217136411@82.25.73.253 \
  'cd ~/domains/guardiaokids.site/public_html \
   && cp -r wp-content/plugins/guardkids-wp wp-content/plugins/guardkids-wp.bak-$(date +%Y%m%d-%H%M) \
   && wp plugin install ~/guardkids-wp-1.27.0.zip --force \
   && wp plugin get guardkids-wp --field=version \
   && wp option get guardkids_db_version \
   && wp db query "SHOW TABLES LIKE '"'"'%guardkids_content_%'"'"'" \
   && rm -f ~/guardkids-wp-1.27.0.zip'
```
Expected: version `1.27.0`, `guardkids_db_version` **16**, 5 tabelas `content_*`. Smoke: home 200, `/content/summary` sem nonce → 401/403, `/painel-filho` 200, `/painel-pais` 200.

---

## Self-Review (feito na escrita do plano)

- **Cobertura do spec:** §3 tabelas → Task 1; §4 repos → Task 2; §5 REST → Task 3; §6 app-pais → Task 4; §7 app-filho nav/tela → Task 6; §8 componentes/tipos/api → Tasks 5-6; §9 testes → embutidos; §10 não-metas → respeitadas (tela filho estática, sem reads token, botão disabled). ✅
- **Placeholders:** nenhum TODO/TBD; as notas condicionais (criar `renderWithClient` se ausente no app-parent; atualizar testes de nav se assertam contagem) trazem a ação exata.
- **Consistência de tipos:** repos `create/add/all/count/findByChild/findByCategory`; controller métodos `categories/contents/favoritesList/recommendationsList/summary/createRecommendation/addFavorite`; summary shape `{contents,categories,favorites,recommendations,lastSync}` igual no PHP, no `ContentSummary` TS e no teste; `PageId` ganha `'content'` (pais) e `'mundo'` (filho); componentes `CategoryCard(icon,name,description,count)`, `EmptyState(icon,message)`. Consistentes. ✅
