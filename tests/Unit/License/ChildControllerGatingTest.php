<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\License;

use GuardKids\Api\Controllers\ChildController;
use GuardKids\License\Gate;
use GuardKids\License\Verifier;
use GuardKids\Tests\Support\AlwaysAllowGate;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Comportamento de gating no ChildController:
 *   - Free: cap em 1 filho (unlimited_kids) + sem schedule
 *   - Premium ativa: ilimitado + schedule liberado
 */
final class ChildControllerGatingTest extends TestCase
{
    /** @var array{pubkey: string, signKey: string} */
    private array $keys;

    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $keypair          = sodium_crypto_sign_keypair();
        $this->keys       = [
            'pubkey'  => sodium_crypto_sign_publickey($keypair),
            'signKey' => sodium_crypto_sign_secretkey($keypair),
        ];
        $GLOBALS['gk_options']            = [];
        $GLOBALS['gk_options']['siteurl'] = 'https://example.test';

        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            public int $insert_id = 0;
            /** @var array<int, array<string, mixed>> */
            public array $rows = [];

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

            public function get_results($sql, $output = OBJECT)
            {
                return array_values($this->rows);
            }

            public function get_row($sql, $output = OBJECT, $y = 0)
            {
                if (preg_match('/WHERE id = (\d+)/', (string) $sql, $m) === 1) {
                    return $this->rows[(int) $m[1]] ?? null;
                }
                return null;
            }

            public function insert($table, $data, $format = null)
            {
                $this->insert_id            = count($this->rows) + 1;
                $this->rows[$this->insert_id] = array_merge(['id' => $this->insert_id], $data);
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                $id = (int) ($where['id'] ?? 0);
                if (isset($this->rows[$id])) {
                    $this->rows[$id] = array_merge($this->rows[$id], $data);
                }
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testFreeAllowsFirstChild(): void
    {
        $req = new WP_REST_Request('POST', '/children');
        $req->set_param('name', 'Lucas');

        $res = (new ChildController(new Gate()))->create($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
    }

    public function testFreeBlocksSecondChildWith402(): void
    {
        // Pré-popula 1 filho
        $this->wpdb->rows[1] = ['id' => 1, 'name' => 'Lucas', 'slug' => 'lucas'];

        $req = new WP_REST_Request('POST', '/children');
        $req->set_param('name', 'Sofia');

        $res = (new ChildController(new Gate()))->create($req);

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('plan_limit', $res->get_error_code());
        self::assertSame(402, $res->get_error_data()['status']);
    }

    public function testFreeBlocksScheduleFieldsOnCreate(): void
    {
        $req = new WP_REST_Request('POST', '/children');
        $req->set_param('name', 'Lucas');
        $req->set_param('bedtime_enabled', true);

        $res = (new ChildController(new Gate()))->create($req);

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('plan_limit', $res->get_error_code());
        self::assertSame(402, $res->get_error_data()['status']);
    }

    public function testFreeBlocksScheduleFieldsOnUpdate(): void
    {
        $this->wpdb->rows[1] = [
            'id' => 1, 'name' => 'Lucas', 'slug' => 'lucas',
            'bedtime_enabled' => 0, 'bedtime_start' => null, 'bedtime_end' => null,
            'allowed_weekdays' => 'YYYYYYY',
        ];

        $req = new WP_REST_Request('PATCH', '/children/1');
        $req['id'] = 1;
        $req->set_param('allowed_weekdays', 'YYYYYNN');

        $res = (new ChildController(new Gate()))->update($req);

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('plan_limit', $res->get_error_code());
    }

    public function testFreeAllowsUpdateOfNonScheduleFields(): void
    {
        $this->wpdb->rows[1] = [
            'id' => 1, 'name' => 'Lucas', 'slug' => 'lucas',
            'bedtime_enabled' => 0, 'allowed_weekdays' => 'YYYYYYY',
        ];

        $req = new WP_REST_Request('PATCH', '/children/1');
        $req['id'] = 1;
        $req->set_param('limit_minutes', 90);

        $res = (new ChildController(new Gate()))->update($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
    }

    public function testPremiumActiveAllowsMultipleChildren(): void
    {
        $this->installPremium();
        $this->wpdb->rows[1] = ['id' => 1, 'name' => 'Lucas'];

        $req = new WP_REST_Request('POST', '/children');
        $req->set_param('name', 'Sofia');

        $res = (new ChildController($this->premiumGate()))->create($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
    }

    public function testPremiumActiveAllowsScheduleFields(): void
    {
        $this->installPremium();
        $this->wpdb->rows[1] = [
            'id' => 1, 'name' => 'Lucas', 'slug' => 'lucas',
            'bedtime_enabled' => 0, 'allowed_weekdays' => 'YYYYYYY',
        ];

        $req = new WP_REST_Request('PATCH', '/children/1');
        $req['id'] = 1;
        $req->set_param('allowed_weekdays', 'YYYYYNN');

        $res = (new ChildController($this->premiumGate()))->update($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
    }

    public function testAlwaysAllowGateSkipsAllLimits(): void
    {
        // Sem licença instalada, mas com gate forçado a permitir
        $this->wpdb->rows[1] = ['id' => 1, 'name' => 'Lucas'];

        $req = new WP_REST_Request('POST', '/children');
        $req->set_param('name', 'Sofia');
        $req->set_param('bedtime_enabled', true);

        $res = (new ChildController(new AlwaysAllowGate()))->create($req);

        // AlwaysAllowGate libera unlimited_kids E schedule.
        // Nome obrigatório está OK, vai chegar no insert. 201 ou outro 4xx
        // (mas não 402).
        if ($res instanceof WP_Error) {
            self::assertNotSame(402, $res->get_error_data()['status']);
        } else {
            self::assertInstanceOf(WP_REST_Response::class, $res);
        }
    }

    private function premiumGate(): Gate
    {
        return new Gate(new Verifier(base64_encode($this->keys['pubkey'])));
    }

    private function installPremium(): void
    {
        $payload = [
            'iss'      => 'guardkids',
            'sub'      => 'https://example.test',
            'jti'      => '01HJTEST',
            'iat'      => time(),
            'exp'      => time() + 86_400 * 365,
            'plan'     => 'premium',
            'features' => Gate::PREMIUM_FEATURES,
            'email'    => 'djair@example.test',
        ];
        $json      = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $b64       = self::b64url($json);
        $signature = sodium_crypto_sign_detached($b64, $this->keys['signKey']);
        $key       = $b64 . '.' . self::b64url($signature);
        $GLOBALS['gk_options']['guardkids_license'] = [
            'key_b64'      => $key,
            'activated_at' => '2026-06-08 14:00:00',
        ];
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
