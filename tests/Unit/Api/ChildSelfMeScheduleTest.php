<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\ChildSelfController;
use GuardKids\Auth\ChildAuth;
use GuardKids\Auth\ChildPin;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * ChildSelfController::me() — verifica que a resposta inclui `schedule`
 * calculado pelo ScheduleEvaluator. Usa o evaluator real (puro, sem mock)
 * e um token real emitido por ChildAuth.
 *
 * wp_timezone() é stubada via eval() neste arquivo porque o bootstrap não
 * define a função e o controller chama-a pra montar o $now local.
 */
final class ChildSelfMeScheduleTest extends TestCase
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

    private function authedRequest(): WP_REST_Request
    {
        $req = new WP_REST_Request('GET', '/child/me');
        $req->set_header('X-GuardKids-Token', $this->validToken);
        return $req;
    }

    public function testMeIncludesScheduleFalseWhenAllAllowed(): void
    {
        $res = (new ChildSelfController())->me($this->authedRequest());

        self::assertInstanceOf(WP_REST_Response::class, $res);
        $data = $res->get_data();
        self::assertArrayHasKey('schedule', $data);
        self::assertFalse($data['schedule']['isBlocked']);
        self::assertNull($data['schedule']['reason']);
        self::assertNull($data['schedule']['unlockAt']);
    }

    public function testMeReportsBlockedByBedtime(): void
    {
        // Bedtime 00:00-23:59 cobre 23h59min de qualquer dia. O gap de 1min
        // antes da meia-noite só estoura num build no segundo errado — janela
        // suficientemente larga pra eliminar flakiness em CI.
        $this->wpdb->children[1]['bedtime_enabled'] = 1;
        $this->wpdb->children[1]['bedtime_start']   = '00:00:00';
        $this->wpdb->children[1]['bedtime_end']     = '23:59:00';

        $data = (new ChildSelfController())->me($this->authedRequest())->get_data();

        self::assertTrue($data['schedule']['isBlocked']);
        self::assertSame('bedtime', $data['schedule']['reason']);
        self::assertNotNull($data['schedule']['unlockAt']);
    }

    public function testMeReportsBlockedByWeekday(): void
    {
        // 'NNNNNNN' = bloqueado em qualquer dia, unlockAt=null (sem horizonte).
        $this->wpdb->children[1]['allowed_weekdays'] = 'NNNNNNN';

        $data = (new ChildSelfController())->me($this->authedRequest())->get_data();

        self::assertTrue($data['schedule']['isBlocked']);
        self::assertSame('weekday', $data['schedule']['reason']);
        self::assertNull($data['schedule']['unlockAt']);
    }

    public function testMeReportsBlockedByDailyLimitWhenUsageReachesCap(): void
    {
        // Toggle on, limite 60min, 60min de uso hoje (3600s) → bloqueia por limite.
        $this->wpdb->children[1]['daily_limit_enabled'] = 1;
        $this->wpdb->children[1]['limit_minutes']       = 60;
        $this->wpdb->usageSeconds                       = 3600;

        $data = (new ChildSelfController())->me($this->authedRequest())->get_data();

        self::assertTrue($data['schedule']['isBlocked']);
        self::assertSame('limit', $data['schedule']['reason']);
        self::assertNotNull($data['schedule']['unlockAt']);
    }

    public function testMePinUnlockDisabledByDefault(): void
    {
        $data = (new ChildSelfController())->me($this->authedRequest())->get_data();

        self::assertArrayHasKey('pinUnlockEnabled', $data);
        self::assertFalse($data['pinUnlockEnabled']);
    }

    public function testMePinUnlockEnabledWhenPinSet(): void
    {
        (new ChildPin())->set('1234');

        $data = (new ChildSelfController())->me($this->authedRequest())->get_data();

        self::assertTrue($data['pinUnlockEnabled']);
    }

    public function testMeNotBlockedWhenUsageUnderDailyLimit(): void
    {
        // Toggle on, limite 60min, só 30min de uso (1800s) → não bloqueia.
        $this->wpdb->children[1]['daily_limit_enabled'] = 1;
        $this->wpdb->children[1]['limit_minutes']       = 60;
        $this->wpdb->usageSeconds                       = 1800;

        $data = (new ChildSelfController())->me($this->authedRequest())->get_data();

        self::assertFalse($data['schedule']['isBlocked']);
        self::assertNull($data['schedule']['reason']);
    }
}
