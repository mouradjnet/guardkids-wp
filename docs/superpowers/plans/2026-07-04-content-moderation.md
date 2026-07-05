# Moderação de Conteúdo — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Adicionar um portão de aprovação global ao Mundo Guardião — conteúdo novo nasce `pending` e só aparece pra criança depois que um guardião aprova.

**Architecture:** Coluna `status` (`pending`⇄`approved`) em `content_items` + `approved_by`/`approved_at`. Enforcement 100% server-side: todo caminho de leitura da criança usa leitura approved-only. Autor pode aprovar o próprio conteúdo (gate de staging). Conteúdo existente é *grandfathered* para `approved` na migração. Sem mudança de código no app-filho.

**Tech Stack:** PHP 8.2 (WordPress plugin, repos via `$wpdb`), PHPUnit 9.6 (FakeWpdb por regex), React/TS + TanStack Query + Vitest.

**Spec:** `docs/superpowers/specs/2026-07-04-content-moderation-design.md`

---

## Convenções (leia antes de começar)

**Branch:** já estamos em `feat/content-moderation`.

**Runner PHP (o `php` default é 8.1 e não tem sodium/openssl.cnf).** Antes de rodar qualquer teste PHP, crie uma vez os arquivos de config locais (gitignored) e exporte as variáveis:

```bash
PHP82="/c/Users/mysho/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe"
INI="$HOME/bin/composer-php.ini"; GKINI="/c/Users/mysho/guardkids-wp/.phpunit.cache/gk.ini"
mkdir -p /c/Users/mysho/guardkids-wp/.phpunit.cache
cp "$INI" "$GKINI"; echo 'extension=sodium' >> "$GKINI"
printf '[req]\ndefault_bits=2048\ndistinguished_name=req_dn\n[req_dn]\n' > /c/Users/mysho/guardkids-wp/.phpunit.cache/openssl.cnf
export OPENSSL_CONF="/c/Users/mysho/guardkids-wp/.phpunit.cache/openssl.cnf"
```

Daí, o comando de teste PHP (chamado de `gkphpunit` neste plano) é:

```bash
"$PHP82" -c "$GKINI" vendor/bin/phpunit --testsuite unit
```

Adicione `--filter <NomeDoTeste>` pra rodar um teste específico. `.phpunit.cache/` já é gitignored.

**Runner Vitest:** `cd public/app-parent && npx vitest run <path>` (ou `public/app-child`).

**Commit:** cada task termina com um commit. TDD: teste falhando → implementação mínima → teste passando → commit.

---

## Task 1: Migração 023 + bump da versão do banco

**Files:**
- Create: `database/migrations/023_content_moderation.php`
- Modify: `guardkids.php:22`
- Test: `tests/Unit/Database/Migration023Test.php`

- [ ] **Step 1: Escreva o teste falhando**

Create `tests/Unit/Database/Migration023Test.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

/**
 * Migração 023 — adiciona status/approved_by/approved_at em content_items e
 * faz grandfather do conteúdo existente (status='approved'). Verifica o DDL/DML
 * emitido capturando as queries num FakeWpdb.
 */
final class Migration023Test extends TestCase
{
    /** @var array<int, string> */
    private array $queries = [];

    private function fakeWpdb(bool $statusColumnExists): \wpdb
    {
        $self = $this;
        return new class ($self, $statusColumnExists) extends \wpdb {
            public string $prefix = 'wp_';
            public function __construct(private object $t, private bool $hasStatus)
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
            public function get_var($sql, $x = 0, $y = 0)
            {
                // SHOW COLUMNS ... LIKE 'status' → coluna existe?
                if (str_contains((string) $sql, "LIKE 'status'")) {
                    return $this->hasStatus ? 'status' : null;
                }
                return null; // demais colunas: fingir ausentes → ALTER roda
            }
            public function query($sql)
            {
                ($this->t)->record((string) $sql);
                return 0;
            }
        };
    }

    public function record(string $sql): void
    {
        $this->queries[] = $sql;
    }

    public function testAddsStatusColumnAndGrandfathersExisting(): void
    {
        $this->queries = [];
        $GLOBALS['wpdb'] = $this->fakeWpdb(statusColumnExists: false);

        $factory = require dirname(__DIR__, 3) . '/database/migrations/023_content_moderation.php';
        $factory($GLOBALS['wpdb'], 'utf8mb4');

        $all = implode("\n", $this->queries);
        self::assertMatchesRegularExpression('/ALTER TABLE \S*content_items ADD COLUMN status/', $all);
        self::assertMatchesRegularExpression('/ADD COLUMN approved_by/', $all);
        self::assertMatchesRegularExpression('/ADD COLUMN approved_at/', $all);
        // Grandfather: existentes viram approved
        self::assertMatchesRegularExpression(
            "/UPDATE \S*content_items SET status = 'approved'.*WHERE status = 'pending'/s",
            $all,
        );
    }

    public function testIdempotentWhenStatusColumnAlreadyExists(): void
    {
        $this->queries = [];
        $GLOBALS['wpdb'] = $this->fakeWpdb(statusColumnExists: true);

        $factory = require dirname(__DIR__, 3) . '/database/migrations/023_content_moderation.php';
        $factory($GLOBALS['wpdb'], 'utf8mb4');

        $all = implode("\n", $this->queries);
        // status já existe → não deve re-adicionar a coluna status
        self::assertDoesNotMatchRegularExpression('/ADD COLUMN status /', $all);
    }
}
```

- [ ] **Step 2: Rode e confirme que falha**

Run: `gkphpunit --filter Migration023Test`
Expected: FAIL — `require` de `023_content_moderation.php` falha (arquivo não existe).

- [ ] **Step 3: Crie a migração**

Create `database/migrations/023_content_moderation.php`:

```php
<?php

declare(strict_types=1);

/**
 * Migration 023 — Moderação de conteúdo. Adiciona status/approved_by/approved_at
 * em content_items e faz grandfather do conteúdo existente (status='approved'),
 * pra que nada que já está no ar suma da vista das crianças. ADD COLUMN não é
 * idempotente → guard addColumnIfMissing.
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

    $addColumnIfMissing($p . 'content_items', 'status', "VARCHAR(20) NOT NULL DEFAULT 'pending'");
    $addColumnIfMissing($p . 'content_items', 'approved_by', 'BIGINT UNSIGNED NULL');
    $addColumnIfMissing($p . 'content_items', 'approved_at', 'DATETIME NULL');

    // Grandfather: todo conteúdo já existente vira approved (senão sumiria da
    // biblioteca das crianças). Roda em todo boot mas é inofensivo — depois do
    // primeiro run não há mais linha 'pending' antiga; conteúdo novo criado pela
    // app nasce 'pending' DEPOIS desta migração ter subido o db_version, então
    // nunca é pego aqui.
    $now = current_time('mysql', true);
    $wpdb->query($wpdb->prepare(
        "UPDATE {$p}content_items SET status = 'approved', approved_at = %s WHERE status = 'pending'",
        $now,
    ));
};
```

> **Nota sobre o grandfather e a idempotência:** o `db_version` só sobe pra 23 depois que esta migração roda limpa. A partir daí ela nunca mais roda (o runner pula versões `<= current`). Portanto conteúdo novo criado com `status='pending'` pela app (Task 2/3) **não** é pego pelo UPDATE — ele roda uma vez só, no salto 22→23.

- [ ] **Step 4: Bump da versão do banco**

Modify `guardkids.php` linha 22:

```php
define('GUARDKIDS_DB_VERSION', 23);
```

(Deixe `GUARDKIDS_VERSION` como está por enquanto — o bump pra `1.34.0` acontece na Task 8, no release.)

- [ ] **Step 5: Rode e confirme que passa**

Run: `gkphpunit --filter Migration023Test`
Expected: PASS (2 testes).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/023_content_moderation.php guardkids.php tests/Unit/Database/Migration023Test.php
git commit -m "feat(content): migração 023 — status de moderação + grandfather (DB v23)"
```

---

## Task 2: ContentRepository — status no create, filtro approved, approve/revoke

**Files:**
- Modify: `database/ContentRepository.php`
- Test: `tests/Unit/Database/ContentModerationRepoTest.php`

- [ ] **Step 1: Escreva o teste falhando**

Create `tests/Unit/Database/ContentModerationRepoTest.php`:

```php
<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Database;

use GuardKids\Database\ContentRepository;
use PHPUnit\Framework\TestCase;

final class ContentModerationRepoTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new class () extends \wpdb {
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
                return vsprintf(str_replace(['%d', '%s'], ['%d', "'%s'"], (string) $query), $args);
            }
            public function get_var($sql, $x = 0, $y = 0)
            {
                if (str_contains((string) $sql, 'COUNT(*)') && preg_match("/status = '([a-z]+)'/", (string) $sql, $m) === 1) {
                    return (string) count(array_filter($this->rows, static fn ($r) => ($r['status'] ?? null) === $m[1]));
                }
                return null;
            }
            public function get_results($sql, $output = OBJECT)
            {
                $rows = array_values($this->rows);
                if (str_contains((string) $sql, "status = 'approved'")) {
                    $rows = array_values(array_filter($rows, static fn ($r) => ($r['status'] ?? null) === 'approved'));
                }
                return $rows;
            }
            public function get_row($sql, $output = OBJECT, $y = 0)
            {
                if (preg_match('/WHERE id = (\d+)/', (string) $sql, $m) === 1) {
                    return $this->rows[(int) $m[1]] ?? null;
                }
                return null;
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
        };
    }

    public function testCreateDefaultsToPending(): void
    {
        $repo = new ContentRepository();
        $id = $repo->create(['title' => 'A']);
        self::assertSame('pending', $repo->findById($id)['status']);
    }

    public function testSearchApprovedOnlyExcludesPending(): void
    {
        $repo = new ContentRepository();
        $repo->create(['title' => 'A']);            // pending
        $approvedId = $repo->create(['title' => 'B']);
        $repo->approve($approvedId, 7);

        $all = $repo->search(null, null, null, false);
        $approved = $repo->search(null, null, null, true);
        self::assertCount(2, $all);
        self::assertCount(1, $approved);
        self::assertSame('B', $approved[0]['title']);
    }

    public function testFindApprovedByIdReturnsNullForPending(): void
    {
        $repo = new ContentRepository();
        $id = $repo->create(['title' => 'A']); // pending
        self::assertNull($repo->findApprovedById($id));
        $repo->approve($id, 7);
        self::assertNotNull($repo->findApprovedById($id));
    }

    public function testApproveSetsApproverAndRevokeClearsIt(): void
    {
        $repo = new ContentRepository();
        $id = $repo->create(['title' => 'A']);
        $repo->approve($id, 42);
        $row = $repo->findById($id);
        self::assertSame('approved', $row['status']);
        self::assertSame(42, (int) $row['approved_by']);
        self::assertNotNull($row['approved_at']);

        $repo->revoke($id);
        $row = $repo->findById($id);
        self::assertSame('pending', $row['status']);
        self::assertNull($row['approved_by']);
        self::assertNull($row['approved_at']);
    }

    public function testCountByStatus(): void
    {
        $repo = new ContentRepository();
        $repo->create(['title' => 'A']);           // pending
        $repo->create(['title' => 'B']);           // pending
        $c = $repo->create(['title' => 'C']);
        $repo->approve($c, 7);
        self::assertSame(2, $repo->countByStatus('pending'));
        self::assertSame(1, $repo->countByStatus('approved'));
    }
}
```

- [ ] **Step 2: Rode e confirme que falha**

Run: `gkphpunit --filter ContentModerationRepoTest`
Expected: FAIL — `search()` só aceita 3 args; `findApprovedById`/`approve`/`revoke`/`countByStatus` não existem.

- [ ] **Step 3: Implemente no ContentRepository**

Modify `database/ContentRepository.php`. Troque o método `create` (linhas 31-36):

```php
    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $defaults = ['status' => 'pending', 'created_at' => current_time('mysql', true)];
        $ok = $this->db->insert($this->table(), $data + $defaults);
        return $ok === false ? 0 : (int) $this->db->insert_id;
    }
