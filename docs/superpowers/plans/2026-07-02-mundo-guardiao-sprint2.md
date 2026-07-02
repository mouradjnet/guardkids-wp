# Biblioteca Inteligente (Mundo Guardião Sprint 2) — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Encher a infra da Sprint 1 com a biblioteca real: CRUD de conteúdo + recomendações ordenadas + analytics (pais), e browse/busca/filtro-idade/favoritos/histórico (filho), com estados de UX.

**Architecture:** Estende as tabelas `content_*` (migração 017), os 5 Repositories e o `ContentController` da S1. Analytics calculados em PHP puro (`ContentAnalytics`) — sem JOIN, unit-testável. Endpoints do filho sob `/child/library/*` (token). Frontend: `ContentDashboard` reescrito + `ContentForm`/`RecommendationManager` (pais); `Mundo` reescrito + `Skeleton` (filho).

**Tech Stack:** PHP 8.2 (`$wpdb`), PHPUnit 9.6. React 19 + TS + Vitest 2 + TanStack Query 5.

**Spec:** `docs/superpowers/specs/2026-07-02-mundo-guardiao-sprint2-design.md`

**Ambiente:** branch `feat/mundo-guardiao-sprint2`. PHPUnit (o `OPENSSL_CONF` só é necessário nos testes que tocam `ChildAuth::issueToken`):
```bash
PHP="/c/Users/mysho/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe"
EXT="$("$PHP" -r 'echo dirname(PHP_BINARY);')/ext"
export OPENSSL_CONF="$HOME/AppData/Local/Temp/claude/C--Users-mysho/4284f3b2-f47a-4fc8-8281-cdd4e7efe450/scratchpad/openssl.cnf"
# (se não existir: printf '[req]\ndefault_bits = 2048\ndistinguished_name = req_dn\n[req_dn]\n' > "$OPENSSL_CONF")
RUN(){ "$PHP" -d extension_dir="$EXT" -d extension=openssl -d extension=mbstring -d extension=sodium \
  vendor/bin/phpunit -c phpunit.xml.dist --testsuite unit "$@"; }
```
App-parent/child: `cd public/app-<x> && pnpm test <arquivo>` / `pnpm exec tsc -b`.

---

## File Structure

**Backend novo:** `database/migrations/017_content_library.php`; `includes/Content/ContentAnalytics.php`.
**Backend modificado:** `guardkids.php` (DB v17); `database/ContentRepository.php`, `RecommendationRepository.php`, `FavoriteRepository.php`, `HistoryRepository.php`; `api/Controllers/ContentController.php`; `api/RestApi.php`.
**app-parent:** `src/api/content.ts` (+tipos), `src/pages/ContentDashboard.tsx` (reescrita), `src/components/ContentForm.tsx`, `src/components/RecommendationManager.tsx`.
**app-child:** `src/api/content.ts` (+`src/api/types.ts`), `src/components/Skeleton.tsx`, `src/pages/Mundo.tsx` (reescrita).

---

## Task 1: Migração 017 (ALTERs idempotentes + seed 12 categorias) + DB v17

**Files:** Create `database/migrations/017_content_library.php`; Modify `guardkids.php`.

- [ ] **Step 1: Criar a migração**

`database/migrations/017_content_library.php`:
```php
<?php

declare(strict_types=1);

/**
 * Migration 017 — Biblioteca Inteligente. Estende content_items (metadados),
 * content_recommendations (sort_order), content_history (duration) e seeda as
 * 12 categorias. ADD COLUMN não é idempotente → guard addColumnIfMissing.
 *
 * @return callable(\wpdb, string): void
 */
return static function (\wpdb $wpdb, string $charsetCollate): void {
    $p = $wpdb->prefix . 'guardkids_';

    $addColumnIfMissing = static function (string $table, string $col, string $def) use ($wpdb): void {
        $found = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $col));
        if ($found === null) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
        }
    };

    $addColumnIfMissing($p . 'content_items', 'age_min', 'TINYINT UNSIGNED NOT NULL DEFAULT 0');
    $addColumnIfMissing($p . 'content_items', 'age_max', 'TINYINT UNSIGNED NOT NULL DEFAULT 99');
    $addColumnIfMissing($p . 'content_items', 'estimated_minutes', 'SMALLINT UNSIGNED NULL');
    $addColumnIfMissing($p . 'content_items', 'level', 'VARCHAR(20) NULL');
    $addColumnIfMissing($p . 'content_items', 'tags', 'VARCHAR(255) NULL');
    $addColumnIfMissing($p . 'content_recommendations', 'sort_order', 'INT NOT NULL DEFAULT 0');
    $addColumnIfMissing($p . 'content_history', 'duration_seconds', 'INT NOT NULL DEFAULT 0');

    $now = current_time('mysql', true);
    $cats = [
        ['games', 'Jogos', 'sports_esports', 1],
        ['learn', 'Aprender', 'school', 2],
        ['create', 'Criar', 'palette', 3],
        ['science', 'Ciências', 'science', 4],
        ['portuguese', 'Português', 'menu_book', 5],
        ['math', 'Matemática', 'calculate', 6],
        ['english', 'Inglês', 'translate', 7],
        ['videos', 'Vídeos', 'smart_display', 8],
        ['reading', 'Leitura', 'auto_stories', 9],
        ['school', 'Escola', 'backpack', 10],
        ['coding', 'Programação', 'code', 11],
        ['creativity', 'Criatividade', 'brush', 12],
    ];
    foreach ($cats as [$slug, $name, $icon, $order]) {
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$p}content_categories (slug, name, icon, sort_order, created_at)
             VALUES (%s, %s, %s, %d, %s)
             ON DUPLICATE KEY UPDATE name = VALUES(name), icon = VALUES(icon), sort_order = VALUES(sort_order)",
            $slug,
            $name,
            $icon,
            $order,
            $now,
        ));
    }
};
```

- [ ] **Step 2: Bump DB version** — `guardkids.php`: `define('GUARDKIDS_DB_VERSION', 16);` → `17`.

- [ ] **Step 3: Rodar MigrationRunnerTest** — `RUN --filter MigrationRunnerTest` → PASS (Windows pode falso-falhar por glob; CI valida).

- [ ] **Step 4: Commit**
```bash
git add database/migrations/017_content_library.php guardkids.php
git commit -m "feat(db): migração 017 — campos da biblioteca + seed 12 categorias + DB v17"
```

---

## Task 2: ContentRepository (search + update/delete)

**Files:** Modify `database/ContentRepository.php`; Test `tests/Unit/Database/ContentRepositorySearchTest.php`.

- [ ] **Step 1: Teste que falha**

`tests/Unit/Database/ContentRepositorySearchTest.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\ContentRepository;
use PHPUnit\Framework\TestCase;

final class ContentRepositorySearchTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];

            public function __construct()
            {
            }

            public function esc_like($text)
            {
                return addcslashes((string) $text, '_%\\');
            }

            public function prepare($query, ...$args)
            {
                $flat = $args[0] ?? null;
                if (is_array($flat)) {
                    $args = $flat;
                }
                return vsprintf(str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%F'], (string) $query), $args);
            }

            public function get_results($sql, $output = OBJECT)
            {
                $rows = array_values($this->rows);
                if (preg_match('/category_id = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['category_id'] ?? 0) === (int) $m[1]));
                }
                if (preg_match("/title LIKE '%([^%']+)%'/", (string) $sql, $m) === 1) {
                    $term = strtolower($m[1]);
                    $rows = array_values(array_filter($rows, static fn ($r) =>
                        str_contains(strtolower((string) ($r['title'] ?? '')), $term)
                        || str_contains(strtolower((string) ($r['tags'] ?? '')), $term)));
                }
                if (preg_match('/age_min <= (\d+) AND age_max >= (\d+)/', (string) $sql, $m) === 1) {
                    $age = (int) $m[1];
                    $rows = array_values(array_filter($rows, static fn ($r) =>
                        (int) ($r['age_min'] ?? 0) <= $age && (int) ($r['age_max'] ?? 99) >= $age));
                }
                return $rows;
            }

            public function insert($table, $data, $format = null)
            {
                $id = count($this->rows) + 1;
                $this->insert_id = $id;
                $this->rows[$id] = array_merge(['id' => $id], $data);
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                $id = (int) ($where['id'] ?? 0);
                if (isset($this->rows[$id])) {
                    $this->rows[$id] = array_merge($this->rows[$id], $data);
                    return 1;
                }
                return 0;
            }

            public function delete($table, $where, $where_format = null)
            {
                $id = (int) ($where['id'] ?? 0);
                if (isset($this->rows[$id])) {
                    unset($this->rows[$id]);
                    return 1;
                }
                return 0;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testSearchByCategory(): void
    {
        $repo = new ContentRepository();
        $repo->create(['category_id' => 1, 'title' => 'A', 'age_min' => 0, 'age_max' => 99]);
        $repo->create(['category_id' => 2, 'title' => 'B', 'age_min' => 0, 'age_max' => 99]);
        self::assertCount(1, $repo->search(1, null, null));
    }

    public function testSearchByTermMatchesTitleOrTags(): void
    {
        $repo = new ContentRepository();
        $repo->create(['category_id' => 1, 'title' => 'Roblox', 'tags' => 'jogo', 'age_min' => 0, 'age_max' => 99]);
        $repo->create(['category_id' => 1, 'title' => 'Khan', 'tags' => 'matematica', 'age_min' => 0, 'age_max' => 99]);
        self::assertCount(1, $repo->search(null, 'roblox', null));
        self::assertCount(1, $repo->search(null, 'matemat', null));
    }

    public function testSearchByAgeFilter(): void
    {
        $repo = new ContentRepository();
        $repo->create(['category_id' => 1, 'title' => 'A', 'age_min' => 4, 'age_max' => 6]);
        $repo->create(['category_id' => 1, 'title' => 'B', 'age_min' => 10, 'age_max' => 13]);
        self::assertCount(1, $repo->search(null, null, 5));
    }

    public function testUpdateAndDelete(): void
    {
        $repo = new ContentRepository();
        $repo->create(['category_id' => 1, 'title' => 'A']);
        self::assertTrue($repo->update(1, ['title' => 'B']));
        self::assertSame('B', $repo->findById(1)['title']);
        self::assertTrue($repo->delete(1));
        self::assertNull($repo->findById(1));
    }
}
```

