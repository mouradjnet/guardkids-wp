<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\SiteController;
use GuardKids\Tests\Support\AlwaysAllowGate;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class SiteControllerTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<int, array<string, mixed>> sites */
            public array $rows = [];
            /** @var array<int, array<string, mixed>> */
            public array $children = [];
            /** @var array<int, array<string, mixed>> */
            public array $notifications = [];

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

            public function get_row($sql, $output = OBJECT, $y = 0)
            {
                if (preg_match('/WHERE id = (\d+)/', (string) $sql, $m) === 1) {
                    return $this->rows[(int) $m[1]] ?? null;
                }
                return null;
            }

            public function get_results($sql, $output = OBJECT)
            {
                if (str_contains((string) $sql, 'guardkids_children')) {
                    return array_values($this->children);
                }
                if (str_contains((string) $sql, 'guardkids_notifications')) {
                    $out = $this->notifications;
                    if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                        $out = array_values(array_filter(
                            $out,
                            static fn (array $r): bool => (int) $r['child_id'] === (int) $m[1],
                        ));
                    }
                    if (preg_match("/dedup_key = '([^']*)'/", (string) $sql, $d) === 1) {
                        $out = array_values(array_filter(
                            $out,
                            static fn (array $r): bool => (string) ($r['dedup_key'] ?? '') === $d[1],
                        ));
                    }
                    return $out;
                }
                return array_values($this->rows);
            }

            public function insert($table, $data, $format = null)
            {
                if (str_contains((string) $table, 'guardkids_notifications')) {
                    $id = count($this->notifications) + 1;
                    $this->notifications[$id] = array_merge(['id' => $id], $data);
                    return 1;
                }
                $this->insert_id = count($this->rows) + 1;
                $this->rows[$this->insert_id] = array_merge(['id' => $this->insert_id], $data);
                return 1;
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

    public function testIndexAllReturnsAllSites(): void
    {
        $this->wpdb->rows = [
            1 => ['id' => 1, 'domain' => 'a.com', 'list_type' => 'whitelist'],
            2 => ['id' => 2, 'domain' => 'b.com', 'list_type' => 'blacklist'],
        ];
        $req = new WP_REST_Request('GET', '/sites');
        $req->set_param('list', 'all');

        $res = (new SiteController(new AlwaysAllowGate()))->index($req);
        self::assertCount(2, $res->get_data());
    }

    public function testCreateReturns201WithDefaultsWhenWhitelistOmitted(): void
    {
        $req = new WP_REST_Request('POST', '/sites');
        $req->set_param('domain', 'khanacademy.org');

        $res = (new SiteController(new AlwaysAllowGate()))->create($req);
        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertSame(201, $res->get_status());
        self::assertSame('khanacademy.org', $res->get_data()['domain']);
        self::assertSame('whitelist', $res->get_data()['listType']);
    }

    public function testCreateAcceptsBlacklistAndAppliesTo(): void
    {
        $req = new WP_REST_Request('POST', '/sites');
        $req->set_param('domain', 'tiktok.com');
        $req->set_param('list_type', 'blacklist');
        $req->set_param('applies_to', [1, 2]);

        $res = (new SiteController(new AlwaysAllowGate()))->create($req);
        self::assertSame('blacklist', $res->get_data()['listType']);
        self::assertSame([1, 2], $res->get_data()['appliesTo']);
    }

    public function testCreateReturns422WhenDomainEmpty(): void
    {
        $req = new WP_REST_Request('POST', '/sites');
        $req->set_param('domain', '');

        $res = (new SiteController(new AlwaysAllowGate()))->create($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(422, $res->get_error_data()['status']);
    }

    public function testCreateWhitelistNotifiesChildrenWithNormalizedDomain(): void
    {
        $this->wpdb->children = [1 => ['id' => 1, 'name' => 'Lucas']];

        $req = new WP_REST_Request('POST', '/sites');
        $req->set_param('domain', 'https://www.canva.com/design');
        $req->set_param('list_type', 'whitelist');

        (new SiteController(new AlwaysAllowGate()))->create($req);

        self::assertNotEmpty($this->wpdb->notifications);
        $last = $this->wpdb->notifications[array_key_last($this->wpdb->notifications)];
        self::assertSame('site_allowed', $last['type']);
        self::assertSame('Agora você pode acessar canva.com', $last['body']);
    }

    public function testCreateBlacklistDoesNotNotify(): void
    {
        $this->wpdb->children = [1 => ['id' => 1, 'name' => 'Lucas']];

        $req = new WP_REST_Request('POST', '/sites');
        $req->set_param('domain', 'tiktok.com');
        $req->set_param('list_type', 'blacklist');

        (new SiteController(new AlwaysAllowGate()))->create($req);

        self::assertEmpty($this->wpdb->notifications);
    }

    public function testDestroyDeletesAndReturnsConfirmation(): void
    {
        $this->wpdb->rows[3] = ['id' => 3, 'domain' => 'x.com'];

        $req = new WP_REST_Request('DELETE', '/sites/3');
        $req['id'] = 3;

        $res = (new SiteController(new AlwaysAllowGate()))->destroy($req);
        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertTrue($res->get_data()['deleted']);
        self::assertSame(3, $res->get_data()['id']);
    }
}