```

Troque a assinatura e o WHERE do `search` (linhas 43-72). Adicione o 4º parâmetro e a cláusula de status:

```php
    /**
     * Busca com filtros opcionais: categoria, termo (title/tags LIKE), idade.
     * Se $approvedOnly for true, só devolve conteúdo com status='approved'.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(?int $categoryId, ?string $term, ?int $childAge, bool $approvedOnly = false): array
    {
        $where = [];
        $params = [];
        if ($approvedOnly) {
            $where[] = "status = 'approved'";
        }
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
```

Adicione os métodos novos logo depois do `update` (antes do `}` final da classe):

```php
    /**
     * Como {@see findById}, mas só devolve se o conteúdo estiver aprovado.
     * Usado nos caminhos de leitura da criança (recomendações/favoritos).
     *
     * @return array<string, mixed>|null
     */
    public function findApprovedById(int $id): ?array
    {
        $row = $this->findById($id);
        return $row !== null && ($row['status'] ?? null) === 'approved' ? $row : null;
    }

    public function approve(int $id, int $userId): bool
    {
        return $this->update($id, [
            'status'      => 'approved',
            'approved_by' => $userId,
            'approved_at' => current_time('mysql', true),
        ]);
    }

    public function revoke(int $id): bool
    {
        return $this->update($id, [
            'status'      => 'pending',
            'approved_by' => null,
            'approved_at' => null,
        ]);
    }

    public function countByStatus(string $status): int
    {
        $sql = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE status = %s',
            $status,
        );
        return (int) $this->db->get_var($sql);
    }
```

- [ ] **Step 4: Rode e confirme que passa**

Run: `gkphpunit --filter ContentModerationRepoTest`
Expected: PASS (5 testes).

- [ ] **Step 5: Rode o teste de search antigo pra garantir compat**

Run: `gkphpunit --filter ContentRepositorySearchTest`
Expected: PASS (4 testes — a assinatura nova é retrocompatível pelo default `false`).

- [ ] **Step 6: Commit**

```bash
git add database/ContentRepository.php tests/Unit/Database/ContentModerationRepoTest.php
git commit -m "feat(content): status no create + search approvedOnly + approve/revoke/countByStatus"
```

---

## Task 3: ContentController — enforcement child + endpoints admin

**Files:**
- Modify: `api/Controllers/ContentController.php`
- Test: `tests/Unit/Api/ContentControllerTest.php` (estende o FakeWpdb + atualiza seeds + novos testes)

- [ ] **Step 1: Estenda o FakeWpdb do teste pra entender status**

Modify `tests/Unit/Api/ContentControllerTest.php`. No método `get_var` do FakeWpdb (após a linha do `setting_key`, antes do `COUNT(*)` genérico), adicione o count por status:

```php
                if (str_contains((string) $sql, 'COUNT(*)')
                    && preg_match('/guardkids_content_items.*status = \'([a-z]+)\'/s', (string) $sql, $s) === 1) {
                    return (string) count(array_filter(
                        $this->t['content_items'] ?? [],
                        static fn ($r) => ($r['status'] ?? null) === $s[1],
                    ));
                }