- [ ] **Step 2: Rodar e falhar** — `RUN --filter ContentRepositorySearchTest` → FAIL (`search`/`update` inexistentes; `update` da base seta `updated_at` que a tabela não tem).

- [ ] **Step 3: Implementar**

Em `database/ContentRepository.php`, adicionar (a classe já tem `all`, `findByCategory`, `count`, `create`):
```php
    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(?int $categoryId, ?string $term, ?int $childAge): array
    {
        $where = [];
        $params = [];
        if ($categoryId !== null && $categoryId > 0) {
            $where[] = 'category_id = %d';
            $params[] = $categoryId;
        }
        if ($term !== null && $term !== '') {
            $like = '%' . $this->db->esc_like($term) . '%';
            $where[] = '(title LIKE %s OR tags LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }
        if ($childAge !== null) {
            $where[] = 'age_min <= %d AND age_max >= %d';
            $params[] = $childAge;
            $params[] = $childAge;
        }
        $sql = 'SELECT * FROM ' . $this->table();
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC';
        if ($params !== []) {
            $sql = $this->db->prepare($sql, ...$params);
        }
        $rows = $this->db->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Override: content_items não tem updated_at (base seta e quebraria).
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        $ok = $this->db->update($this->table(), $data, ['id' => $id]);
        return $ok !== false;
    }
```
(`findById` e `delete` são herdados da `Repository` base — funcionam sem updated_at.)

- [ ] **Step 4: Rodar e passar** — `RUN --filter ContentRepositorySearchTest` → PASS (4 testes).

- [ ] **Step 5: Commit**
```bash
git add database/ContentRepository.php tests/Unit/Database/ContentRepositorySearchTest.php
git commit -m "feat(content): ContentRepository.search + update/delete"
```

---

## Task 3: RecommendationRepository (ordered/update/delete/reorder + sort_order no add)

**Files:** Modify `database/RecommendationRepository.php`; Test `tests/Unit/Database/RecommendationRepositoryTest.php`.

- [ ] **Step 1: Teste que falha**

`tests/Unit/Database/RecommendationRepositoryTest.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\RecommendationRepository;
use PHPUnit\Framework\TestCase;

final class RecommendationRepositoryTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];

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
                if (str_contains((string) $sql, 'MAX(sort_order)') && preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    $vals = array_map(static fn ($r) => (int) ($r['sort_order'] ?? 0), array_filter($this->rows, static fn ($r) => (int) $r['child_id'] === (int) $m[1]));
                    return (string) ($vals === [] ? 0 : max($vals));
                }
                return null;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $rows = array_values($this->rows);
                if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) $r['child_id'] === (int) $m[1]));
                }
                usort($rows, static fn ($a, $b) => ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0)));
                return $rows;
            }

            public function insert($table, $data, $format = null)
            {
                $id = count($this->rows) + 1;
                $this->insert_id = $id;
                $this->rows[$id] = array_merge(['id' => $id], $data);
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                $id = (int) ($where['id'] ?? 0);
                if (isset($this->rows[$id])) {
                    $this->rows[$id] = array_merge($this->rows[$id], $data);
                    return 1;
                }
                return 0;
            }

            public function delete($table, $where, $where_format = null)
            {
                $id = (int) ($where['id'] ?? 0);
                unset($this->rows[$id]);
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testAddAssignsIncrementingSortOrderAndOrders(): void
    {
        $repo = new RecommendationRepository();
        $a = $repo->add(1, 10, 7, null);
        $b = $repo->add(1, 11, 7, null);
        $ordered = $repo->findByChildOrdered(1);
        self::assertSame([$a, $b], array_map(static fn ($r) => (int) $r['id'], $ordered));
    }

    public function testReorderAppliesPositions(): void
    {
        $repo = new RecommendationRepository();
        $a = $repo->add(1, 10, 7, null);
        $b = $repo->add(1, 11, 7, null);
        $repo->reorder([$b, $a]);
        $ordered = $repo->findByChildOrdered(1);
        self::assertSame([$b, $a], array_map(static fn ($r) => (int) $r['id'], $ordered));
    }

    public function testUpdateAndDelete(): void
    {
        $repo = new RecommendationRepository();
        $id = $repo->add(1, 10, 7, 'antiga');
        self::assertTrue($repo->update($id, ['note' => 'nova']));
        self::assertSame('nova', $repo->findById($id)['note']);
        self::assertTrue($repo->delete($id));
        self::assertNull($repo->findById($id));
    }
}
```

- [ ] **Step 2: Rodar e falhar** — `RUN --filter RecommendationRepositoryTest` → FAIL.

- [ ] **Step 3: Implementar**

Em `database/RecommendationRepository.php`, **trocar** o método `add` (S1) por um que grava `sort_order`, e adicionar os novos métodos:
```php
    public function add(int $childId, int $contentId, ?int $guardianId, ?string $note): int
    {
        $ok = $this->db->insert($this->table(), [
            'child_id'    => $childId,
            'content_id'  => $contentId,
            'guardian_id' => $guardianId,
            'note'        => $note,
            'sort_order'  => $this->nextSortOrder($childId),
            'created_at'  => current_time('mysql', true),
        ]);
        return $ok === false ? 0 : (int) $this->db->insert_id;
    }

    public function nextSortOrder(int $childId): int
    {
        $max = $this->db->get_var($this->db->prepare(
            'SELECT MAX(sort_order) FROM ' . $this->table() . ' WHERE child_id = %d',
            $childId,
        ));
        return (int) $max + 1;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByChildOrdered(int $childId): array
    {
        $sql = $this->db->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE child_id = %d ORDER BY sort_order ASC, id ASC',
            $childId,
        );
        $rows = $this->db->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Override: sem updated_at.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        return $this->db->update($this->table(), $data, ['id' => $id]) !== false;
    }

    /** @param array<int> $ids na ordem desejada */
    public function reorder(array $ids): void
    {
        foreach (array_values($ids) as $pos => $id) {
            $this->db->update($this->table(), ['sort_order' => $pos], ['id' => (int) $id]);
        }
    }
```
(`findById`/`delete` herdados; `all`/`count` já existem.)

- [ ] **Step 4: Rodar e passar** — `RUN --filter RecommendationRepositoryTest` → PASS (3 testes).

- [ ] **Step 5: Commit**
```bash
git add database/RecommendationRepository.php tests/Unit/Database/RecommendationRepositoryTest.php
git commit -m "feat(content): RecommendationRepository ordered/update/delete/reorder"
```

---

## Task 4: FavoriteRepository + HistoryRepository + ContentAnalytics

**Files:** Modify `database/FavoriteRepository.php`, `database/HistoryRepository.php`; Create `includes/Content/ContentAnalytics.php`; Test `tests/Unit/Content/ContentAnalyticsTest.php` + adições nos testes de repo (favoritos/history) num `tests/Unit/Database/ContentFavHistTest.php`.

- [ ] **Step 1: Testes que falham**

`tests/Unit/Content/ContentAnalyticsTest.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Content;

use GuardKids\Content\ContentAnalytics;
use PHPUnit\Framework\TestCase;

final class ContentAnalyticsTest extends TestCase
{
    public function testComputesMostAccessedCategoriesAndTime(): void
    {
        $items = [
            ['id' => 10, 'title' => 'Roblox', 'category_id' => 1],
            ['id' => 11, 'title' => 'Khan', 'category_id' => 2],
        ];
        $categories = [
            ['id' => 1, 'name' => 'Jogos'],
            ['id' => 2, 'name' => 'Aprender'],
        ];
        $history = [
            ['content_id' => 10, 'action' => 'open', 'duration_seconds' => 120],
            ['content_id' => 10, 'action' => 'open', 'duration_seconds' => 60],
            ['content_id' => 11, 'action' => 'open', 'duration_seconds' => 300],
        ];

        $out = ContentAnalytics::compute($history, $items, $categories);

        self::assertSame(10, $out['mostAccessed'][0]['contentId']);
        self::assertSame('Roblox', $out['mostAccessed'][0]['title']);
        self::assertSame(2, $out['mostAccessed'][0]['opens']);

        self::assertSame('Jogos', $out['favoriteCategories'][0]['category']);
        self::assertSame(2, $out['favoriteCategories'][0]['opens']);

        // tempo por categoria (minutos): Jogos 180s=3min, Aprender 300s=5min
        $byCat = [];
        foreach ($out['timePerCategory'] as $t) {
            $byCat[$t['category']] = $t['minutes'];
        }
        self::assertSame(3, $byCat['Jogos']);
        self::assertSame(5, $byCat['Aprender']);
    }
}
```

