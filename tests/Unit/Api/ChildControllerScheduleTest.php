<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\ChildController;
use GuardKids\Tests\Support\AlwaysAllowGate;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * ChildController — args + validação do schedule (Fase 8).
 *
 * Mesma estrutura de fake $wpdb que ChildControllerTest, com inspeção
 * do log['update'] pra verificar o que foi persistido.
 */
final class ChildControllerScheduleTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];
            /** @var array<int, array{method:string, args:array}> */
            public array $log = [];

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

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                $this->log[] = ['method' => 'update', 'args' => [$table, $data, $where]];
                $id = (int) ($where['id'] ?? 0);
                if (isset($this->rows[$id])) {
                    $this->rows[$id] = array_merge($this->rows[$id], $data);
                }
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->wpdb->rows[1] = [
            'id' => 1, 'slug' => 'lucas', 'name' => 'Lucas',
            'status' => 'online', 'used_minutes' => 0, 'limit_minutes' => 60,
            'bedtime_enabled' => 0, 'bedtime_start' => null, 'bedtime_end' => null,
            'allowed_weekdays' => 'YYYYYYY',
        ];
    }

    private function makeRequest(array $params): WP_REST_Request
    {
        $req = new WP_REST_Request('PATCH', '/children/1');
        $req['id'] = 1;
        foreach ($params as $k => $v) {
            $req->set_param($k, $v);
        }
        return $req;
    }

    public function testUpdateAllowedWeekdaysPersists(): void
    {
        $req = $this->makeRequest(['allowed_weekdays' => 'YYYYYNN']);
        $res = (new ChildController(new AlwaysAllowGate()))->update($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        $update = array_filter($this->wpdb->log, fn ($e) => $e['method'] === 'update');
        self::assertNotEmpty($update);
        $patch = array_values($update)[0]['args'][1];
        self::assertSame('YYYYYNN', $patch['allowed_weekdays']);
    }

    public function testUpdateBedtimeFieldsPersist(): void
    {
        $req = $this->makeRequest([
            'bedtime_enabled' => true,
            'bedtime_start'   => '21:30',
            'bedtime_end'     => '07:00',
        ]);
        $res = (new ChildController(new AlwaysAllowGate()))->update($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        $patch = end($this->wpdb->log)['args'][1];
        self::assertSame(1, $patch['bedtime_enabled']);
        self::assertSame('21:30:00', $patch['bedtime_start']);
        self::assertSame('07:00:00', $patch['bedtime_end']);
    }

    public function testUpdateBedtimeEnabledTrueWithoutStartReturns422(): void
    {
        $req = $this->makeRequest(['bedtime_enabled' => true]);
        $res = (new ChildController(new AlwaysAllowGate()))->update($req);

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(422, $res->get_error_data()['status']);
        self::assertSame('invalid_payload', $res->get_error_code());
    }

    public function testUpdateBedtimeEnabledTrueWithExistingStartEndIsAllowed(): void
    {
        // Row já tem start/end persistidos
        $this->wpdb->rows[1]['bedtime_start'] = '21:00:00';
        $this->wpdb->rows[1]['bedtime_end']   = '06:00:00';

        $req = $this->makeRequest(['bedtime_enabled' => true]);
        $res = (new ChildController(new AlwaysAllowGate()))->update($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
    }

    public function testUpdatePartialDoesNotTouchOtherFields(): void
    {
        $req = $this->makeRequest(['allowed_weekdays' => 'YYYYYNN']);
        (new ChildController(new AlwaysAllowGate()))->update($req);

        $patch = end($this->wpdb->log)['args'][1];
        self::assertArrayNotHasKey('bedtime_enabled', $patch);
        self::assertArrayNotHasKey('bedtime_start',   $patch);
        self::assertArrayNotHasKey('bedtime_end',     $patch);
        self::assertArrayNotHasKey('limit_minutes',   $patch);
    }

    public function testToJsonIncludesScheduleFields(): void
    {
        $this->wpdb->rows[1]['bedtime_enabled']  = 1;
        $this->wpdb->rows[1]['bedtime_start']    = '21:30:00';
        $this->wpdb->rows[1]['bedtime_end']      = '07:00:00';
        $this->wpdb->rows[1]['allowed_weekdays'] = 'YYYYYNN';

        $req = new WP_REST_Request('GET', '/children/1');
        $req['id'] = 1;
        $res = (new ChildController(new AlwaysAllowGate()))->show($req);

        $data = $res->get_data();
        self::assertTrue($data['bedtimeEnabled']);
        self::assertSame('21:30', $data['bedtimeStart']);
        self::assertSame('07:00', $data['bedtimeEnd']);
        self::assertSame('YYYYYNN', $data['allowedWeekdays']);
    }

    public function testUpdateBedtimeEnabledFalsePersistsZeroAndDoesNotRequireTimes(): void
    {
        // Flow primário de UI: desligar bedtime. Não exige start/end.
        $this->wpdb->rows[1]['bedtime_enabled'] = 1;
        $this->wpdb->rows[1]['bedtime_start']   = '21:00:00';
        $this->wpdb->rows[1]['bedtime_end']     = '07:00:00';

        $req = $this->makeRequest(['bedtime_enabled' => false]);
        $res = (new ChildController(new AlwaysAllowGate()))->update($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        $patch = end($this->wpdb->log)['args'][1];
        self::assertSame(0, $patch['bedtime_enabled']);
        self::assertArrayNotHasKey('bedtime_start', $patch);
        self::assertArrayNotHasKey('bedtime_end', $patch);
    }

    public function testUpdateBedtimeEnabledTrueWithStartButNoEndReturns422(): void
    {
        // Spec: enabled=true exige AMBOS start e end (request OU row).
        // Row vazio + só start no request → 422.
        $req = $this->makeRequest([
            'bedtime_enabled' => true,
            'bedtime_start'   => '21:30',
        ]);
        $res = (new ChildController(new AlwaysAllowGate()))->update($req);

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(422, $res->get_error_data()['status']);
    }

    public function testUpdateDailyLimitEnabledPersists(): void
    {
        $req = $this->makeRequest(['daily_limit_enabled' => true]);
        $res = (new ChildController(new AlwaysAllowGate()))->update($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        $patch = end($this->wpdb->log)['args'][1];
        self::assertSame(1, $patch['daily_limit_enabled']);
    }

    public function testUpdateDailyLimitEnabledIsNotPremiumGated(): void
    {
        // Gate que nega tudo (Free sem schedule). daily_limit_enabled NÃO entra
        // em touchesSchedule, então ligar o toggle não pode retornar 402.
        $denyGate = new class () extends \GuardKids\License\Gate {
            public function __construct()
            {
            }
            public function can(string $featureId): bool
            {
                return false;
            }
        };

        $req = $this->makeRequest(['daily_limit_enabled' => true]);
        $res = (new ChildController($denyGate))->update($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        $patch = end($this->wpdb->log)['args'][1];
        self::assertSame(1, $patch['daily_limit_enabled']);
    }

    public function testToJsonIncludesDailyLimitEnabled(): void
    {
        $this->wpdb->rows[1]['daily_limit_enabled'] = 1;

        $req = new WP_REST_Request('GET', '/children/1');
        $req['id'] = 1;
        $res = (new ChildController(new AlwaysAllowGate()))->show($req);

        self::assertTrue($res->get_data()['dailyLimitEnabled']);
    }

    public function testToJsonReturnsDefaultsWhenScheduleFieldsAreEmpty(): void
    {
        // Row sem bedtime configurado nem allowed_weekdays setado.
        // Verifica que toJson devolve defaults sensatos.
        $this->wpdb->rows[1]['bedtime_enabled']  = 0;
        $this->wpdb->rows[1]['bedtime_start']    = null;
        $this->wpdb->rows[1]['bedtime_end']      = null;
        // Mantém allowed_weekdays padrão de setUp() = 'YYYYYYY'

        $req = new WP_REST_Request('GET', '/children/1');
        $req['id'] = 1;
        $res = (new ChildController(new AlwaysAllowGate()))->show($req);

        $data = $res->get_data();
        self::assertFalse($data['bedtimeEnabled']);
        self::assertNull($data['bedtimeStart']);
        self::assertNull($data['bedtimeEnd']);
        self::assertSame('YYYYYYY', $data['allowedWeekdays']);
    }
}