```

E no método `get_results` do FakeWpdb, dentro do `if (preg_match('/guardkids_(content_[a-z_]+)/'...`, adicione o filtro de status logo após pegar `$rows`:

```php
                    if (str_contains((string) $sql, "status = 'approved'")) {
                        $rows = array_values(array_filter($rows, static fn ($r) => ($r['status'] ?? null) === 'approved'));
                    }
```

- [ ] **Step 2: Atualize os seeds dos testes child existentes**

Ainda em `ContentControllerTest.php`, os testes child agora exigem conteúdo aprovado.

Em `testChildLibraryFiltersByAge`, troque o seed pra incluir `status`:

```php
        $this->wpdb->t['content_items'] = [
            1 => ['id' => 1, 'title' => 'A', 'category_id' => 1, 'age_min' => 4, 'age_max' => 6, 'status' => 'approved'],
            2 => ['id' => 2, 'title' => 'B', 'category_id' => 1, 'age_min' => 7, 'age_max' => 9, 'status' => 'approved'],
        ];
```

Em `testChildFavoriteAddThenRemove`, semeie o conteúdo aprovado antes do add (o `childAddFavorite` agora valida):

```php
    public function testChildFavoriteAddThenRemove(): void
    {
        $this->seedChild(1, 8);
        $this->wpdb->t['content_items'] = [10 => ['id' => 10, 'title' => 'X', 'status' => 'approved']];
        $add = $this->tokenReq('POST', '/child/library/favorites');
        $add->set_param('content_id', 10);
        self::assertSame(201, (new ContentController())->childAddFavorite($add)->get_status());

        $del = $this->tokenReq('DELETE', '/child/library/favorites/10');
        $del['contentId'] = 10;
        self::assertTrue((new ContentController())->childRemoveFavorite($del)->get_data()['ok']);
        self::assertEmpty($this->wpdb->t['content_favorites'] ?? []);
    }
```

Em `testChildHistoryRecords`, semeie o conteúdo aprovado:

```php
    public function testChildHistoryRecords(): void
    {
        $this->seedChild(1, 8);
        $this->wpdb->t['content_items'] = [10 => ['id' => 10, 'title' => 'X', 'status' => 'approved']];
        $req = $this->tokenReq('POST', '/child/library/history');
        $req->set_param('content_id', 10);
        $req->set_param('action', 'open');
        $req->set_param('duration_seconds', 0);
        $res = (new ContentController())->childHistory($req);
        self::assertSame(201, $res->get_status());
        self::assertNotEmpty($this->wpdb->t['content_history']);
    }
```

- [ ] **Step 3: Escreva os testes novos de moderação (falhando)**

Adicione ao final de `ContentControllerTest.php` (antes do `}` de fecho da classe):

```php
    public function testChildLibraryExcludesPending(): void
    {
        $this->seedChild(1, 8);
        $this->wpdb->t['content_items'] = [
            1 => ['id' => 1, 'title' => 'Aprovado', 'category_id' => 1, 'age_min' => 0, 'age_max' => 99, 'status' => 'approved'],
            2 => ['id' => 2, 'title' => 'Pendente', 'category_id' => 1, 'age_min' => 0, 'age_max' => 99, 'status' => 'pending'],
        ];
        $data = (new ContentController())->childLibrary($this->tokenReq('GET', '/child/library'))->get_data();
        self::assertCount(1, $data);
        self::assertSame('Aprovado', $data[0]['title']);
    }

    public function testChildFavoritesSkipsPending(): void
    {
        $this->seedChild(1, 8);
        $this->wpdb->t['content_items'] = [
            5 => ['id' => 5, 'title' => 'Pendente', 'status' => 'pending'],
        ];
        $this->wpdb->t['content_favorites'] = [
            1 => ['id' => 1, 'child_id' => 1, 'content_id' => 5],
        ];
        $data = (new ContentController())->childFavorites($this->tokenReq('GET', '/child/library/favorites'))->get_data();
        self::assertSame([], $data);
    }

    public function testChildAddFavoriteRejectsPending(): void
    {
        $this->seedChild(1, 8);
        $this->wpdb->t['content_items'] = [9 => ['id' => 9, 'title' => 'P', 'status' => 'pending']];
        $add = $this->tokenReq('POST', '/child/library/favorites');
        $add->set_param('content_id', 9);
        $res = (new ContentController())->childAddFavorite($add);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(409, $res->get_error_data()['status']);
    }

    public function testApproveAndRevokeContent(): void
    {
        $this->wpdb->t['content_items'] = [1 => ['id' => 1, 'title' => 'A', 'status' => 'pending']];
        $ap = new WP_REST_Request('POST', '/content/1/approve');
        $ap['id'] = 1;
        $res = (new ContentController())->approveContent($ap);
        self::assertSame('approved', $res->get_data()['status']);

        $rv = new WP_REST_Request('POST', '/content/1/revoke');
        $rv['id'] = 1;
        $res = (new ContentController())->revokeContent($rv);
        self::assertSame('pending', $res->get_data()['status']);
    }

    public function testApproveContent404WhenMissing(): void
    {
        $req = new WP_REST_Request('POST', '/content/999/approve');
        $req['id'] = 999;
        $res = (new ContentController())->approveContent($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(404, $res->get_error_data()['status']);
    }

    public function testListContentsFiltersByStatus(): void
    {
        $this->wpdb->t['content_items'] = [
            1 => ['id' => 1, 'title' => 'A', 'status' => 'approved'],
            2 => ['id' => 2, 'title' => 'B', 'status' => 'pending'],
        ];
        $req = new WP_REST_Request('GET', '/content');
        $req->set_param('status', 'pending');
        $data = (new ContentController())->listContents($req)->get_data();
        self::assertCount(1, $data);
        self::assertSame('B', $data[0]['title']);
        self::assertSame('pending', $data[0]['status']);
    }

    public function testSummaryIncludesPendingCount(): void
    {
        $this->wpdb->t['content_items'] = [
            1 => ['id' => 1, 'title' => 'A', 'status' => 'pending'],
            2 => ['id' => 2, 'title' => 'B', 'status' => 'pending'],
            3 => ['id' => 3, 'title' => 'C', 'status' => 'approved'],
        ];
        $data = (new ContentController())->summary(new WP_REST_Request('GET', '/content/summary'))->get_data();
        self::assertSame(2, $data['pendingCount']);
    }
```

- [ ] **Step 4: Rode e confirme que falha**

Run: `gkphpunit --filter ContentControllerTest`
Expected: FAIL — `approveContent`/`revokeContent` não existem; `status` não está no JSON; `pendingCount` ausente; enforcement ainda não filtra.

- [ ] **Step 5: Implemente o enforcement e os endpoints no ContentController**

Modify `api/Controllers/ContentController.php`.

**5a.** Em `childLibrary` (linha ~59), passe `true` como 4º arg:

```php
        $rows = $this->contentRepo->search(
            is_numeric($category) ? (int) $category : null,
            is_string($search) ? $search : null,
            $this->childAge($childId),
            true,
        );
```

**5b.** Em `childLibraryCategories` (linha ~76):

```php
        $items = $this->contentRepo->search(null, null, $this->childAge($childId), true);
```

**5c.** Em `childRecommendations` (linha ~99), troque `findById` por `findApprovedById`:

```php
            $content = $this->contentRepo->findApprovedById((int) ($rec['content_id'] ?? 0));
```

**5d.** Em `childFavorites` (linha ~115):

```php
            $content = $this->contentRepo->findApprovedById($cid);
```

**5e.** Em `childAddFavorite`, após validar `$contentId === 0` (linha ~132), adicione o guard:

```php
        if ($this->contentRepo->findApprovedById($contentId) === null) {
            return new WP_Error('content_not_available', 'Conteúdo indisponível.', ['status' => 409]);
        }
```

**5f.** Em `childHistory`, após validar `$contentId === 0` (linha ~156), adicione o mesmo guard:

```php
        if ($this->contentRepo->findApprovedById($contentId) === null) {
            return new WP_Error('content_not_available', 'Conteúdo indisponível.', ['status' => 409]);
        }
```

**5g.** Em `listContents` (linha ~185), adicione o filtro de status:

```php
    public function listContents(WP_REST_Request $req): WP_REST_Response
    {
        $category = $req->get_param('category');
        $search   = $req->get_param('search');
        $status   = $req->get_param('status');
        $rows = $this->contentRepo->search(
            is_numeric($category) ? (int) $category : null,
            is_string($search) ? $search : null,
            null,
        );
        if (is_string($status) && in_array($status, ['pending', 'approved'], true)) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $r): bool => ($r['status'] ?? 'approved') === $status,
            ));
        }
        return rest_ensure_response(array_map([$this, 'contentToJson'], $rows));
    }
