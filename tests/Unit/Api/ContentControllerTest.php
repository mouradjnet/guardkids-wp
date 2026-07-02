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
