<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\RewardController;
use GuardKids\Auth\ChildAuth;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

final class RewardControllerTest extends TestCase
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
                return null;
            }

            private function nameOf(string $sql): string
            {
                preg_match_all('/guardkids_([a-z_]+)/', $sql, $m);
                return end($m[1]) ?: '';
            }

            public function insert($table, $data, $format = null)
            {
                if (str_contains((string) $table, 'guardkids_settings')) {
                    $this->settings[(string) $data['setting_key']] = (string) $data['value'];
                    return 1;
                }
                $n = $this->nameOf((string) $table);
                $this->t[$n] ??= [];
                $id = count($this->t[$n]) + 1;
                $this->insert_id = $id;
                $this->t[$n][$id] = array_merge(['id' => $id], $data);
                return 1;
            }

            public function get_row($sql, $output = OBJECT, $y = 0)
            {
                $n = $this->nameOf((string) $sql);
                if (preg_match('/id = (\d+)/', (string) $sql, $m) === 1) {
                    return $this->t[$n][(int) $m[1]] ?? null;
                }
                return null;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $n = $this->nameOf((string) $sql);
                $rows = array_values($this->t[$n] ?? []);
                if (preg_match('/active = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['active'] ?? 0) === (int) $m[1]));
                }
                if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['child_id'] ?? 0) === (int) $m[1]));
                }
                return $rows;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->token = (new ChildAuth())->issueToken(1, 'tablet')['token'];
    }

    private function tokenReq(string $route): WP_REST_Request
    {
        $req = new WP_REST_Request('GET', $route);
        $req->set_header('X-GuardKids-Token', $this->token);
        return $req;
    }

    public function testCreateValidatesTitleAndCost(): void
    {
        $ctrl = new RewardController();
        $bad = new WP_REST_Request('POST', '/rewards');
        $bad->set_param('title', '');
        $bad->set_param('costCoins', 100);
        $res = $ctrl->create($bad);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(422, $res->get_error_data()['status']);
    }

    public function testCreatePersists(): void
    {
        $ctrl = new RewardController();
        $req = new WP_REST_Request('POST', '/rewards');
        $req->set_param('title', 'Sorvete');
        $req->set_param('costCoins', 100);
        $req->set_param('icon', 'icecream');
        $res = $ctrl->create($req);
        $data = $res->get_data();
        self::assertSame('Sorvete', $data['title']);
        self::assertSame(100, $data['costCoins']);
        self::assertTrue($data['active']);
    }

    public function testChildRewardsReturnsActivePlusBalance(): void
    {
        $this->wpdb->t['rewards'] = [
            1 => ['id' => 1, 'title' => 'Sorvete', 'cost_coins' => 100, 'icon' => 'icecream', 'active' => 1],
            2 => ['id' => 2, 'title' => 'Off', 'cost_coins' => 10, 'icon' => null, 'active' => 0],
        ];
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 1, 'coins' => 250],
        ];
        $data = (new RewardController())->childRewards($this->tokenReq('/child/rewards'))->get_data();
        self::assertSame(250, $data['balance']);
        self::assertCount(1, $data['rewards']);
        self::assertSame('Sorvete', $data['rewards'][0]['title']);
    }

    public function testChildRewards401WithoutToken(): void
    {
        $res = (new RewardController())->childRewards(new WP_REST_Request('GET', '/child/rewards'));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(401, $res->get_error_data()['status']);
    }
}
