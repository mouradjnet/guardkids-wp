<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\ChildSelfController;
use GuardKids\Auth\ChildAuth;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * ChildSelfController::me() — verifica o campo `avatarEmoji`: null por default
 * (sem carteira/avatar equipado) e o emoji do catálogo quando
 * progression.equipped_avatar aponta pra uma chave conhecida.
 *
 * Espelha o fake wpdb de ChildSelfMeScheduleTest (que já sustenta o me()
 * inteiro) e adiciona o suporte à tabela `progression` via get_results.
 */
final class ChildSelfControllerAvatarTest extends TestCase
{
    private \wpdb $wpdb;
    private string $validToken = '';

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<string, string> */
            public array $settings = [];
            /** @var array<int, array<string, mixed>> */
            public array $children = [];
            /** Tabelas extras (progression etc.) para get_results. */
            /** @var array<string, array<int, array<string, mixed>>> */
            public array $t = [];
            /** Segundos de uso retornados pela query de usage_events (SUM). */
            public int $usageSeconds = 0;

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
                if (str_contains((string) $sql, 'guardkids_usage_events')) {
                    return (string) $this->usageSeconds;
                }
                if (preg_match("/setting_key = '([^']+)'/", (string) $sql, $m) === 1) {
                    if (str_contains((string) $sql, 'SELECT id')) {
                        return isset($this->settings[$m[1]]) ? '1' : null;
                    }
                    return $this->settings[$m[1]] ?? null;
                }
                return null;
            }

            public function get_row($sql, $output = OBJECT, $y = 0)
            {
                if (str_contains((string) $sql, 'guardkids_children') &&
                    preg_match('/WHERE id = (\d+)/', (string) $sql, $m) === 1) {
                    return $this->children[(int) $m[1]] ?? null;
                }
                return null;
            }

            public function get_results($sql, $output = OBJECT)
            {
                if (str_contains((string) $sql, 'guardkids_progression')) {
                    $rows = array_values($this->t['progression'] ?? []);
                    if (preg_match('/child_id = (\d+)/', (string) $sql, $m) === 1) {
                        $cid  = (int) $m[1];
                        $rows = array_values(array_filter(
                            $rows,
                            static fn (array $r): bool => (int) ($r['child_id'] ?? 0) === $cid,
                        ));
                    }
                    return $rows;
                }
                return [];
            }

            public function insert($table, $data, $format = null)
            {
                if (str_contains((string) $table, 'guardkids_settings')) {
                    $this->settings[$data['setting_key']] = (string) $data['value'];
                    return 1;
                }
                return 0;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;

        // Stub wp_timezone APENAS pra este test — controller usa pra montar $now.
        if (! function_exists('wp_timezone')) {
            eval("function wp_timezone() { return new \\DateTimeZone('America/Sao_Paulo'); }");
        }

        $this->wpdb->children[1] = [
            'id' => 1, 'slug' => 'lucas', 'name' => 'Lucas',
            'status' => 'online', 'used_minutes' => 0, 'limit_minutes' => 60,
            'bedtime_enabled' => 0, 'bedtime_start' => null, 'bedtime_end' => null,
            'allowed_weekdays' => 'YYYYYYY',
        ];

        $issued           = (new ChildAuth())->issueToken(1, 'tablet');
        $this->validToken = $issued['token'];
    }

    private function meReq(): WP_REST_Request
    {
        $req = new WP_REST_Request('GET', '/child/me');
        $req->set_header('X-GuardKids-Token', $this->validToken);
        return $req;
    }

    public function testMeIncludesAvatarEmojiNullByDefault(): void
    {
        // sem progression seedada → avatarEmoji null
        $data = (new ChildSelfController())->me($this->meReq())->get_data();
        self::assertArrayHasKey('avatarEmoji', $data);
        self::assertNull($data['avatarEmoji']);
    }

    public function testMeIncludesEquippedEmoji(): void
    {
        $this->wpdb->t['progression'] = [
            1 => ['id' => 1, 'child_id' => 1, 'xp' => 0, 'coins' => 0, 'streak_days' => 0, 'equipped_avatar' => 'rocket'],
        ];
        $data = (new ChildSelfController())->me($this->meReq())->get_data();
        self::assertSame('🚀', $data['avatarEmoji']);
    }
}