```

**5h.** Em `contentToJson` (linha ~295), adicione `status` ao array retornado (após `'tags'`):

```php
            'tags'             => $row['tags'] ?? null,
            'status'           => (string) ($row['status'] ?? 'approved'),
```

**5i.** Em `summary` (linha ~357), adicione `pendingCount`:

```php
    public function summary(WP_REST_Request $req): WP_REST_Response
    {
        return rest_ensure_response([
            'contents'        => $this->contentRepo->count(),
            'categories'      => $this->categoriesRepo->count(),
            'favorites'       => $this->favorites->count(),
            'recommendations' => $this->recommendations->count(),
            'pendingCount'    => $this->contentRepo->countByStatus('pending'),
            'lastSync'        => null,
        ]);
    }
```

**5j.** Adicione os dois handlers novos (após `deleteContent`, linha ~240):

```php
    public function approveContent(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id = (int) $req['id'];
        if ($this->contentRepo->findById($id) === null) {
            return new WP_Error('not_found', 'Conteúdo não encontrado.', ['status' => 404]);
        }
        $this->contentRepo->approve($id, (int) get_current_user_id());
        return rest_ensure_response($this->contentToJson($this->contentRepo->findById($id) ?? []));
    }

    public function revokeContent(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id = (int) $req['id'];
        if ($this->contentRepo->findById($id) === null) {
            return new WP_Error('not_found', 'Conteúdo não encontrado.', ['status' => 404]);
        }
        $this->contentRepo->revoke($id);
        return rest_ensure_response($this->contentToJson($this->contentRepo->findById($id) ?? []));
    }
```

- [ ] **Step 6: Rode e confirme que passa**

Run: `gkphpunit --filter ContentControllerTest`
Expected: PASS (todos, incl. os 7 novos e os 3 seeds atualizados).

- [ ] **Step 7: Commit**

```bash
git add api/Controllers/ContentController.php tests/Unit/Api/ContentControllerTest.php
git commit -m "feat(content): enforcement approved-only nos caminhos child + approve/revoke/status/pendingCount"
```

---

## Task 4: Rotas REST approve/revoke

**Files:**
- Modify: `api/RestApi.php` (dentro de `registerContentRoutes`)

- [ ] **Step 1: Adicione as rotas**

Modify `api/RestApi.php`. Logo após o bloco `register_rest_route(self::NAMESPACE, '/content/(?P<id>\d+)', [...]);` (que termina na linha ~217), adicione:

```php
        register_rest_route(self::NAMESPACE, '/content/(?P<id>\d+)/approve', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'approveContent'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);

        register_rest_route(self::NAMESPACE, '/content/(?P<id>\d+)/revoke', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'revokeContent'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);
```

- [ ] **Step 2: Confirme que a suíte PHP inteira segue verde**

Run: `gkphpunit`
Expected: PASS (todos os testes unit; deve ser ~526 + os novos).

- [ ] **Step 3: Commit**

```bash
git add api/RestApi.php
git commit -m "feat(content): rotas admin POST /content/{id}/approve e /revoke"
```

---

## Task 5: Frontend — api/content.ts

**Files:**
- Modify: `public/app-parent/src/api/content.ts`

- [ ] **Step 1: Adicione o campo status e pendingCount aos tipos**

Modify `public/app-parent/src/api/content.ts`.

Em `ContentSummary` (linha 3-9), adicione `pendingCount`:

```ts
export type ContentSummary = {
  contents: number;
  categories: number;
  favorites: number;
  recommendations: number;
  pendingCount: number;
  lastSync: string | null;
};
```

Em `Content` (linha 15-28), adicione `status` no fim:

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
  status: 'pending' | 'approved';
};
```

- [ ] **Step 2: Adicione o param de status ao listContents e as funções approve/revoke**

Troque `listContents` (linhas 53-59):

```ts
export function listContents(
  category = 0,
  search = '',
  status: '' | 'pending' | 'approved' = '',
): Promise<Content[]> {
  const params = new URLSearchParams();
  if (category > 0) params.set('category', String(category));
  if (search) params.set('search', search);
  if (status) params.set('status', status);
  const qs = params.toString();
  return apiFetch<Content[]>(`/content${qs ? `?${qs}` : ''}`);
}
```

Adicione após `deleteContent` (linha 75):

```ts
export function approveContent(id: number): Promise<Content> {
  return apiFetch<Content>(`/content/${id}/approve`, { method: 'POST' });
}

export function revokeContent(id: number): Promise<Content> {
  return apiFetch<Content>(`/content/${id}/revoke`, { method: 'POST' });
}
```

- [ ] **Step 3: Confirme o typecheck**

Run: `cd public/app-parent && npx tsc -b`
Expected: sem erros (o `ContentDashboard` ainda não usa as funções novas, mas os tipos compilam).

