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
            /** @var array<int, array<string, mixed>> */
            public array $children = [];

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
                if (str_contains((string) $sql, 'COUNT(*)')
                    && preg_match('/guardkids_content_items.*status = \'([a-z]+)\'/s', (string) $sql, $s) === 1) {
                    return (string) count(array_filter(
                        $this->t['content_items'] ?? [],
                        static fn ($r) => ($r['status'] ?? null) === $s[1],
                    ));
                }
                if (str_contains((string) $sql, 'COUNT(*)') && preg_match('/guardkids_(content_[a-z_]+)/', (string) $sql, $m) === 1) {
                    return (string) count($this->t[$m[1]] ?? []);
                }
                return null;
            }

            public function get_results($sql, $output = OBJECT)
            {
                if (preg_match('/guardkids_(content_[a-z_]+)/', (string) $sql, $m) === 1) {
                    $rows = array_values($this->t[$m[1]] ?? []);
                    if (str_contains((string) $sql, "status = 'approved'")) {
                        $rows = array_values(array_filter($rows, static fn ($r) => ($r['status'] ?? null) === 'approved'));
                    }
                    if (preg_match('/category_id = (\d+)/', (string) $sql, $c) === 1) {
                        $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['category_id'] ?? 0) === (int) $c[1]));
                    }
                    if (preg_match('/age_min <= (\d+) AND age_max >= (\d+)/', (string) $sql, $a) === 1) {
                        $age = (int) $a[1];
                        $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['age_min'] ?? 0) <= $age && (int) ($r['age_max'] ?? 99) >= $age));
                    }
                    if (preg_match('/child_id = (\d+)/', (string) $sql, $ch) === 1) {
                        $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['child_id'] ?? 0) === (int) $ch[1]));
                    }
                    return $rows;
                }
                return [];
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

            public function get_row($sql, $output = OBJECT, $y = 0)
            {
                if (preg_match('/guardkids_(content_[a-z_]+).*WHERE id = (\d+)/s', (string) $sql, $m) === 1) {
                    return $this->t[$m[1]][(int) $m[2]] ?? null;
                }
                if (str_contains((string) $sql, 'guardkids_children') &&
                    preg_match('/WHERE id = (\d+)/', (string) $sql, $m) === 1) {
                    return $this->children[(int) $m[1]] ?? null;
                }
                return null;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                if (preg_match('/guardkids_(content_[a-z_]+)/', (string) $table, $m) === 1) {
                    $id = (int) ($where['id'] ?? 0);
                    if (isset($this->t[$m[1]][$id])) {
                        $this->t[$m[1]][$id] = array_merge($this->t[$m[1]][$id], $data);
                    }
                }
                return 1;
            }

            public function delete($table, $where, $where_format = null)
            {
                if (preg_match('/guardkids_(content_[a-z_]+)/', (string) $table, $m) === 1) {
                    $id = (int) ($where['id'] ?? 0);
                    if (isset($this->t[$m[1]][$id])) {
                        unset($this->t[$m[1]][$id]);
                        return 1;
                    }
                    return 0;
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

    private function seedChild(int $id, int $age): void
    {
        $this->wpdb->children[$id] = ['id' => $id, 'age' => $age];
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

    public function testUpdateAndDeleteContent(): void
    {
        $this->wpdb->t['content_items'] = [1 => ['id' => 1, 'title' => 'A']];
        $up = new WP_REST_Request('PUT', '/content/1');
        $up['id'] = 1;
        $up->set_param('title', 'B');
        (new ContentController())->updateContent($up);
        self::assertSame('B', $this->wpdb->t['content_items'][1]['title']);

        $del = new WP_REST_Request('DELETE', '/content/1');
        $del['id'] = 1;
        $res = (new ContentController())->deleteContent($del);
        self::assertTrue($res->get_data()['deleted']);
        self::assertArrayNotHasKey(1, $this->wpdb->t['content_items']);
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

    public function testChildLibraryFiltersByAge(): void
    {
        $this->seedChild(1, 8);
        $this->wpdb->t['content_items'] = [
            1 => ['id' => 1, 'title' => 'A', 'category_id' => 1, 'age_min' => 4, 'age_max' => 6, 'status' => 'approved'],
            2 => ['id' => 2, 'title' => 'B', 'category_id' => 1, 'age_min' => 7, 'age_max' => 9, 'status' => 'approved'],
        ];
        $res = (new ContentController())->childLibrary($this->tokenReq('GET', '/child/library'));
        $data = $res->get_data();
        self::assertCount(1, $data);
        self::assertSame('B', $data[0]['title']);
    }

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

    public function testChildLibrary401WithoutToken(): void
    {
        $res = (new ContentController())->childLibrary(new WP_REST_Request('GET', '/child/library'));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(401, $res->get_error_data()['status']);
    }

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
}