`tests/Unit/Database/ContentFavHistTest.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\FavoriteRepository;
use GuardKids\Database\HistoryRepository;
use PHPUnit\Framework\TestCase;

final class ContentFavHistTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
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

            private function nameOf(string $sql): string
            {
                preg_match('/guardkids_(content_[a-z_]+)/', $sql, $m);
                return $m[1] ?? '';
            }

            public function insert($table, $data, $format = null)
            {
                $n = $this->nameOf((string) $table);
                $this->t[$n] ??= [];
                $id = count($this->t[$n]) + 1;
                $this->insert_id = $id;
                $this->t[$n][$id] = array_merge(['id' => $id], $data);
                return 1;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $rows = array_values($this->t[$this->nameOf((string) $sql)] ?? []);
                if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['child_id'] ?? 0) === (int) $m[1]));
                }
                return $rows;
            }

            public function query($sql)
            {
                if (preg_match('/DELETE FROM \S*guardkids_(content_[a-z_]+).*child_id = (\d+) AND content_id = (\d+)/s', (string) $sql, $m) === 1) {
                    foreach (($this->t[$m[1]] ?? []) as $id => $r) {
                        if ((int) $r['child_id'] === (int) $m[2] && (int) $r['content_id'] === (int) $m[3]) {
                            unset($this->t[$m[1]][$id]);
                        }
                    }
                }
                return 0;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testFavoriteRemoveAndContentIds(): void
    {
        $repo = new FavoriteRepository();
        $repo->add(1, 10);
        $repo->add(1, 11);
        self::assertSame([10, 11], $repo->contentIdsOf(1));
        $repo->remove(1, 10);
        self::assertSame([11], $repo->contentIdsOf(1));
    }

    public function testHistoryRecordWithDurationAndAll(): void
    {
        $repo = new HistoryRepository();
        $repo->record(1, 10, 'open', 120);
        self::assertCount(1, $repo->all());
        self::assertSame(120, (int) $repo->all()[0]['duration_seconds']);
    }
}
```

- [ ] **Step 2: Rodar e falhar** — `RUN --filter "ContentAnalyticsTest|ContentFavHistTest"` → FAIL.

- [ ] **Step 3: Implementar FavoriteRepository (adições)**

Em `database/FavoriteRepository.php`, adicionar:
```php
    public function remove(int $childId, int $contentId): void
    {
        $sql = $this->db->prepare(
            'DELETE FROM ' . $this->table() . ' WHERE child_id = %d AND content_id = %d',
            $childId,
            $contentId,
        );
        $this->db->query($sql);
    }

    /** @return array<int, int> */
    public function contentIdsOf(int $childId): array
    {
        return array_map(
            static fn (array $r): int => (int) $r['content_id'],
            $this->findByChild($childId),
        );
    }
```
> `findByChild` (S1) ordena por `id DESC`. Ajustar o `findByChild` da S1 para `ORDER BY id ASC` (para `contentIdsOf` sair estável [10,11] no teste) — trocar `'id', 'DESC'` por `'id', 'ASC'` na chamada `findWhere` de `findByChild`.

- [ ] **Step 4: Implementar HistoryRepository (adições)**

Em `database/HistoryRepository.php`, adicionar:
```php
    public function record(int $childId, int $contentId, string $action, int $durationSeconds): int
    {
        $ok = $this->db->insert($this->table(), [
            'child_id'         => $childId,
            'content_id'       => $contentId,
            'action'           => $action,
            'duration_seconds' => $durationSeconds,
            'created_at'       => current_time('mysql', true),
        ]);
        return $ok === false ? 0 : (int) $this->db->insert_id;
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->findAll('id', 'DESC');
    }
```

- [ ] **Step 5: Implementar ContentAnalytics (puro)**

`includes/Content/ContentAnalytics.php`:
```php
<?php

declare(strict_types=1);

namespace GuardKids\Content;

/**
 * Calcula analytics da biblioteca em PHP puro (escala de família, sem JOIN).
 * Recebe linhas cruas de history/items/categories e devolve os 3 blocos.
 */
final class ContentAnalytics
{
    /**
     * @param array<int, array<string, mixed>> $history
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array<string, mixed>> $categories
     * @return array{
     *   mostAccessed: array<int, array{contentId:int,title:string,opens:int}>,
     *   favoriteCategories: array<int, array{category:string,opens:int}>,
     *   timePerCategory: array<int, array{category:string,minutes:int}>
     * }
     */
    public static function compute(array $history, array $items, array $categories): array
    {
        $titleOf = [];
        $catOfContent = [];
        foreach ($items as $it) {
            $titleOf[(int) $it['id']] = (string) ($it['title'] ?? '');
            $catOfContent[(int) $it['id']] = isset($it['category_id']) ? (int) $it['category_id'] : 0;
        }
        $catName = [];
        foreach ($categories as $c) {
            $catName[(int) $c['id']] = (string) ($c['name'] ?? '');
        }

        $opensByContent = [];
        $opensByCat = [];
        $secondsByCat = [];
        foreach ($history as $h) {
            $cid = (int) ($h['content_id'] ?? 0);
            $catId = $catOfContent[$cid] ?? 0;
            if (($h['action'] ?? '') === 'open') {
                $opensByContent[$cid] = ($opensByContent[$cid] ?? 0) + 1;
                $opensByCat[$catId] = ($opensByCat[$catId] ?? 0) + 1;
            }
            $secondsByCat[$catId] = ($secondsByCat[$catId] ?? 0) + (int) ($h['duration_seconds'] ?? 0);
        }

        arsort($opensByContent);
        $mostAccessed = [];
        foreach (array_slice($opensByContent, 0, 5, true) as $cid => $opens) {
            $mostAccessed[] = ['contentId' => $cid, 'title' => $titleOf[$cid] ?? '', 'opens' => $opens];
        }

        arsort($opensByCat);
        $favoriteCategories = [];
        foreach (array_slice($opensByCat, 0, 5, true) as $catId => $opens) {
            $favoriteCategories[] = ['category' => $catName[$catId] ?? '—', 'opens' => $opens];
        }

        arsort($secondsByCat);
        $timePerCategory = [];
        foreach ($secondsByCat as $catId => $sec) {
            $timePerCategory[] = ['category' => $catName[$catId] ?? '—', 'minutes' => (int) round($sec / 60)];
        }

        return [
            'mostAccessed'       => $mostAccessed,
            'favoriteCategories' => $favoriteCategories,
            'timePerCategory'    => $timePerCategory,
        ];
    }
}
```

- [ ] **Step 6: Rodar e passar** — `RUN --filter "ContentAnalyticsTest|ContentFavHistTest"` → PASS (3 testes).

- [ ] **Step 7: Commit**
```bash
git add database/FavoriteRepository.php database/HistoryRepository.php includes/Content/ContentAnalytics.php tests/Unit/Content/ContentAnalyticsTest.php tests/Unit/Database/ContentFavHistTest.php
git commit -m "feat(content): favoritos remove/ids, history record+all, ContentAnalytics puro"
```

---

## Task 5: ContentController — CRUD conteúdo + analytics + recomendações CRUD/reorder

**Files:** Modify `api/Controllers/ContentController.php`, `api/RestApi.php`; Test add em `tests/Unit/Api/ContentControllerTest.php`.

- [ ] **Step 1: Testes que falham**

Adicionar em `tests/Unit/Api/ContentControllerTest.php` (o fake wpdb da S1 já cobre insert/get_results/get_var por tabela `content_*`; garantir que ele tenha `update`/`delete`/`query` — se não tiver, adicionar branches análogos aos de insert). Casos:
```php
    public function testCreateContentPersistsFields(): void
    {
        $req = new WP_REST_Request('POST', '/content');
        $req->set_param('title', 'Roblox');
        $req->set_param('categoryId', 1);
        $req->set_param('ageMin', 7);
        $req->set_param('ageMax', 9);
        $req->set_param('url', 'https://roblox.com');
        $req->set_param('tags', 'jogo, online');
        $res = (new ContentController())->createContent($req);
        self::assertSame(201, $res->get_status());
        $row = $this->wpdb->t['content_items'][1];
        self::assertSame('Roblox', $row['title']);
        self::assertSame(7, (int) $row['age_min']);
    }

    public function testCreateContent422WithoutTitle(): void
    {
        $res = (new ContentController())->createContent(new WP_REST_Request('POST', '/content'));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(422, $res->get_error_data()['status']);
    }

    public function testAnalyticsShape(): void
    {
        $res = (new ContentController())->analytics(new WP_REST_Request('GET', '/content/analytics'));
        $data = $res->get_data();
        self::assertArrayHasKey('mostAccessed', $data);
        self::assertArrayHasKey('favoriteCategories', $data);
        self::assertArrayHasKey('timePerCategory', $data);
    }

    public function testReorderRecommendations(): void
    {
        $this->wpdb->t['content_recommendations'] = [
            1 => ['id' => 1, 'child_id' => 1, 'sort_order' => 0],
            2 => ['id' => 2, 'child_id' => 1, 'sort_order' => 1],
        ];
        $req = new WP_REST_Request('POST', '/content/recommendations/reorder');
        $req->set_param('child_id', 1);
        $req->set_param('ids', [2, 1]);
        $res = (new ContentController())->reorderRecommendations($req);
        self::assertTrue($res->get_data()['ok']);
        self::assertSame(0, (int) $this->wpdb->t['content_recommendations'][2]['sort_order']);
    }
```
> Se o fake wpdb do arquivo não tiver `update`/`delete`/`query` que operem em `$this->t[...]`, adicionar (mesmo padrão de `insert`: localizar tabela via regex `guardkids_(content_[a-z_]+)`, aplicar em `$this->t[$name]`). O `get_results` já usado nos analytics devolve `array_values($this->t[$name])`.

- [ ] **Step 2: Rodar e falhar** — `RUN --filter ContentControllerTest` → FAIL.

- [ ] **Step 3: Implementar (adições no ContentController)**

Import no topo: `use GuardKids\Content\ContentAnalytics;`.

