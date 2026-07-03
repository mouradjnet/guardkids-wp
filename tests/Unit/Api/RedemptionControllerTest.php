<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\RedemptionController;
use GuardKids\Auth\ChildAuth;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

final class RedemptionControllerTest extends TestCase
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

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                if (str_contains((string) $table, 'guardkids_settings')) {
                    return 1;
                }
                $n = $this->nameOf((string) $table);
                $id = (int) ($where['id'] ?? 0);
                if (isset($this->t[$n][$id])) {
                    $this->t[$n][$id] = array_merge($this->t[$n][$id], $data);
                    return 1;
                }
                return 0;
            }

            public function query($sql)
            {
                $sql = (string) $sql;
                if (! str_contains($sql, 'UPDATE') || ! str_contains($sql, 'guardkids_progression')) {
                    return 0;
                }
                preg_match('/coins = coins - (\d+)/', $sql, $mc);
                preg_match('/child_id = (\d+)/', $sql, $mChild);
                preg_match('/coins >= (\d+)/', $sql, $mMin);
                $amount = (int) ($mc[1] ?? 0);
                $childId = (int) ($mChild[1] ?? 0);
                $min = (int) ($mMin[1] ?? 0);
                foreach (($this->t['progression'] ?? []) as $id => $r) {
                    if ((int) $r['child_id'] === $childId && (int) $r['coins'] >= $min) {
                        $this->t['progression'][$id]['coins'] = (int) $r['coins'] - $amount;
                        return 1;
                    }
                }
                return 0;
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
                foreach (['child_id', 'reward_id'] as $col) {
                    if (preg_match("/{$col} = (\d+)/", (string) $sql, $m) === 1) {
                        $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r[$col] ?? 0) === (int) $m[1]));
                    }
                }
                if (preg_match("/status = '([^']+)'/", (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (string) ($r['status'] ?? '') === $m[1]));
                }
                return $rows;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->token = (new ChildAuth())->issueToken(1, 'tablet')['token'];
        $this->wpdb->t['rewards'] = [
            7 => ['id' => 7, 'title' => 'Sorvete', 'cost_coins' => 100, 'icon' => 'icecream', 'active' => 1],
        ];
        $this->wpdb->t['children'] = [
            1 => ['id' => 1, 'name' => 'Lucas'],
        ];
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 1, 'coins' => 250],
        ];
    }

    private function tokenPost(int $rewardId): WP_REST_Request
    {
        $req = new WP_REST_Request('POST', '/child/redemptions');
        $req->set_header('X-GuardKids-Token', $this->token);
        $req->set_param('rewardId', $rewardId);
        return $req;
    }

    private function adminReq(string $method, string $route, int $id): WP_REST_Request
    {
        $req = new WP_REST_Request($method, $route);
        $req->set_param('id', $id);
        return $req;
    }

    public function testChildCreateBlocksDuplicatePending(): void
    {
        $ctrl = new RedemptionController();
        self::assertSame(201, $ctrl->childCreate($this->tokenPost(7))->get_status());
        $res = $ctrl->childCreate($this->tokenPost(7));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(409, $res->get_error_data()['status']);
        self::assertSame('already_pending', $res->get_error_code());
    }

    public function testChildCreateBlocksInsufficientBalance(): void
    {
        $this->wpdb->t['progression'][1]['coins'] = 50; // < 100
        $res = (new RedemptionController())->childCreate($this->tokenPost(7));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(409, $res->get_error_data()['status']);
        self::assertSame('insufficient_funds', $res->get_error_code());
    }

    public function testApproveDeductsSnapshotAtomically(): void
    {
        $ctrl = new RedemptionController();
        $ctrl->childCreate($this->tokenPost(7)); // cria redemption id 1, cost 100
        $res = $ctrl->approve($this->adminReq('POST', '/redemptions/1/approve', 1));
        self::assertSame('approved', $res->get_data()['status']);
        self::assertSame(150, (int) $this->wpdb->t['progression'][1]['coins']); // 250 - 100
    }

    public function testApproveFailsWhenBalanceDropped(): void
    {
        $ctrl = new RedemptionController();
        $ctrl->childCreate($this->tokenPost(7)); // cost 100 snapshot
        $this->wpdb->t['progression'][1]['coins'] = 30; // caiu abaixo do custo
        $res = $ctrl->approve($this->adminReq('POST', '/redemptions/1/approve', 1));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('insufficient_funds', $res->get_error_code());
        self::assertSame('pending', $this->wpdb->t['reward_redemptions'][1]['status']); // continua pending
        self::assertSame(30, (int) $this->wpdb->t['progression'][1]['coins']); // intacto
    }

    public function testApproveAlreadyDecided(): void
    {
        $ctrl = new RedemptionController();
        $ctrl->childCreate($this->tokenPost(7));
        $ctrl->approve($this->adminReq('POST', '/redemptions/1/approve', 1));
        $res = $ctrl->approve($this->adminReq('POST', '/redemptions/1/approve', 1));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(409, $res->get_error_data()['status']);
    }

    public function testDenyDoesNotSpend(): void
    {
        $ctrl = new RedemptionController();
        $ctrl->childCreate($this->tokenPost(7));
        $res = $ctrl->deny($this->adminReq('POST', '/redemptions/1/deny', 1));
        self::assertSame('denied', $res->get_data()['status']);
        self::assertSame(250, (int) $this->wpdb->t['progression'][1]['coins']); // nada saiu
    }
}
