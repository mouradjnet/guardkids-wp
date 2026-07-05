<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\ContentController;
use GuardKids\Api\Controllers\GamificationController;
use GuardKids\Auth\ChildAuth;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

final class GamificationControllerTest extends TestCase
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
                if (str_contains((string) $sql, 'COUNT(*)') && str_contains((string) $sql, 'mission_completions')) {
                    preg_match('/child_id = (\d+)/', (string) $sql, $mc);
                    $cid = (int) ($mc[1] ?? 0);
                    return (string) count(array_filter(
                        $this->t['mission_completions'] ?? [],
                        static fn ($r) => (int) ($r['child_id'] ?? 0) === $cid,
                    ));
                }
                if (str_contains((string) $sql, 'COUNT(*)') && str_contains((string) $sql, 'medal_unlocks')) {
                    preg_match('/child_id = (\d+)/', (string) $sql, $mc);
                    $cid = (int) ($mc[1] ?? 0);
                    return (string) count(array_filter(
                        $this->t['medal_unlocks'] ?? [],
                        static fn ($r) => (int) ($r['child_id'] ?? 0) === $cid,
                    ));
                }
                return null;
            }

            private function nameOf(string $sql): string
            {
                preg_match('/guardkids_([a-z_]+)/', $sql, $m);
                return $m[1] ?? '';
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
                $rows = array_values($this->t[$this->nameOf((string) $sql)] ?? []);
                foreach (['child_id', 'content_id'] as $col) {
                    if (preg_match("/{$col} = (\d+)/", (string) $sql, $m) === 1) {
                        $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r[$col] ?? 0) === (int) $m[1]));
                    }
                }
                if (preg_match("/award_date = '([^']+)'/", (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (string) ($r['award_date'] ?? '') === $m[1]));
                }
                return $rows;
            }

            public function get_row($sql, $output = OBJECT, $y = 0)
            {
                if (preg_match('/WHERE id = (\d+)/', (string) $sql, $m) === 1) {
                    return $this->t[$this->nameOf((string) $sql)][(int) $m[1]] ?? null;
                }
                return null;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->token = (new ChildAuth())->issueToken(1, 'tablet')['token'];
    }

    private function tokenReq(string $method, string $route): WP_REST_Request
    {
        $req = new WP_REST_Request($method, $route);
        $req->set_header('X-GuardKids-Token', $this->token);
        return $req;
    }

    public function testChildProgressionZeroWithoutWallet(): void
    {
        $res = (new GamificationController())->childProgression($this->tokenReq('GET', '/child/progression'));
        $data = $res->get_data();
        self::assertSame(0, $data['xp']);
        self::assertSame(1, $data['level']);
        self::assertSame(0, $data['streakDays']);
    }

    public function testChildProgression401WithoutToken(): void
    {
        $res = (new GamificationController())->childProgression(new WP_REST_Request('GET', '/child/progression'));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(401, $res->get_error_data()['status']);
    }

    public function testParentProgressionReflectsWallet(): void
    {
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 5, 'xp' => 150, 'coins' => 20, 'streak_days' => 3, 'last_activity_date' => '2026-07-02'],
        ];
        $req = new WP_REST_Request('GET', '/progression');
        $req->set_param('child_id', 5);
        $data = (new GamificationController())->progression($req)->get_data();
        self::assertSame(150, $data['xp']);
        self::assertSame(2, $data['level']);
        self::assertSame(3, $data['streakDays']);
        self::assertSame(0, $data['missionsCompleted']);
    }

    public function testParentProgressionCountsCompletedMissions(): void
    {
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 5, 'xp' => 150, 'coins' => 20, 'streak_days' => 3, 'last_activity_date' => '2026-07-02'],
        ];
        $this->wpdb->t['mission_completions'] = [
            1 => ['id' => 1, 'child_id' => 5, 'mission_key' => 'explore_3', 'completion_date' => '2026-07-02'],
            2 => ['id' => 2, 'child_id' => 5, 'mission_key' => 'streak_today', 'completion_date' => '2026-07-02'],
            3 => ['id' => 3, 'child_id' => 9, 'mission_key' => 'explore_3', 'completion_date' => '2026-07-02'],
        ];
        $req = new WP_REST_Request('GET', '/progression');
        $req->set_param('child_id', 5);
        $data = (new GamificationController())->progression($req)->get_data();
        self::assertSame(2, $data['missionsCompleted']);
    }

    public function testParentProgressionCountsUnlockedMedals(): void
    {
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 5, 'xp' => 150, 'coins' => 20, 'streak_days' => 3, 'last_activity_date' => '2026-07-02'],
        ];
        $this->wpdb->t['medal_unlocks'] = [
            1 => ['id' => 1, 'child_id' => 5, 'medal_key' => 'explorer_10', 'unlocked_date' => '2026-07-02'],
            2 => ['id' => 2, 'child_id' => 5, 'medal_key' => 'faithful_7', 'unlocked_date' => '2026-07-02'],
            3 => ['id' => 3, 'child_id' => 9, 'medal_key' => 'explorer_10', 'unlocked_date' => '2026-07-02'],
        ];
        $req = new WP_REST_Request('GET', '/progression');
        $req->set_param('child_id', 5);
        $data = (new GamificationController())->progression($req)->get_data();
        self::assertSame(2, $data['medalsUnlocked']);
    }

    public function testChildHistoryOpenCreditsProgression(): void
    {
        $this->wpdb->t['content_items'] = [10 => ['id' => 10, 'title' => 'X', 'status' => 'approved']];
        $req = $this->tokenReq('POST', '/child/library/history');
        $req->set_param('content_id', 10);
        $req->set_param('action', 'open');
        $req->set_param('duration_seconds', 0);
        (new ContentController())->childHistory($req);

        $wallet = array_values($this->wpdb->t['progression'] ?? []);
        self::assertNotEmpty($wallet);
        self::assertSame(10, (int) $wallet[0]['xp']);
    }
}