Adicionar métodos:
```php
    public function getContent(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $row = $this->contentRepo->findById((int) $req['id']);
        if ($row === null) {
            return new WP_Error('not_found', 'Conteúdo não encontrado.', ['status' => 404]);
        }
        return rest_ensure_response($this->contentToJson($row));
    }

    public function listContents(WP_REST_Request $req): WP_REST_Response
    {
        $category = $req->get_param('category');
        $search   = $req->get_param('search');
        $rows = $this->contentRepo->search(
            is_numeric($category) ? (int) $category : null,
            is_string($search) ? $search : null,
            null,
        );
        return rest_ensure_response(array_map([$this, 'contentToJson'], $rows));
    }

    public function createContent(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $title = trim((string) $req->get_param('title'));
        if ($title === '') {
            return new WP_Error('invalid_payload', 'Título obrigatório.', ['status' => 422]);
        }
        $id = $this->contentRepo->create($this->contentDataFrom($req, $title));
        if ($id === 0) {
            return new WP_Error('db_error', 'Não foi possível salvar.', ['status' => 500]);
        }
        return new WP_REST_Response($this->contentToJson($this->contentRepo->findById($id) ?? []), 201);
    }

    public function updateContent(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id = (int) $req['id'];
        if ($this->contentRepo->findById($id) === null) {
            return new WP_Error('not_found', 'Conteúdo não encontrado.', ['status' => 404]);
        }
        $title = trim((string) $req->get_param('title'));
        if ($title === '') {
            return new WP_Error('invalid_payload', 'Título obrigatório.', ['status' => 422]);
        }
        $this->contentRepo->update($id, $this->contentDataFrom($req, $title));
        return rest_ensure_response($this->contentToJson($this->contentRepo->findById($id) ?? []));
    }

    public function deleteContent(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id = (int) $req['id'];
        if (! $this->contentRepo->delete($id)) {
            return new WP_Error('db_error', 'Falha ao excluir.', ['status' => 500]);
        }
        return rest_ensure_response(['deleted' => true, 'id' => $id]);
    }

    public function analytics(WP_REST_Request $req): WP_REST_Response
    {
        return rest_ensure_response(ContentAnalytics::compute(
            $this->history->all(),
            $this->contentRepo->all(),
            $this->categoriesRepo->all(),
        ));
    }

    public function updateRecommendation(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id = (int) $req['id'];
        if ($this->recommendations->findById($id) === null) {
            return new WP_Error('not_found', 'Recomendação não encontrada.', ['status' => 404]);
        }
        $patch = [];
        $note = $req->get_param('note');
        if (is_string($note)) {
            $patch['note'] = $note;
        }
        $contentId = $req->get_param('content_id');
        if (is_numeric($contentId)) {
            $patch['content_id'] = (int) $contentId;
        }
        if ($patch !== []) {
            $this->recommendations->update($id, $patch);
        }
        return rest_ensure_response(['ok' => true]);
    }

    public function deleteRecommendation(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id = (int) $req['id'];
        if (! $this->recommendations->delete($id)) {
            return new WP_Error('db_error', 'Falha ao excluir.', ['status' => 500]);
        }
        return rest_ensure_response(['deleted' => true, 'id' => $id]);
    }

    public function reorderRecommendations(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $ids = $req->get_param('ids');
        if (! is_array($ids)) {
            return new WP_Error('invalid_payload', 'ids obrigatório.', ['status' => 422]);
        }
        $this->recommendations->reorder(array_map('intval', $ids));
        return rest_ensure_response(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function contentToJson(array $row): array
    {
        return [
            'id'               => (int) ($row['id'] ?? 0),
            'categoryId'       => isset($row['category_id']) ? (int) $row['category_id'] : null,
            'title'            => (string) ($row['title'] ?? ''),
            'description'      => $row['description'] ?? null,
            'url'              => $row['url'] ?? null,
            'thumbnail'        => $row['thumbnail'] ?? null,
            'type'             => (string) ($row['type'] ?? 'link'),
            'ageMin'           => (int) ($row['age_min'] ?? 0),
            'ageMax'           => (int) ($row['age_max'] ?? 99),
            'estimatedMinutes' => isset($row['estimated_minutes']) ? (int) $row['estimated_minutes'] : null,
            'level'            => $row['level'] ?? null,
            'tags'             => $row['tags'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contentDataFrom(WP_REST_Request $req, string $title): array
    {
        $strOrNull = static function ($v): ?string {
            return is_string($v) && $v !== '' ? $v : null;
        };
        return [
            'title'             => $title,
            'description'       => $strOrNull($req->get_param('description')),
            'category_id'       => is_numeric($req->get_param('categoryId')) ? (int) $req->get_param('categoryId') : null,
            'url'               => $strOrNull($req->get_param('url')),
            'thumbnail'         => $strOrNull($req->get_param('thumbnail')),
            'type'              => is_string($req->get_param('type')) ? (string) $req->get_param('type') : 'link',
            'age_min'           => is_numeric($req->get_param('ageMin')) ? (int) $req->get_param('ageMin') : 0,
            'age_max'           => is_numeric($req->get_param('ageMax')) ? (int) $req->get_param('ageMax') : 99,
            'estimated_minutes' => is_numeric($req->get_param('estimatedMinutes')) ? (int) $req->get_param('estimatedMinutes') : null,
            'level'             => $strOrNull($req->get_param('level')),
            'tags'              => $strOrNull($req->get_param('tags')),
        ];
    }
```

- [ ] **Step 4: Registrar rotas** — em `api/RestApi.php`, dentro de `registerContentRoutes` (após as rotas da S1), adicionar:
```php
        register_rest_route(self::NAMESPACE, '/content/analytics', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'analytics'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);
        register_rest_route(self::NAMESPACE, '/content/(?P<id>\d+)', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$controller, 'getContent'],
                'permission_callback' => [self::class, 'requireAdmin'],
            ],
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [$controller, 'updateContent'],
                'permission_callback' => [self::class, 'requireAdmin'],
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [$controller, 'deleteContent'],
                'permission_callback' => [self::class, 'requireAdmin'],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/content/recommendations/reorder', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'reorderRecommendations'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);
        register_rest_route(self::NAMESPACE, '/content/recommendations/(?P<id>\d+)', [
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [$controller, 'updateRecommendation'],
                'permission_callback' => [self::class, 'requireAdmin'],
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [$controller, 'deleteRecommendation'],
                'permission_callback' => [self::class, 'requireAdmin'],
            ],
        ]);
```
> A rota antiga `$adminGet('/content', 'contents')` (S1) passa a chamar o novo `listContents` (com filtros). Trocar o callback de `'contents'` para `'listContents'` na linha existente. O método `contents` da S1 pode ser removido (substituído por `listContents`) — órfão criado pela mudança.

- [ ] **Step 5: Rodar e passar** — `RUN --filter ContentControllerTest` → PASS. Depois `RUN` (suíte inteira) → PASS.

- [ ] **Step 6: Commit**
```bash
git add api/Controllers/ContentController.php api/RestApi.php tests/Unit/Api/ContentControllerTest.php
git commit -m "feat(api): CRUD de conteúdo + analytics + recomendações CRUD/reorder"
```

---

## Task 6: ContentController — endpoints do filho `/child/library/*`

**Files:** Modify `api/Controllers/ContentController.php`, `api/RestApi.php`; Test add em `tests/Unit/Api/ContentControllerTest.php`.

- [ ] **Step 1: Testes que falham**

No `ContentControllerTest`, o `setUp` já cria um token pra child 1 (`$this->token`). Garantir que o fake wpdb resolva a idade do filho: `ChildAuth::resolveChildId` lê o token de settings; o childId é 1. Pra o age-filter, o controller precisa da idade do filho → `ChildRepository::findById(1)['age']`. Adicionar ao fake um `get_row` que devolva a linha de `guardkids_children` (com `age`) — seedar `$this->wpdb` com um child 1 idade 8 (ver o padrão do `ChildSelfControllerTest`). Casos:
```php
    public function testChildLibraryFiltersByAge(): void
    {
        // child 1, idade 8 (seedar via fake get_row de guardkids_children)
        $this->seedChild(1, 8);
        $this->wpdb->t['content_items'] = [
            1 => ['id' => 1, 'title' => 'A', 'category_id' => 1, 'age_min' => 4, 'age_max' => 6],
            2 => ['id' => 2, 'title' => 'B', 'category_id' => 1, 'age_min' => 7, 'age_max' => 9],
        ];
        $res = (new ContentController())->childLibrary($this->tokenReq('GET', '/child/library'));
        $data = $res->get_data();
        self::assertCount(1, $data);
        self::assertSame('B', $data[0]['title']);
    }

    public function testChildFavoriteAddThenRemove(): void
    {
        $this->seedChild(1, 8);
        $add = (new ContentController())->childAddFavorite($this->tokenReqBody('POST', '/child/library/favorites', ['content_id' => 10]));
        self::assertSame(201, $add->get_status());
        $del = (new ContentController())->childRemoveFavorite($this->tokenReqParam('DELETE', '/child/library/favorites/10', 'contentId', 10));
        self::assertTrue($del->get_data()['ok']);
    }

    public function testChildHistoryRecords(): void
    {
        $this->seedChild(1, 8);
        $req = $this->tokenReq('POST', '/child/library/history');
        $req->set_param('content_id', 10);
        $req->set_param('action', 'open');
        $req->set_param('duration_seconds', 0);
        $res = (new ContentController())->childHistory($req);
        self::assertSame(201, $res->get_status());
        self::assertNotEmpty($this->wpdb->t['content_history']);
    }

    public function testChildLibrary401WithoutToken(): void
    {
        $res = (new ContentController())->childLibrary(new WP_REST_Request('GET', '/child/library'));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(401, $res->get_error_data()['status']);
    }
```
> Helpers a adicionar no teste: `seedChild(int $id, int $age)` (popula uma linha de `guardkids_children` que o `get_row` do fake devolve por `WHERE id = N`); `tokenReqBody`/`tokenReqParam` (variações do `tokenReq` já existente que setam param/`$req['contentId']`). Espelhar o `get_row` de children do `ChildSelfControllerTest`.

- [ ] **Step 2: Rodar e falhar** — `RUN --filter ContentControllerTest` → FAIL.