> Nota: `tsc -b` pode acusar erro em `ContentDashboard`/testes se algum consumidor de `Content` construir o objeto sem `status`. Se acusar, é esperado — será resolvido nas Tasks 6. Se o erro for só nos arquivos que a Task 6 vai tocar, siga; senão, ajuste o consumidor apontado.

- [ ] **Step 4: Commit**

```bash
git add public/app-parent/src/api/content.ts
git commit -m "feat(content): api front — status, listContents(status), approveContent/revokeContent"
```

---

## Task 6: Frontend — ContentDashboard (filtro, badges, aprovar/revogar, contador)

**Files:**
- Modify: `public/app-parent/src/pages/ContentDashboard.tsx`
- Test: `public/app-parent/src/pages/ContentDashboard.test.tsx`

- [ ] **Step 1: Escreva o teste falhando**

Modify `public/app-parent/src/pages/ContentDashboard.test.tsx`. Substitua o arquivo inteiro por:

```tsx
import { fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { ContentDashboard } from './ContentDashboard';

const getAnalytics = vi.fn();
const listContents = vi.fn();
const listContentCategories = vi.fn();
const getContentSummary = vi.fn();
const approveContent = vi.fn();
const revokeContent = vi.fn();
vi.mock('../api/content', () => ({
  getAnalytics: () => getAnalytics(),
  listContents: (...args: unknown[]) => listContents(...args),
  listContentCategories: () => listContentCategories(),
  getContentSummary: () => getContentSummary(),
  approveContent: (id: number) => approveContent(id),
  revokeContent: (id: number) => revokeContent(id),
  createContent: vi.fn(),
  updateContent: vi.fn(),
  deleteContent: vi.fn(),
  listRecommendations: () => Promise.resolve([]),
  createRecommendation: vi.fn(),
  deleteRecommendation: vi.fn(),
  reorderRecommendations: vi.fn(),
}));
vi.mock('../api/children', () => ({
  listChildren: () => Promise.resolve([]),
}));

const pendingItem = {
  id: 1, categoryId: 1, title: 'Pendente', description: null, url: null, thumbnail: null,
  type: 'link', ageMin: 0, ageMax: 99, estimatedMinutes: null, level: null, tags: null,
  status: 'pending' as const,
};

function defaults() {
  getAnalytics.mockResolvedValue({ mostAccessed: [], favoriteCategories: [], timePerCategory: [] });
  listContentCategories.mockResolvedValue([]);
  getContentSummary.mockResolvedValue({ contents: 1, categories: 0, favorites: 0, recommendations: 0, pendingCount: 1, lastSync: null });
}

describe('ContentDashboard', () => {
  afterEach(() => {
    vi.clearAllMocks();
  });

  it('mostra estado vazio quando não há conteúdo e botão ativo', async () => {
    defaults();
    listContents.mockResolvedValue([]);
    getContentSummary.mockResolvedValue({ contents: 0, categories: 0, favorites: 0, recommendations: 0, pendingCount: 0, lastSync: null });
    renderWithClient(<ContentDashboard />);
    expect(await screen.findByText('Nenhum conteúdo cadastrado')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /adicionar conteúdo/i })).toBeEnabled();
  });

  it('marca item pendente com badge e botão Aprovar que chama a api', async () => {
    defaults();
    listContents.mockResolvedValue([pendingItem]);
    approveContent.mockResolvedValue({ ...pendingItem, status: 'approved' });
    renderWithClient(<ContentDashboard />);

    expect(await screen.findByText('Pendente')).toBeInTheDocument();
    expect(screen.getByText('Pendente', { selector: 'span' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /aprovar/i }));
    await waitFor(() => expect(approveContent).toHaveBeenCalledWith(1));
  });
});
```

- [ ] **Step 2: Rode e confirme que falha**

Run: `cd public/app-parent && npx vitest run src/pages/ContentDashboard.test.tsx`
Expected: FAIL — não existe badge nem botão "Aprovar"; a mock de `getContentSummary`/`approveContent` ainda não é usada pelo componente.

- [ ] **Step 3: Implemente o ContentDashboard**

Modify `public/app-parent/src/pages/ContentDashboard.tsx`.

**3a.** Troque o import da api (linhas 3-6):

```tsx
import {
  approveContent, createContent, deleteContent, getAnalytics, getContentSummary,
  listContentCategories, listContents, revokeContent, updateContent,
  type Content, type ContentInput,
} from '../api/content';
```

**3b.** No corpo do componente, troque as queries/estado (linhas 32-39) por:

```tsx
  const qc = useQueryClient();
  const [statusFilter, setStatusFilter] = useState<'' | 'pending' | 'approved'>('');
  const analytics = useQuery({ queryKey: ['content', 'analytics'], queryFn: getAnalytics });
  const categories = useQuery({ queryKey: ['content', 'categories'], queryFn: listContentCategories });
  const contents = useQuery({ queryKey: ['content', 'list', statusFilter], queryFn: () => listContents(0, '', statusFilter) });
  const summary = useQuery({ queryKey: ['content', 'summary'], queryFn: getContentSummary });
  const children = useQuery({ queryKey: ['children'], queryFn: listChildren });
  const [editing, setEditing] = useState<Content | null>(null);
  const [creating, setCreating] = useState(false);
  const [recChild, setRecChild] = useState(0);
```

**3c.** Troque o `invalidate` e adicione as mutations de aprovar/revogar (linhas 41-49):

```tsx
  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['content', 'list'] });
    qc.invalidateQueries({ queryKey: ['content', 'analytics'] });
    qc.invalidateQueries({ queryKey: ['content', 'summary'] });
  };
  const save = useMutation({
    mutationFn: (input: ContentInput) => (editing ? updateContent(editing.id, input) : createContent(input)),
    onSuccess: () => { invalidate(); setEditing(null); setCreating(false); },
  });
  const remove = useMutation({ mutationFn: (id: number) => deleteContent(id), onSuccess: invalidate });
  const approve = useMutation({ mutationFn: (id: number) => approveContent(id), onSuccess: invalidate });
  const revoke = useMutation({ mutationFn: (id: number) => revokeContent(id), onSuccess: invalidate });
```

