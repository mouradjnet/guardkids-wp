<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\AvatarController;
use GuardKids\Auth\ChildAuth;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

final class AvatarControllerTest extends TestCase
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
                }
                return 1;
            }

            public function get_results($sql, $output = OBJECT)
            {
                $n = $this->nameOf((string) $sql);
                $rows = array_values($this->t[$n] ?? []);
                if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                    $rows = array_values(array_filter($rows, static fn ($r) => (int) ($r['child_id'] ?? 0) === (int) $m[1]));
                }
                return $rows;
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

    /** @param array<int,array<string,mixed>> $avatars */
    private function pick(array $avatars, string $key): array
    {
        return array_values(array_filter($avatars, static fn ($a) => $a['key'] === $key))[0];
    }

    public function testListReturns401WithoutToken(): void
    {
        $res = (new AvatarController())->childAvatars(new WP_REST_Request('GET', '/child/avatars'));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(401, $res->get_error_data()['status']);
    }

    public function testListDefaultsToStarAndComputesUnlocked(): void
    {
        $data = (new AvatarController())->childAvatars($this->tokenReq('GET', '/child/avatars'))->get_data();
        self::assertSame('star', $data['equipped']);
        self::assertTrue($this->pick($data['avatars'], 'star')['isEquipped']);
        self::assertTrue($this->pick($data['avatars'], 'star')['unlocked']);
        self::assertFalse($this->pick($data['avatars'], 'rocket')['unlocked']);
    }

    public function testEquipUnlockedPersists(): void
    {
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 1, 'xp' => 5000, 'coins' => 0, 'streak_days' => 0, 'equipped_avatar' => null],
        ];
        $req = $this->tokenReq('POST', '/child/avatar');
        $req->set_param('avatarKey', 'rocket');
        $data = (new AvatarController())->equip($req)->get_data();
        self::assertSame('rocket', $data['equipped']);
        self::assertSame('rocket', $this->wpdb->t['progression'][1]['equipped_avatar']);
    }

    public function testEquipLockedIsRejected(): void
    {
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 1, 'xp' => 0, 'coins' => 0, 'streak_days' => 0, 'equipped_avatar' => null],
        ];
        $req = $this->tokenReq('POST', '/child/avatar');
        $req->set_param('avatarKey', 'rocket');
        $res = (new AvatarController())->equip($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(409, $res->get_error_data()['status']);
        self::assertSame('avatar_locked', $res->get_error_code());
    }

    public function testEquipUnknownKeyIs404(): void
    {
        $req = $this->tokenReq('POST', '/child/avatar');
        $req->set_param('avatarKey', 'dragon');
        $res = (new AvatarController())->equip($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(404, $res->get_error_data()['status']);
    }
}