- [ ] **Step 3: Implementar (adições no ContentController)**

Precisa da idade do filho → injetar `ChildRepository`. Adicionar no construtor: `use GuardKids\Database\ChildRepository;` + propriedade `private readonly ChildRepository $children;` + `$this->children = new ChildRepository();`.

Métodos:
```php
    private function childAge(int $childId): ?int
    {
        $row = $this->children->findById($childId);
        return $row !== null && isset($row['age']) ? (int) $row['age'] : null;
    }

    public function childLibrary(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        $category = $req->get_param('category');
        $search   = $req->get_param('search');
        $rows = $this->contentRepo->search(
            is_numeric($category) ? (int) $category : null,
            is_string($search) ? $search : null,
            $this->childAge($childId),
        );
        $favIds = $this->favorites->contentIdsOf($childId);
        return rest_ensure_response(array_map(function (array $r) use ($favIds): array {
            return $this->contentToJson($r) + ['favorited' => in_array((int) $r['id'], $favIds, true)];
        }, $rows));
    }

    public function childLibraryCategories(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        $items = $this->contentRepo->search(null, null, $this->childAge($childId));
        $countByCat = [];
        foreach ($items as $it) {
            $c = isset($it['category_id']) ? (int) $it['category_id'] : 0;
            $countByCat[$c] = ($countByCat[$c] ?? 0) + 1;
        }
        return rest_ensure_response(array_map(static fn (array $c): array => [
            'id'    => (int) $c['id'],
            'slug'  => (string) ($c['slug'] ?? ''),
            'name'  => (string) ($c['name'] ?? ''),
            'icon'  => $c['icon'] ?? null,
            'count' => $countByCat[(int) $c['id']] ?? 0,
        ], $this->categoriesRepo->all()));
    }

    public function childRecommendations(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        $out = [];
        foreach ($this->recommendations->findByChildOrdered($childId) as $rec) {
            $content = $this->contentRepo->findById((int) ($rec['content_id'] ?? 0));
            if ($content !== null) {
                $out[] = ['id' => (int) $rec['id'], 'note' => $rec['note'] ?? null, 'content' => $this->contentToJson($content)];
            }
        }
        return rest_ensure_response($out);
    }

    public function childFavorites(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        $out = [];
        foreach ($this->favorites->contentIdsOf($childId) as $cid) {
            $content = $this->contentRepo->findById($cid);
            if ($content !== null) {
                $out[] = $this->contentToJson($content) + ['favorited' => true];
            }
        }
        return rest_ensure_response($out);
    }

    public function childAddFavorite(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        $contentId = (int) $req->get_param('content_id');
        if ($contentId === 0) {
            return new WP_Error('invalid_payload', 'content_id obrigatório.', ['status' => 422]);
        }
        $this->favorites->add($childId, $contentId);
        return new WP_REST_Response(['ok' => true], 201);
    }

    public function childRemoveFavorite(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        $this->favorites->remove($childId, (int) $req['contentId']);
        return rest_ensure_response(['ok' => true]);
    }

    public function childHistory(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $childId = $this->auth->resolveChildId($req);
        if ($childId === null) {
            return new WP_Error('child_auth_required', 'Token inválido.', ['status' => 401]);
        }
        $contentId = (int) $req->get_param('content_id');
        if ($contentId === 0) {
            return new WP_Error('invalid_payload', 'content_id obrigatório.', ['status' => 422]);
        }
        $action = (string) $req->get_param('action');
        $action = in_array($action, ['open', 'close'], true) ? $action : 'open';
        $duration = (int) $req->get_param('duration_seconds');
        $this->history->record($childId, $contentId, $action, max(0, $duration));
        return new WP_REST_Response(['ok' => true], 201);
    }
```

- [ ] **Step 4: Registrar rotas** — em `registerContentRoutes`, adicionar (auth token):
```php
        $token = (new ChildAuth())->requireToken();
        register_rest_route(self::NAMESPACE, '/child/library', [
            'methods' => \WP_REST_Server::READABLE, 'callback' => [$controller, 'childLibrary'], 'permission_callback' => $token,
        ]);
        register_rest_route(self::NAMESPACE, '/child/library/categories', [
            'methods' => \WP_REST_Server::READABLE, 'callback' => [$controller, 'childLibraryCategories'], 'permission_callback' => $token,
        ]);
        register_rest_route(self::NAMESPACE, '/child/library/recommendations', [
            'methods' => \WP_REST_Server::READABLE, 'callback' => [$controller, 'childRecommendations'], 'permission_callback' => $token,
        ]);
        register_rest_route(self::NAMESPACE, '/child/library/favorites', [
            [ 'methods' => \WP_REST_Server::READABLE, 'callback' => [$controller, 'childFavorites'], 'permission_callback' => $token ],
            [ 'methods' => \WP_REST_Server::CREATABLE, 'callback' => [$controller, 'childAddFavorite'], 'permission_callback' => $token ],
        ]);
        register_rest_route(self::NAMESPACE, '/child/library/favorites/(?P<contentId>\d+)', [
            'methods' => \WP_REST_Server::DELETABLE, 'callback' => [$controller, 'childRemoveFavorite'], 'permission_callback' => $token,
        ]);
        register_rest_route(self::NAMESPACE, '/child/library/history', [
            'methods' => \WP_REST_Server::CREATABLE, 'callback' => [$controller, 'childHistory'], 'permission_callback' => $token,
        ]);
```

- [ ] **Step 5: Rodar e passar** — `RUN --filter ContentControllerTest` → PASS. `RUN` (inteira) → PASS.

- [ ] **Step 6: Commit**
```bash
git add api/Controllers/ContentController.php api/RestApi.php tests/Unit/Api/ContentControllerTest.php
git commit -m "feat(api): endpoints /child/library/* (browse age-filtered, favoritos, history)"
```

---

## Task 7: app-parent — gestão de conteúdo (api + dashboard + form + recomendações)

**Files:** Modify `public/app-parent/src/api/content.ts`, `src/pages/ContentDashboard.tsx`; Create `src/components/ContentForm.tsx`, `src/components/RecommendationManager.tsx`, `src/pages/ContentDashboard.test.tsx` (atualizar), `src/components/ContentForm.test.tsx`.

- [ ] **Step 1: api + tipos**

Em `public/app-parent/src/api/content.ts`, adicionar (mantém `getContentSummary`/`ContentSummary`):
```ts
export type Content = {
  id: number;
  categoryId: number | null;
  title: string;
  description: string | null;
  url: string | null;
  thumbnail: string | null;
  type: string;
  ageMin: number;
  ageMax: number;
  estimatedMinutes: number | null;
  level: string | null;
  tags: string | null;
};

export type ContentCategory = { id: number; slug: string; name: string; icon: string | null; description: string | null };

export type ContentAnalytics = {
  mostAccessed: { contentId: number; title: string; opens: number }[];
  favoriteCategories: { category: string; opens: number }[];
  timePerCategory: { category: string; minutes: number }[];
};

export type Recommendation = { id: number; childId: number; contentId: number; note: string | null; createdAt: string | null };

export type ContentInput = {
  title: string;
  description?: string;
  categoryId?: number;
  ageMin: number;
  ageMax: number;
  url?: string;
  thumbnail?: string;
  estimatedMinutes?: number;
  level?: string;
  tags?: string;
};

export function listContents(category = 0, search = ''): Promise<Content[]> {
  const params = new URLSearchParams();
  if (category > 0) params.set('category', String(category));
  if (search) params.set('search', search);
  const qs = params.toString();
  return apiFetch<Content[]>(`/content${qs ? `?${qs}` : ''}`);
}

export function listContentCategories(): Promise<ContentCategory[]> {
  return apiFetch<ContentCategory[]>('/content/categories');
}

export function createContent(input: ContentInput): Promise<Content> {
  return apiFetch<Content>('/content', { method: 'POST', body: JSON.stringify(input) });
}

export function updateContent(id: number, input: ContentInput): Promise<Content> {
  return apiFetch<Content>(`/content/${id}`, { method: 'PUT', body: JSON.stringify(input) });
}

export function deleteContent(id: number): Promise<{ deleted: boolean }> {
  return apiFetch<{ deleted: boolean }>(`/content/${id}`, { method: 'DELETE' });
}

export function getAnalytics(): Promise<ContentAnalytics> {
  return apiFetch<ContentAnalytics>('/content/analytics');
}

export function listRecommendations(childId: number): Promise<Recommendation[]> {
  return apiFetch<Recommendation[]>(`/content/recommendations?child_id=${childId}`);
}

export function createRecommendation(childId: number, contentId: number, note = ''): Promise<{ id: number }> {
  return apiFetch<{ id: number }>('/content/recommendations', {
    method: 'POST',
    body: JSON.stringify({ child_id: childId, content_id: contentId, note }),
  });
}

export function deleteRecommendation(id: number): Promise<{ deleted: boolean }> {
  return apiFetch<{ deleted: boolean }>(`/content/recommendations/${id}`, { method: 'DELETE' });
}

export function reorderRecommendations(childId: number, ids: number[]): Promise<{ ok: boolean }> {
  return apiFetch<{ ok: boolean }>('/content/recommendations/reorder', {
    method: 'POST',
    body: JSON.stringify({ child_id: childId, ids }),
  });
}
```

- [ ] **Step 2: ContentForm + teste**

`public/app-parent/src/components/ContentForm.test.tsx`:
```tsx
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ContentForm } from './ContentForm';

describe('ContentForm', () => {
  it('envia o conteúdo com faixa etária mapeada', async () => {
    const onSubmit = vi.fn().mockResolvedValue(undefined);
    render(<ContentForm categories={[{ id: 1, slug: 'games', name: 'Jogos', icon: null, description: null }]} onSubmit={onSubmit} onClose={() => {}} />);
    fireEvent.change(screen.getByLabelText('Título'), { target: { value: 'Roblox' } });
    fireEvent.change(screen.getByLabelText('Faixa etária'), { target: { value: '7-9' } });
    fireEvent.click(screen.getByRole('button', { name: /salvar/i }));
    await waitFor(() =>
      expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ title: 'Roblox', ageMin: 7, ageMax: 9 })),
    );
  });
});
```