**3d.** Troque o cabeçalho (linhas 56-61) pra incluir o contador de pendentes:

```tsx
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <h1 className="font-display text-headline-lg text-on-background">Conteúdo Infantil</h1>
          {(summary.data?.pendingCount ?? 0) > 0 && (
            <span className="rounded-full bg-amber-500/15 px-3 py-1 text-label-sm font-semibold text-amber-700">
              {summary.data?.pendingCount} pendente{summary.data?.pendingCount === 1 ? '' : 's'}
            </span>
          )}
        </div>
        <button type="button" onClick={() => { setEditing(null); setCreating(true); }} className="rounded-xl bg-primary px-5 py-2.5 text-label-md font-semibold text-white">
          Adicionar Conteúdo
        </button>
      </div>
```

**3e.** Adicione o filtro de status logo antes do bloco da lista (antes do `{contents.isLoading ? (`, linha ~69):

```tsx
      <div className="flex gap-2" role="group" aria-label="Filtrar por status">
        {([['', 'Todos'], ['pending', 'Pendentes'], ['approved', 'Aprovados']] as const).map(([value, label]) => (
          <button
            key={value}
            type="button"
            onClick={() => setStatusFilter(value)}
            className={`rounded-full px-4 py-1.5 text-label-sm font-semibold ${
              statusFilter === value ? 'bg-primary text-white' : 'bg-surface-container-low text-on-surface-variant'
            }`}
          >
            {label}
          </button>
        ))}
      </div>
```

**3f.** Troque a linha de cada item da lista (linhas 78-89) pra incluir o badge e o botão contextual:

```tsx
          {list.map((c) => (
            <div key={c.id} className="flex items-center justify-between p-3">
              <div>
                <div className="flex items-center gap-2">
                  <span className="text-label-md font-semibold text-on-surface">{c.title}</span>
                  <span className={`rounded-full px-2 py-0.5 text-label-sm font-semibold ${
                    c.status === 'approved' ? 'bg-green-500/15 text-green-700' : 'bg-amber-500/15 text-amber-700'
                  }`}>
                    {c.status === 'approved' ? 'Aprovado' : 'Pendente'}
                  </span>
                </div>
                <div className="text-label-sm text-on-surface-variant">{c.ageMin}–{c.ageMax} anos{c.tags ? ` · ${c.tags}` : ''}</div>
              </div>
              <div className="flex gap-2">
                {c.status === 'pending' ? (
                  <button type="button" onClick={() => approve.mutate(c.id)} className="text-green-700">Aprovar</button>
                ) : (
                  <button type="button" onClick={() => revoke.mutate(c.id)} className="text-amber-700">Revogar</button>
                )}
                <button type="button" onClick={() => { setCreating(false); setEditing(c); }} className="text-primary">Editar</button>
                <button type="button" onClick={() => remove.mutate(c.id)} className="text-error">Excluir</button>
              </div>
            </div>
          ))}
```

- [ ] **Step 4: Rode e confirme que passa**

Run: `cd public/app-parent && npx vitest run src/pages/ContentDashboard.test.tsx`
Expected: PASS (2 testes).

- [ ] **Step 5: Rode a suíte inteira do app-parent + typecheck**

Run: `cd public/app-parent && npx tsc -b && npx vitest run`
Expected: sem erro de TS; todos os testes verdes (303 + os ajustes).

- [ ] **Step 6: Commit**

```bash
git add public/app-parent/src/pages/ContentDashboard.tsx public/app-parent/src/pages/ContentDashboard.test.tsx
git commit -m "feat(content): painel de moderação — filtro, badges, aprovar/revogar, contador de pendentes"
```

---

## Task 7: Gate de qualidade completo

**Files:** nenhum (só validação)

- [ ] **Step 1: PHP unit inteiro**

Run: `gkphpunit`
Expected: PASS (todos). Se algo quebrar, corrija antes de seguir.

- [ ] **Step 2: Vitest app-parent**

Run: `cd public/app-parent && npx vitest run`
Expected: PASS.

- [ ] **Step 3: Vitest app-child (nenhuma mudança, mas confirme verde)**

Run: `cd public/app-child && npx vitest run`
Expected: PASS.

- [ ] **Step 4: Commit (se algum fix foi necessário)**

```bash
git add -A
git commit -m "test(content): ajustes finais do gate de qualidade" || echo "nada a commitar"
```

---

## Task 8: Release v1.34.0 + deploy prod

**Files:**
- Modify: `guardkids.php:21` (versão do plugin)

- [ ] **Step 1: Bump da versão do plugin**

Modify `guardkids.php`. Linha 21 e o header `Version:`:

```php
define('GUARDKIDS_VERSION', '1.34.0');
```

E o comentário de header do arquivo (`* Version: 1.33.0` → `* Version: 1.34.0`).

- [ ] **Step 2: Commit + merge do PR**

```bash
git add guardkids.php
git commit -m "chore(release): v1.34.0 — Moderação de Conteúdo (Mundo Guardião)"
git push -u origin feat/content-moderation
gh pr create --base master --head feat/content-moderation \
  --title "feat: moderação de conteúdo (Mundo Guardião v1.34.0)" \
  --body "Gate de aprovação global em content_items (pending⇄approved). Enforcement server-side em todos os caminhos de leitura da criança. Migração 023 + grandfather. Spec/plano em docs/superpowers/."
```

Aguarde o CI verde, depois:

```bash
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only
git tag v1.34.0 && git push origin v1.34.0
```

- [ ] **Step 3: Build dos apps + zip**

```bash
cd /c/Users/mysho/guardkids-wp/public/app-child && npm run build
cd /c/Users/mysho/guardkids-wp/public/app-parent && npm run build
cd /c/Users/mysho/guardkids-wp && "$PHP82" -c "$GKINI" scripts/build-release-zip.php
```

Confirme o zip em `~/OneDrive/Documentos/guardkids-wp/guardkids-wp-1.34.0.zip`.

- [ ] **Step 4: GitHub Release**

```bash
gh release create v1.34.0 \
  "/c/Users/mysho/OneDrive/Documentos/guardkids-wp/guardkids-wp-1.34.0.zip#guardkids-wp-1.34.0.zip" \
  --title "v1.34.0 — Moderação de Conteúdo" \
  --notes "Gate de aprovação: conteúdo novo nasce pendente e só aparece pra criança após aprovação de um guardião. Migração 023 (DB v23) + grandfather do conteúdo existente."
```

- [ ] **Step 5: Deploy prod via SSH (com migração)**

```bash
ZIP="/c/Users/mysho/OneDrive/Documentos/guardkids-wp/guardkids-wp-1.34.0.zip"
scp -o BatchMode=yes -P 65002 "$ZIP" u217136411@82.25.73.253:~/guardkids-wp-1.34.0.zip
ssh -o BatchMode=yes -p 65002 u217136411@82.25.73.253 'cd ~/domains/guardiaokids.site/public_html && cp -r wp-content/plugins/guardkids-wp wp-content/plugins/guardkids-wp.bak-$(date +%Y%m%d-%H%M) && wp plugin install ~/guardkids-wp-1.34.0.zip --force 2>&1 | grep -viE "post-quantum|^\*\*" && echo "version: $(wp plugin get guardkids-wp --field=version)" && echo "db_version: $(wp option get guardkids_db_version)" && rm -f ~/guardkids-wp-1.34.0.zip' 2>&1 | grep -viE 'post-quantum|^\*\*'
```

Expected: `version: 1.34.0`, `db_version: 23`.

- [ ] **Step 6: Smoke crítico — o grandfather funcionou?**

Este é o passo que valida o risco principal. Emita um token de filho e compare a biblioteca:

```bash
TOKEN=$(ssh -o BatchMode=yes -p 65002 u217136411@82.25.73.253 'cd ~/domains/guardiaokids.site/public_html && wp eval "\$r=(new GuardKids\Auth\ChildAuth())->issueToken(9,\"moderation-smoke\"); echo \$r[\"token\"];"' 2>/dev/null | grep -viE 'post-quantum|^\*\*' | tr -d "[:space:]")
BASE="https://guardiaokids.site/wp-json/guardkids/v1"
echo "biblioteca (deve ter o conteúdo existente, não vazio se havia conteúdo aprovado):"
curl -s -H "X-GuardKids-Token: $TOKEN" "$BASE/child/library?_=$(date +%s)" | head -c 400; echo
echo "summary pendingCount (admin — precisa de auth admin; validar no painel):"
```

Verifique no painel-pais (logado) que **Conteúdo Infantil** lista os itens existentes como **Aprovado** e o contador de pendentes reflete a realidade. Se a biblioteca do filho voltar vazia mas havia conteúdo antes → o grandfather falhou; investigue o `db_version` e o log.

Ao final, **delete o token de smoke** (higiene):

```bash
HASH=$(printf '%s' "$TOKEN" | sha256sum | cut -d' ' -f1)
ssh -o BatchMode=yes -p 65002 u217136411@82.25.73.253 "cd ~/domains/guardiaokids.site/public_html && wp eval 'global \$wpdb; \$wpdb->query(\$wpdb->prepare(\"DELETE FROM {\$wpdb->prefix}guardkids_settings WHERE setting_key=%s\", \"child_token:$HASH\"));'" 2>&1 | grep -viE 'post-quantum|^\*\*'
```

- [ ] **Step 7: Smoke visual (browser)**

No painel-pais (`https://guardiaokids.site/painel-pais`): adicione um conteúdo novo → confirme que aparece como **Pendente** e **não** aparece na biblioteca do filho; clique **Aprovar** → confirme que passa a aparecer pra criança. Teste **Revogar** → some da vista da criança.

---

## Self-Review (preenchido pelo autor do plano)

**Cobertura do spec:**
- Migração 023 (status/approved_by/approved_at + grandfather + idempotência) → Task 1 ✓
- `ContentRepository` (search approvedOnly, findApprovedById, create pending, approve/revoke, countByStatus) → Task 2 ✓
- Enforcement nos 6 caminhos child (library, categories, recommendations, favorites, addFavorite 409, history 409) → Task 3 (5a-5f) ✓
- Admin: listContents?status, status no JSON, summary.pendingCount, approve/revoke endpoints → Task 3 (5g-5j) ✓
- Rotas REST approve/revoke (requireAdmin) → Task 4 ✓
- Frontend api (status, pendingCount, listContents(status), approve/revoke) → Task 5 ✓
- Frontend ContentDashboard (filtro, badges, botões, contador) → Task 6 ✓
- app-child sem mudança → confirmado (Task 7 step 3 só valida verde) ✓
- Rollout v1.34.0/DB v23 + smoke do grandfather → Task 8 ✓

**Consistência de tipos:** `search(?int,?string,?int,bool $approvedOnly=false)`, `findApprovedById(int):?array`, `approve(int,int):bool`, `revoke(int):bool`, `countByStatus(string):int`, `approveContent`/`revokeContent` (front) — nomes batem entre repo, controller, rotas e front. `status: 'pending'|'approved'`, `pendingCount:number` consistentes.

**Sem placeholders:** todos os passos de código têm o código real.
