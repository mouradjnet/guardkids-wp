<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\MedalController;
use GuardKids\Auth\ChildAuth;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

final class MedalControllerTest extends TestCase
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
            public int $sigOpened = 0;
            public int $sigMissions = 0;
            public int $sigCategories = 0;

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
                if (str_contains($sql, 'COUNT(DISTINCT')) {
                    return (string) $this->sigCategories;
                }
                if (str_contains($sql, 'COUNT(*)') && str_contains($sql, 'progression_awards')) {
                    return (string) $this->sigOpened;
                }
                if (str_contains($sql, 'COUNT(*)') && str_contains($sql, 'mission_completions')) {
                    return (string) $this->sigMissions;
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
                if (preg_match("/medal_key = '([^']+)'/", $sql, $mm) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (string) ($r['medal_key'] ?? '') === $mm[1]));
                }
                return $rows;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->token = (new ChildAuth())->issueToken(1, 'tablet')['token'];
    }

    private function tokenReq(): WP_REST_Request
    {
        $req = new WP_REST_Request('GET', '/child/medals');
        $req->set_header('X-GuardKids-Token', $this->token);
        return $req;
    }

    /** @param array<int,array<string,mixed>> $data */
    private function medal(array $data, string $key): array
    {
        return array_values(array_filter($data, static fn ($m) => $m['key'] === $key))[0];
    }

    public function testReturns401WithoutToken(): void
    {
        $res = (new MedalController())->childMedals(new WP_REST_Request('GET', '/child/medals'));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(401, $res->get_error_data()['status']);
    }

    public function testReturnsSixMedalsWithProgress(): void
    {
        $this->wpdb->sigOpened = 5;
        $data = (new MedalController())->childMedals($this->tokenReq())->get_data();
        self::assertCount(6, $data);
        self::assertSame(5, $this->medal($data, 'explorer_10')['progress']);
        self::assertFalse($this->medal($data, 'explorer_10')['unlocked']);
        self::assertArrayNotHasKey('medal_unlocks', $this->wpdb->t);
    }

    public function testUnlocksMedalOnceAndIsIdempotent(): void
    {
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 1, 'xp' => 0, 'coins' => 0, 'streak_days' => 7, 'last_activity_date' => '2026-07-03'],
        ];

        $ctrl = new MedalController();
        $first = $ctrl->childMedals($this->tokenReq())->get_data();
        $faithful = $this->medal($first, 'faithful_7');
        self::assertTrue($faithful['unlocked']);
        self::assertTrue($faithful['justUnlocked']);

        self::assertCount(1, $this->wpdb->t['medal_unlocks'] ?? []);
        self::assertSame(40, (int) array_values($this->wpdb->t['progression'])[0]['xp']);
        self::assertSame(25, (int) array_values($this->wpdb->t['progression'])[0]['coins']);

        $second = $ctrl->childMedals($this->tokenReq())->get_data();
        self::assertFalse($this->medal($second, 'faithful_7')['justUnlocked']);
        self::assertCount(1, $this->wpdb->t['medal_unlocks'] ?? []);
        self::assertSame(40, (int) array_values($this->wpdb->t['progression'])[0]['xp']);
    }

    public function testPermanenceWhenSignalDrops(): void
    {
        $this->wpdb->t['medal_unlocks'] = [
            1 => ['id' => 1, 'child_id' => 1, 'medal_key' => 'faithful_7', 'unlocked_date' => '2026-07-01', 'xp' => 40, 'coins' => 25],
        ];
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 1, 'xp' => 40, 'coins' => 25, 'streak_days' => 1, 'last_activity_date' => '2026-07-03'],
        ];

        $data = (new MedalController())->childMedals($this->tokenReq())->get_data();
        $faithful = $this->medal($data, 'faithful_7');
        self::assertTrue($faithful['unlocked']);
        self::assertFalse($faithful['justUnlocked']);
        self::assertCount(1, $this->wpdb->t['medal_unlocks'] ?? []);
        self::assertSame(40, (int) array_values($this->wpdb->t['progression'])[0]['xp']);
    }
}