`public/app-parent/src/components/ContentForm.tsx`:
```tsx
import { useState, type FormEvent } from 'react';
import type { Content, ContentCategory, ContentInput } from '../api/content';

const AGE_BUCKETS: Record<string, [number, number]> = {
  '4-6': [4, 6], '7-9': [7, 9], '10-13': [10, 13], '14-16': [14, 16],
};
const LEVELS = ['iniciante', 'intermediário', 'avançado'];

type ContentFormProps = {
  categories: ContentCategory[];
  initial?: Content;
  onSubmit: (input: ContentInput) => Promise<void>;
  onClose: () => void;
};

function bucketOf(min: number, max: number): string {
  const found = Object.entries(AGE_BUCKETS).find(([, [a, b]]) => a === min && b === max);
  return found ? found[0] : '4-6';
}

export function ContentForm({ categories, initial, onSubmit, onClose }: ContentFormProps) {
  const [title, setTitle] = useState(initial?.title ?? '');
  const [description, setDescription] = useState(initial?.description ?? '');
  const [categoryId, setCategoryId] = useState(initial?.categoryId ?? categories[0]?.id ?? 0);
  const [bucket, setBucket] = useState(initial ? bucketOf(initial.ageMin, initial.ageMax) : '4-6');
  const [url, setUrl] = useState(initial?.url ?? '');
  const [thumbnail, setThumbnail] = useState(initial?.thumbnail ?? '');
  const [minutes, setMinutes] = useState(initial?.estimatedMinutes?.toString() ?? '');
  const [level, setLevel] = useState(initial?.level ?? LEVELS[0]);
  const [tags, setTags] = useState(initial?.tags ?? '');
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    if (!title.trim()) return;
    setBusy(true);
    const [ageMin, ageMax] = AGE_BUCKETS[bucket];
    await onSubmit({
      title: title.trim(),
      description: description || undefined,
      categoryId: categoryId || undefined,
      ageMin,
      ageMax,
      url: url || undefined,
      thumbnail: thumbnail || undefined,
      estimatedMinutes: minutes ? Number(minutes) : undefined,
      level,
      tags: tags || undefined,
    });
    setBusy(false);
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
      <form onClick={(e) => e.stopPropagation()} onSubmit={submit} className="w-full max-w-lg space-y-3 rounded-2xl bg-surface p-6 shadow-lg">
        <h2 className="font-display text-headline-md text-on-surface">{initial ? 'Editar conteúdo' : 'Novo conteúdo'}</h2>
        <label className="block text-label-md">Título
          <input aria-label="Título" value={title} onChange={(e) => setTitle(e.target.value)} required className="mt-1 w-full rounded-lg border border-outline-variant p-2" />
        </label>
        <label className="block text-label-md">Descrição
          <textarea value={description} onChange={(e) => setDescription(e.target.value)} rows={2} className="mt-1 w-full rounded-lg border border-outline-variant p-2" />
        </label>
        <div className="grid grid-cols-2 gap-3">
          <label className="block text-label-md">Categoria
            <select value={categoryId} onChange={(e) => setCategoryId(Number(e.target.value))} className="mt-1 w-full rounded-lg border border-outline-variant p-2">
              {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </label>
          <label className="block text-label-md">Faixa etária
            <select aria-label="Faixa etária" value={bucket} onChange={(e) => setBucket(e.target.value)} className="mt-1 w-full rounded-lg border border-outline-variant p-2">
              {Object.keys(AGE_BUCKETS).map((b) => <option key={b} value={b}>{b}</option>)}
            </select>
          </label>
        </div>
        <label className="block text-label-md">Link
          <input value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://..." className="mt-1 w-full rounded-lg border border-outline-variant p-2" />
        </label>
        <label className="block text-label-md">Miniatura (URL)
          <input value={thumbnail} onChange={(e) => setThumbnail(e.target.value)} placeholder="https://..." className="mt-1 w-full rounded-lg border border-outline-variant p-2" />
        </label>
        <div className="grid grid-cols-3 gap-3">
          <label className="block text-label-md">Tempo (min)
            <input type="number" value={minutes} onChange={(e) => setMinutes(e.target.value)} className="mt-1 w-full rounded-lg border border-outline-variant p-2" />
          </label>
          <label className="block text-label-md">Nível
            <select value={level} onChange={(e) => setLevel(e.target.value)} className="mt-1 w-full rounded-lg border border-outline-variant p-2">
              {LEVELS.map((l) => <option key={l} value={l}>{l}</option>)}
            </select>
          </label>
          <label className="block text-label-md">Tags
            <input value={tags} onChange={(e) => setTags(e.target.value)} placeholder="jogo, online" className="mt-1 w-full rounded-lg border border-outline-variant p-2" />
          </label>
        </div>
        <div className="flex justify-end gap-2 pt-2">
          <button type="button" onClick={onClose} className="rounded-lg px-4 py-2 text-on-surface-variant">Cancelar</button>
          <button type="submit" disabled={busy} className="rounded-lg bg-primary px-4 py-2 font-semibold text-white disabled:opacity-60">{busy ? 'Salvando…' : 'Salvar'}</button>
        </div>
      </form>
    </div>
  );
}
```

- [ ] **Step 3: RecommendationManager**

`public/app-parent/src/components/RecommendationManager.tsx`:
```tsx
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  createRecommendation, deleteRecommendation, listRecommendations, reorderRecommendations,
  type Recommendation,
} from '../api/content';

type RecommendationManagerProps = { childId: number; contentOptions: { id: number; title: string }[] };

export function RecommendationManager({ childId, contentOptions }: RecommendationManagerProps) {
  const qc = useQueryClient();
  const query = useQuery({ queryKey: ['content', 'recs', childId], queryFn: () => listRecommendations(childId) });
  const invalidate = () => qc.invalidateQueries({ queryKey: ['content', 'recs', childId] });
  const add = useMutation({ mutationFn: (contentId: number) => createRecommendation(childId, contentId), onSuccess: invalidate });
  const remove = useMutation({ mutationFn: (id: number) => deleteRecommendation(id), onSuccess: invalidate });
  const reorder = useMutation({ mutationFn: (ids: number[]) => reorderRecommendations(childId, ids), onSuccess: invalidate });

  const recs = query.data ?? [];
  function move(index: number, dir: -1 | 1) {
    const next = [...recs];
    const j = index + dir;
    if (j < 0 || j >= next.length) return;
    [next[index], next[j]] = [next[j], next[index]];
    reorder.mutate(next.map((r) => r.id));
  }

  return (
    <div className="space-y-2">
      <div className="flex items-center gap-2">
        <select id="rec-add" className="rounded-lg border border-outline-variant p-2" defaultValue="">
          <option value="" disabled>Escolher conteúdo…</option>
          {contentOptions.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}
        </select>
        <button
          type="button"
          onClick={() => {
            const el = document.getElementById('rec-add') as HTMLSelectElement | null;
            if (el && el.value) add.mutate(Number(el.value));
          }}
          className="rounded-lg bg-primary px-3 py-2 text-label-md font-semibold text-white"
        >
          Adicionar
        </button>
      </div>
      {recs.length === 0 && <p className="text-label-sm text-on-surface-variant">Nenhuma recomendação para este filho.</p>}
      <ul className="space-y-1">
        {recs.map((r: Recommendation, i) => (
          <li key={r.id} className="flex items-center justify-between rounded-lg border border-outline-variant p-2">
            <span className="text-label-md text-on-surface">Conteúdo #{r.contentId}{r.note ? ` — ${r.note}` : ''}</span>
            <span className="flex gap-1">
              <button type="button" aria-label="Subir" onClick={() => move(i, -1)} className="px-2">↑</button>
              <button type="button" aria-label="Descer" onClick={() => move(i, 1)} className="px-2">↓</button>
              <button type="button" aria-label="Remover" onClick={() => remove.mutate(r.id)} className="px-2 text-error">✕</button>
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}
```

- [ ] **Step 4: ContentDashboard reescrito + teste**

`public/app-parent/src/pages/ContentDashboard.test.tsx` (substituir):
```tsx
import { screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { ContentDashboard } from './ContentDashboard';

const getAnalytics = vi.fn();
const listContents = vi.fn();
const listContentCategories = vi.fn();
vi.mock('../api/content', () => ({
  getAnalytics: () => getAnalytics(),
  listContents: () => listContents(),
  listContentCategories: () => listContentCategories(),
  createContent: vi.fn(),
  updateContent: vi.fn(),
  deleteContent: vi.fn(),
  listRecommendations: () => Promise.resolve([]),
  createRecommendation: vi.fn(),
  deleteRecommendation: vi.fn(),
  reorderRecommendations: vi.fn(),
}));

describe('ContentDashboard', () => {
  afterEach(() => {
    getAnalytics.mockReset();
    listContents.mockReset();
    listContentCategories.mockReset();
  });

  it('mostra estado vazio quando não há conteúdo e botão ativo', async () => {
    getAnalytics.mockResolvedValueOnce({ mostAccessed: [], favoriteCategories: [], timePerCategory: [] });
    listContents.mockResolvedValueOnce([]);
    listContentCategories.mockResolvedValueOnce([]);
    renderWithClient(<ContentDashboard />);
    expect(await screen.findByText('Nenhum conteúdo cadastrado')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /adicionar conteúdo/i })).toBeEnabled();
  });
});
```

