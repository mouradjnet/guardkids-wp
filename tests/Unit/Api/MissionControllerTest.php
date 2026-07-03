<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\MissionController;
use GuardKids\Auth\ChildAuth;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

final class MissionControllerTest extends TestCase
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
            // sinais seedados p/ o controller (independem de SQL real)
            public int $sigOpened = 0;
            public int $sigCategories = 0;
            public ?string $sigLastDate = null;

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
                $sql = (string) $sql;
                if (preg_match("/setting_key = '([^']+)'/", $sql, $m) === 1) {
                    if (str_contains($sql, 'SELECT id')) {
                        return isset($this->settings[$m[1]]) ? '1' : null;
                    }
                    return $this->settings[$m[1]] ?? null;
                }
                if (str_contains($sql, 'COUNT(*)') && str_contains($sql, 'progression_awards')) {
                    return (string) $this->sigOpened;
                }
                if (str_contains($sql, 'COUNT(DISTINCT')) {
                    return (string) $this->sigCategories;
                }
                if (str_contains($sql, 'last_activity_date') && str_contains($sql, 'SELECT last_activity_date')) {
                    return $this->sigLastDate;
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
                }
                return 1;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $sql = (string) $sql;
                $n = $this->nameOf($sql);
                $rows = array_values($this->t[$n] ?? []);
                if (preg_match('/child_id = (\d+)/', $sql, $mm) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['child_id'] ?? 0) === (int) $mm[1]));
                }
                if (preg_match("/mission_key = '([^']+)'/", $sql, $mm) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (string) ($r['mission_key'] ?? '') === $mm[1]));
                }
                if (preg_match("/completion_date = '([^']+)'/", $sql, $mm) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (string) ($r['completion_date'] ?? '') === $mm[1]));
                }
                return $rows;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->token = (new ChildAuth())->issueToken(1, 'tablet')['token'];
    }

    private function tokenReq(): WP_REST_Request
    {
        $req = new WP_REST_Request('GET', '/child/missions');
        $req->set_header('X-GuardKids-Token', $this->token);
        return $req;
    }

    public function testReturns401WithoutToken(): void
    {
        $res = (new MissionController())->childMissions(new WP_REST_Request('GET', '/child/missions'));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(401, $res->get_error_data()['status']);
    }

    public function testReturnsThreeMissionsWithProgress(): void
    {
        $this->wpdb->sigOpened = 1;
        $data = (new MissionController())->childMissions($this->tokenReq())->get_data();
        self::assertCount(3, $data);
        $explore = array_values(array_filter($data, static fn ($m) => $m['key'] === 'explore_3'))[0];
        self::assertSame(1, $explore['progress']);
        self::assertFalse($explore['completed']);
    }

    public function testCreditsBonusOnceAndIsIdempotent(): void
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        // streak_today completa: last_activity_date == hoje
        $this->wpdb->sigLastDate = $today;
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 1, 'xp' => 0, 'coins' => 0, 'streak_days' => 1, 'last_activity_date' => $today],
        ];

        $ctrl = new MissionController();
        $first = $ctrl->childMissions($this->tokenReq())->get_data();
        $streak = array_values(array_filter($first, static fn ($m) => $m['key'] === 'streak_today'))[0];
        self::assertTrue($streak['completed']);
        self::assertTrue($streak['justCompleted']);

        // 1 linha no ledger, bônus creditado 1x (10 XP / 5 coins)
        self::assertCount(1, $this->wpdb->t['mission_completions'] ?? []);
        self::assertSame(10, (int) array_values($this->wpdb->t['progression'])[0]['xp']);
        self::assertSame(5, (int) array_values($this->wpdb->t['progression'])[0]['coins']);

        // segunda chamada no mesmo dia não credita de novo
        $second = $ctrl->childMissions($this->tokenReq())->get_data();
        $streak2 = array_values(array_filter($second, static fn ($m) => $m['key'] === 'streak_today'))[0];
        self::assertFalse($streak2['justCompleted']);
        self::assertCount(1, $this->wpdb->t['mission_completions'] ?? []);
        self::assertSame(10, (int) array_values($this->wpdb->t['progression'])[0]['xp']);
    }

    public function testIncompleteMissionDoesNotCredit(): void
    {
        $this->wpdb->sigOpened = 1; // explore_3 alvo 3 → incompleto
        (new MissionController())->childMissions($this->tokenReq());
        self::assertArrayNotHasKey('mission_completions', $this->wpdb->t);
    }
}
