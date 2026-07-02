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
}