`public/app-parent/src/pages/ContentDashboard.tsx` (substituir):
```tsx
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  createContent, deleteContent, getAnalytics, listContentCategories, listContents, updateContent,
  type Content, type ContentInput,
} from '../api/content';
import { ContentForm } from '../components/ContentForm';

function AnalyticsCard({ title, rows }: { title: string; rows: { label: string; value: string | number }[] }) {
  return (
    <div className="rounded-2xl border border-outline-variant bg-surface p-4 shadow-sm">
      <h3 className="mb-2 text-label-md font-bold text-on-surface">{title}</h3>
      {rows.length === 0 ? (
        <p className="text-label-sm text-on-surface-variant">Sem dados ainda.</p>
      ) : (
        <ul className="space-y-1">
          {rows.map((r) => (
            <li key={r.label} className="flex justify-between text-label-sm">
              <span className="text-on-surface-variant">{r.label}</span>
              <span className="font-semibold text-on-surface">{r.value}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export function ContentDashboard() {
  const qc = useQueryClient();
  const analytics = useQuery({ queryKey: ['content', 'analytics'], queryFn: getAnalytics });
  const categories = useQuery({ queryKey: ['content', 'categories'], queryFn: listContentCategories });
  const contents = useQuery({ queryKey: ['content', 'list'], queryFn: () => listContents() });
  const [editing, setEditing] = useState<Content | null>(null);
  const [creating, setCreating] = useState(false);

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['content', 'list'] });
    qc.invalidateQueries({ queryKey: ['content', 'analytics'] });
  };
  const save = useMutation({
    mutationFn: (input: ContentInput) =>
      editing ? updateContent(editing.id, input) : createContent(input),
    onSuccess: () => { invalidate(); setEditing(null); setCreating(false); },
  });
  const remove = useMutation({ mutationFn: (id: number) => deleteContent(id), onSuccess: invalidate });

  const a = analytics.data;
  const list = contents.data ?? [];

  return (
    <main className="flex-1 space-y-6 p-6">
      <div className="flex items-center justify-between">
        <h1 className="font-display text-headline-lg text-on-background">Conteúdo Infantil</h1>
        <button type="button" onClick={() => { setEditing(null); setCreating(true); }} className="rounded-xl bg-primary px-5 py-2.5 text-label-md font-semibold text-white">
          Adicionar Conteúdo
        </button>
      </div>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
        <AnalyticsCard title="Mais acessados" rows={(a?.mostAccessed ?? []).map((m) => ({ label: m.title, value: `${m.opens}×` }))} />
        <AnalyticsCard title="Categorias favoritas" rows={(a?.favoriteCategories ?? []).map((c) => ({ label: c.category, value: `${c.opens}×` }))} />
        <AnalyticsCard title="Tempo por categoria" rows={(a?.timePerCategory ?? []).map((t) => ({ label: t.category, value: `${t.minutes} min` }))} />
      </div>

      {contents.isLoading ? (
        <div className="h-24 animate-pulse rounded-2xl bg-surface-container-low" />
      ) : list.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-2xl border-2 border-dashed border-outline-variant p-10 text-center">
          <span className="material-symbols-outlined text-5xl text-outline">inventory_2</span>
          <p className="text-label-lg font-semibold text-on-surface">Nenhum conteúdo cadastrado</p>
        </div>
      ) : (
        <div className="divide-y divide-outline-variant rounded-2xl border border-outline-variant bg-surface">
          {list.map((c) => (
            <div key={c.id} className="flex items-center justify-between p-3">
              <div>
                <div className="text-label-md font-semibold text-on-surface">{c.title}</div>
                <div className="text-label-sm text-on-surface-variant">{c.ageMin}–{c.ageMax} anos{c.tags ? ` · ${c.tags}` : ''}</div>
              </div>
              <div className="flex gap-2">
                <button type="button" onClick={() => { setCreating(false); setEditing(c); }} className="text-primary">Editar</button>
                <button type="button" onClick={() => remove.mutate(c.id)} className="text-error">Excluir</button>
              </div>
            </div>
          ))}
        </div>
      )}

      {(creating || editing) && (
        <ContentForm
          categories={categories.data ?? []}
          initial={editing ?? undefined}
          onSubmit={(input) => save.mutateAsync(input).then(() => undefined)}
          onClose={() => { setCreating(false); setEditing(null); }}
        />
      )}
    </main>
  );
}
```

- [ ] **Step 5: Rodar e passar + tsc + suíte**
```bash
cd public/app-parent && pnpm test ContentForm ContentDashboard && pnpm exec tsc -b && pnpm test
```
Expected: PASS; TS limpo; suíte inteira verde.

- [ ] **Step 6: Commit**
```bash
git add public/app-parent/src/api/content.ts public/app-parent/src/components/ContentForm.tsx public/app-parent/src/components/ContentForm.test.tsx public/app-parent/src/components/RecommendationManager.tsx public/app-parent/src/pages/ContentDashboard.tsx public/app-parent/src/pages/ContentDashboard.test.tsx
git commit -m "feat(app-parent): gestão de conteúdo (analytics, CRUD, recomendações)"
```

---

## Task 8: app-child — Biblioteca real (api + Skeleton + Mundo reescrito)

**Files:** Modify `public/app-child/src/api/content.ts`, `src/api/types.ts`, `src/pages/Mundo.tsx`; Create `src/components/Skeleton.tsx`; Test `src/pages/Mundo.test.tsx` (substituir).

- [ ] **Step 1: tipos + api**

Em `src/api/types.ts`, garantir que `Content` tenha os campos novos (adicionar ao tipo `Content` existente): `ageMin: number; ageMax: number; estimatedMinutes: number | null; level: string | null; tags: string | null; favorited?: boolean;`.

`public/app-child/src/api/content.ts` (substituir — `addFavorite` passa a apontar pro novo endpoint):
```ts
import { apiFetch } from './client';
import type { Content } from './types';

export type LibraryCategory = { id: number; slug: string; name: string; icon: string | null; count: number };
export type ChildRecommendation = { id: number; note: string | null; content: Content };

export function browseLibrary(category = 0, search = ''): Promise<Content[]> {
  const params = new URLSearchParams();
  if (category > 0) params.set('category', String(category));
  if (search) params.set('search', search);
  const qs = params.toString();
  return apiFetch<Content[]>(`/child/library${qs ? `?${qs}` : ''}`);
}

export function listLibraryCategories(): Promise<LibraryCategory[]> {
  return apiFetch<LibraryCategory[]>('/child/library/categories');
}

export function listChildRecommendations(): Promise<ChildRecommendation[]> {
  return apiFetch<ChildRecommendation[]>('/child/library/recommendations');
}

export function listChildFavorites(): Promise<Content[]> {
  return apiFetch<Content[]>('/child/library/favorites');
}

export function addFavorite(contentId: number): Promise<{ ok: boolean }> {
  return apiFetch<{ ok: boolean }>('/child/library/favorites', {
    method: 'POST',
    body: JSON.stringify({ content_id: contentId }),
  });
}

export function removeFavorite(contentId: number): Promise<{ ok: boolean }> {
  return apiFetch<{ ok: boolean }>(`/child/library/favorites/${contentId}`, { method: 'DELETE' });
}

export function recordHistory(contentId: number, action: 'open' | 'close', durationSeconds = 0): Promise<{ ok: boolean }> {
  return apiFetch<{ ok: boolean }>('/child/library/history', {
    method: 'POST',
    body: JSON.stringify({ content_id: contentId, action, duration_seconds: durationSeconds }),
  });
}
```

- [ ] **Step 2: Skeleton**

`public/app-child/src/components/Skeleton.tsx`:
```tsx
export function Skeleton({ count = 4 }: { count?: number }) {
  return (
    <div className="grid grid-cols-2 gap-3">
      {Array.from({ length: count }).map((_, i) => (
        <div key={i} className="glass-panel h-28 animate-pulse rounded-2xl bg-surface-container-low" />
      ))}
    </div>
  );
}
```

- [ ] **Step 3: Teste que falha (Mundo)**

`public/app-child/src/pages/Mundo.test.tsx` (substituir):
```tsx
import { fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { Mundo } from './Mundo';

const browseLibrary = vi.fn();
const listLibraryCategories = vi.fn();
const listChildRecommendations = vi.fn();
const addFavorite = vi.fn();
const recordHistory = vi.fn();
vi.mock('../api/content', () => ({
  browseLibrary: () => browseLibrary(),
  listLibraryCategories: () => listLibraryCategories(),
  listChildRecommendations: () => listChildRecommendations(),
  listChildFavorites: () => Promise.resolve([]),
  addFavorite: (id: number) => addFavorite(id),
  removeFavorite: vi.fn(),
  recordHistory: (...a: unknown[]) => recordHistory(...a),
}));

const sample = [
  { id: 10, categoryId: 1, title: 'Roblox', description: null, url: 'https://roblox.com', thumbnail: null, type: 'link', ageMin: 7, ageMax: 9, estimatedMinutes: null, level: null, tags: null, favorited: false },
];

describe('Mundo', () => {
  afterEach(() => {
    browseLibrary.mockReset();
    listLibraryCategories.mockReset();
    listChildRecommendations.mockReset();
    addFavorite.mockReset();
    recordHistory.mockReset();
  });

  it('lista conteúdo da biblioteca e abre + registra histórico ao tocar', async () => {
    browseLibrary.mockResolvedValue(sample);
    listLibraryCategories.mockResolvedValue([]);
    listChildRecommendations.mockResolvedValue([]);
    const open = vi.spyOn(window, 'open').mockReturnValue(null);
    renderWithClient(<Mundo />);
    fireEvent.click(await screen.findByText('Roblox'));
    expect(open).toHaveBeenCalledWith('https://roblox.com', '_blank', 'noopener,noreferrer');
    await waitFor(() => expect(recordHistory).toHaveBeenCalledWith(10, 'open', 0));
    open.mockRestore();
  });

  it('mostra estado vazio quando a biblioteca está vazia', async () => {
    browseLibrary.mockResolvedValue([]);
    listLibraryCategories.mockResolvedValue([]);
    listChildRecommendations.mockResolvedValue([]);
    renderWithClient(<Mundo />);
    expect(await screen.findByText(/nada por aqui ainda/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 4: Rodar e falhar** — `cd public/app-child && pnpm test Mundo` → FAIL.

- [ ] **Step 5: Implementar Mundo reescrito**

`public/app-child/src/pages/Mundo.tsx` (substituir):
```tsx
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { browseLibrary, listChildRecommendations, listLibraryCategories, recordHistory } from '../api/content';
import type { Content } from '../api/types';
import { EmptyState } from '../components/EmptyState';
import { Icon } from '../components/Icon';
import { Skeleton } from '../components/Skeleton';

function toUrl(domain: string): string {
  const t = domain.trim();
  return /^https?:\/\//i.test(t) ? t : `https://${t}`;
}

export function Mundo() {
  const [category, setCategory] = useState(0);
  const [search, setSearch] = useState('');
  const cats = useQuery({ queryKey: ['library', 'cats'], queryFn: listLibraryCategories });
  const recs = useQuery({ queryKey: ['library', 'recs'], queryFn: listChildRecommendations });
  const items = useQuery({ queryKey: ['library', 'items', category, search], queryFn: () => browseLibrary(category, search) });

  function open(c: Content) {
    if (c.url) {
      recordHistory(c.id, 'open', 0).catch(() => {});
      window.open(toUrl(c.url), '_blank', 'noopener,noreferrer');
    }
  }

  const list = items.data ?? [];

  return (
    <main className="flex flex-1 flex-col gap-stack-md px-container-padding-mobile py-stack-md">
      <div className="glass-panel flex items-center gap-2 rounded-2xl px-3 py-2 shadow-ambient">
        <Icon name="search" className="text-base text-on-surface-variant" />
        <input
          aria-label="Buscar"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Buscar na biblioteca"
          className="flex-1 bg-transparent text-label-md text-on-surface outline-none"
        />
      </div>

      {recs.data && recs.data.length > 0 && (
        <section>
          <h2 className="mb-2 px-1 font-display text-headline-md text-primary">Indicados pra você</h2>
          <div className="flex gap-3 overflow-x-auto pb-1">
            {recs.data.map((r) => (
              <button key={r.id} type="button" onClick={() => open(r.content)} className="glass-panel min-w-[140px] shrink-0 rounded-2xl p-3 text-left shadow-ambient">
                <Icon name="recommend" className="text-primary" filled />
                <div className="mt-1 text-label-md font-bold text-on-surface">{r.content.title}</div>
              </button>
            ))}
          </div>
        </section>
      )}

      <div className="-mx-1 flex gap-2 overflow-x-auto px-1">
        <Chip active={category === 0} label="Tudo" onClick={() => setCategory(0)} />
        {(cats.data ?? []).map((c) => (
          <Chip key={c.id} active={category === c.id} label={`${c.name} (${c.count})`} onClick={() => setCategory(c.id)} />
        ))}
      </div>

      {items.isLoading ? (
        <Skeleton />
      ) : items.error ? (
        <div className="glass-panel flex flex-col items-center gap-2 rounded-2xl bg-error/5 p-4 text-error">
          <Icon name="error" className="text-2xl" />
          <p className="text-label-sm">Não deu pra carregar agora.</p>
        </div>
      ) : list.length === 0 ? (
        <EmptyState icon="auto_stories" message={search ? `Nada encontrado pra "${search}"` : 'Nada por aqui ainda. Seu mundo será preenchido pelo papai.'} />
      ) : (
        <div className="grid grid-cols-2 gap-3">
          {list.map((c) => (
            <button key={c.id} type="button" onClick={() => open(c)} className="glass-panel flex flex-col gap-2 rounded-2xl p-4 text-left shadow-ambient active:scale-95">
              <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary-container text-on-primary-container">
                <Icon name="play_circle" className="text-2xl" filled />
              </div>
              <div className="font-display text-label-md font-bold text-on-surface">{c.title}</div>
              {c.description && <div className="text-label-sm text-on-surface-variant">{c.description}</div>}
            </button>
          ))}
        </div>
      )}
    </main>
  );
}

function Chip({ active, label, onClick }: { active: boolean; label: string; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={
        active
          ? 'shrink-0 rounded-full bg-primary px-3 py-1.5 text-label-sm font-semibold text-white'
          : 'shrink-0 rounded-full border border-outline-variant bg-white px-3 py-1.5 text-label-sm font-semibold text-on-surface'
      }
    >
      {label}
    </button>
  );
}
```
> A tela deixa de usar `CategoryCard` no Mundo (vira chips + cards de conteúdo). `CategoryCard` continua existindo (usado em testes próprios); não removê-lo.

- [ ] **Step 6: Rodar e passar + suíte + tsc**
```bash
pnpm test Mundo && pnpm test && pnpm exec tsc -b
```
Expected: Mundo PASS; suíte inteira verde (o `Mundo.test` antigo foi substituído); TS limpo.

- [ ] **Step 7: Commit**
```bash
git add public/app-child/src/api/content.ts public/app-child/src/api/types.ts public/app-child/src/components/Skeleton.tsx public/app-child/src/pages/Mundo.tsx public/app-child/src/pages/Mundo.test.tsx
git commit -m "feat(app-child): biblioteca real (browse/busca/categorias/abrir+history)"
```

---

## Task 9: Verificação completa + release + deploy

- [ ] **Step 1: Suítes completas**

PHP: `RUN` → verde. Apps:
```bash
cd public/app-parent && pnpm test && pnpm exec tsc -b && pnpm build
cd ../app-child && pnpm test && pnpm exec tsc -b && pnpm build && pnpm test:e2e
```
Expected: tudo verde.

- [ ] **Step 2: PR + CI**
```bash
git push -u origin feat/mundo-guardiao-sprint2
gh pr create --base master --head feat/mundo-guardiao-sprint2 \
  --title "feat: Biblioteca Inteligente (Mundo Guardião Sprint 2)" \
  --body "Migração 017 (campos de conteúdo + sort_order + duration + seed 12 categorias, DB v17), CRUD de conteúdo + analytics + recomendações CRUD/ordenação (pais), browse/busca/filtro-idade/favoritos/histórico (filho, /child/library/*), UX states. Estende a S1, nada existente alterado. Spec/plano em docs/superpowers/."
```
Acompanhar CI 4 jobs. **Integration** roda a migração 017 + seed em MySQL real.

- [ ] **Step 3: Merge squash**
```bash
gh pr merge <N> --squash --delete-branch
git checkout master && git pull --ff-only
```

- [ ] **Step 4: Bump versão + tag + release** — em `guardkids.php` bumpar `Version:` e `GUARDKIDS_VERSION` pra `1.28.0`, commit `chore(release): v1.28.0 — Biblioteca Inteligente`, tag `v1.28.0`, push, zip:
```bash
"$PHP" -d extension_dir="$EXT" -d extension=zip scripts/build-release-zip.php
gh release create v1.28.0 --title "v1.28.0 — Biblioteca Inteligente" \
  --notes "<resumo>" "C:/Users/mysho/OneDrive/Documentos/guardkids-wp/guardkids-wp-1.28.0.zip"
```

- [ ] **Step 5: Deploy SSH + smoke (confirmar DB v17 + seed)**
```bash
scp -o BatchMode=yes -P 65002 "<zip>" u217136411@82.25.73.253:~/
ssh -o BatchMode=yes -p 65002 u217136411@82.25.73.253 \
  'cd ~/domains/guardiaokids.site/public_html \
   && cp -r wp-content/plugins/guardkids-wp wp-content/plugins/guardkids-wp.bak-$(date +%Y%m%d-%H%M) \
   && wp plugin install ~/guardkids-wp-1.28.0.zip --force \
   && wp plugin get guardkids-wp --field=version \
   && wp option get guardkids_db_version \
   && wp db query "SELECT COUNT(*) FROM wp_guardkids_content_categories" \
   && rm -f ~/guardkids-wp-1.28.0.zip'
```
Expected: version `1.28.0`, `guardkids_db_version` **17**, categorias **12**. Smoke: home 200, `/content/analytics` sem nonce → 401, `/child/library` sem token → 401, painel-filho/pais carregam.

---

## Self-Review (feito na escrita do plano)

- **Cobertura do spec:** §3 migração → Task 1; §5 repos → Tasks 2-4; §4/§6 REST admin+filho → Tasks 5-6; §7 pais → Task 7; §8 filho → Task 8; §9 UX (Skeleton/empty/erro/sem-resultados) → Tasks 7-8; §10 testes → embutidos; §2 categorias seed → Task 1; analytics (§7 painel) → Task 4 (ContentAnalytics) + Task 5 (endpoint). ✅
- **Placeholders:** as notas condicionais (adicionar update/delete/query ao fake wpdb do ContentControllerTest; helpers seedChild/tokenReqBody; ajustar findByChild pra ASC) trazem a ação exata. Sem TODO/TBD.
- **Consistência de tipos:** `ContentAnalytics::compute(history, items, categories)` → shape usado no controller `analytics()` e no TS `ContentAnalytics`; `contentToJson` shape = TS `Content` (id/categoryId/title/.../ageMin/ageMax/estimatedMinutes/level/tags/[favorited]); repos `search/update/delete/findByChildOrdered/reorder/nextSortOrder/record/all/remove/contentIdsOf`; endpoints filho `childLibrary/childLibraryCategories/childRecommendations/childFavorites/childAddFavorite/childRemoveFavorite/childHistory`; api TS bate rota-a-rota. ✅
