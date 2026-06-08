<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\License;

use GuardKids\Api\Controllers\CategoryController;
use GuardKids\Api\Controllers\ReportsController;
use GuardKids\Api\Controllers\SafeZoneController;
use GuardKids\Api\Controllers\SiteController;
use GuardKids\License\Gate;
use GuardKids\License\Verifier;
use GuardKids\Tests\Support\AlwaysAllowGate;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Gating Free vs Premium nos demais controllers:
 *   - CategoryController.update  → categories
 *   - SiteController.create      → browser (apenas whitelist; blacklist é livre)
 *   - SafeZoneController.{create,update} → location
 *   - ReportsController.index    → full_history (month → degrada pra week no Free)
 */
final class MultiControllerGatingTest extends TestCase
{
    /** @var array{pubkey: string, signKey: string} */
    private array $keys;
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $this->keys = [
            'pubkey'  => sodium_crypto_sign_publickey($keypair),
            'signKey' => sodium_crypto_sign_secretkey($keypair),
        ];
        $GLOBALS['gk_options']            = [];
        $GLOBALS['gk_options']['siteurl'] = 'https://example.test';

        // wpdb permissivo — devolve qualquer row pra qualquer findById, aceita inserts.
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
                return (string) $query;
            }

            public function get_row($sql, $output = OBJECT, $y = 0)
            {
                return $this->rows[1] ?? null;
            }

            public function get_results($sql, $output = OBJECT)
            {
                return array_values($this->rows);
            }

            public function get_var($sql, $x = 0, $y = 0)
            {
                return null;
            }

            public function insert($table, $data, $format = null)
            {
                $this->insert_id            = (count($this->rows) + 1) ?: 1;
                $this->rows[$this->insert_id] = array_merge(['id' => $this->insert_id], $data);
                return 1;
            }

            public function update($table, $data, $where, $format = null, $where_format = null)
            {
                return 1;
            }

            public function delete($table, $where, $where_format = null)
            {
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    // CategoryController

    public function testFreeBlocksCategoryUpdate(): void
    {
        $this->wpdb->rows[1] = ['id' => 1, 'slug' => 'gambling', 'name' => 'Apostas'];

        $req = new WP_REST_Request('PATCH', '/categories/1');
        $req['id'] = 1;
        $req->set_param('blocked', true);

        $res = (new CategoryController(new Gate()))->update($req);

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('plan_limit', $res->get_error_code());
        self::assertSame(402, $res->get_error_data()['status']);
    }

    public function testPremiumAllowsCategoryUpdate(): void
    {
        $this->installPremium();
        $this->wpdb->rows[1] = ['id' => 1, 'slug' => 'gambling', 'name' => 'Apostas', 'blocked' => 0];

        $req = new WP_REST_Request('PATCH', '/categories/1');
        $req['id'] = 1;
        $req->set_param('blocked', true);

        $res = (new CategoryController($this->premiumGate()))->update($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
    }

    // SiteController

    public function testFreeBlocksWhitelistCreate(): void
    {
        $req = new WP_REST_Request('POST', '/sites');
        $req->set_param('domain', 'safesite.com');
        $req->set_param('list_type', 'whitelist');

        $res = (new SiteController(new Gate()))->create($req);

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('plan_limit', $res->get_error_code());
    }

    public function testFreeAllowsBlacklistCreate(): void
    {
        $req = new WP_REST_Request('POST', '/sites');
        $req->set_param('domain', 'blocksite.com');
        $req->set_param('list_type', 'blacklist');

        $res = (new SiteController(new Gate()))->create($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertSame(201, $res->get_status());
    }

    public function testFreeBlocksImplicitWhitelistWhenListTypeOmitted(): void
    {
        // Default é whitelist — também precisa bloquear
        $req = new WP_REST_Request('POST', '/sites');
        $req->set_param('domain', 'safesite.com');

        $res = (new SiteController(new Gate()))->create($req);

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('plan_limit', $res->get_error_code());
    }

    public function testPremiumAllowsWhitelistCreate(): void
    {
        $this->installPremium();

        $req = new WP_REST_Request('POST', '/sites');
        $req->set_param('domain', 'safesite.com');
        $req->set_param('list_type', 'whitelist');

        $res = (new SiteController($this->premiumGate()))->create($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertSame(201, $res->get_status());
    }

    // SafeZoneController

    public function testFreeBlocksSafeZoneCreate(): void
    {
        $req = new WP_REST_Request('POST', '/safe-zones');
        $req->set_param('name', 'Casa');
        $req->set_param('latitude', -23.5);
        $req->set_param('longitude', -46.6);
        $req->set_param('radius_meters', 100);

        $res = (new SafeZoneController(new Gate()))->create($req);

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('plan_limit', $res->get_error_code());
    }

    public function testFreeBlocksSafeZoneUpdate(): void
    {
        $this->wpdb->rows[1] = [
            'id' => 1, 'name' => 'Casa', 'latitude' => -23.5,
            'longitude' => -46.6, 'radius_meters' => 100,
        ];

        $req = new WP_REST_Request('PATCH', '/safe-zones/1');
        $req['id'] = 1;
        $req->set_param('name', 'Casa Renomeada');
        $req->set_param('latitude', -23.5);
        $req->set_param('longitude', -46.6);
        $req->set_param('radius_meters', 100);

        $res = (new SafeZoneController(new Gate()))->update($req);

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('plan_limit', $res->get_error_code());
    }

    public function testPremiumAllowsSafeZoneCreate(): void
    {
        $this->installPremium();

        $req = new WP_REST_Request('POST', '/safe-zones');
        $req->set_param('name', 'Casa');
        $req->set_param('latitude', -23.5);
        $req->set_param('longitude', -46.6);
        $req->set_param('radius_meters', 100);

        $res = (new SafeZoneController($this->premiumGate()))->create($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertSame(201, $res->get_status());
    }

    // ReportsController — degrade silencioso

    public function testFreeReportsMonthDegradesToWeek(): void
    {
        $req = new WP_REST_Request('GET', '/reports');
        $req->set_param('range', 'month');

        $res = (new ReportsController(new Gate()))->index($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertSame('week', $res->get_data()['range']);
    }

    public function testPremiumReportsAcceptsMonth(): void
    {
        $this->installPremium();

        $req = new WP_REST_Request('GET', '/reports');
        $req->set_param('range', 'month');

        $res = (new ReportsController($this->premiumGate()))->index($req);

        self::assertSame('month', $res->get_data()['range']);
    }

    public function testAlwaysAllowGateBypassesAll(): void
    {
        // Sanity check: AlwaysAllowGate (usado nos testes existentes)
        // não bloqueia nada
        $req = new WP_REST_Request('POST', '/safe-zones');
        $req->set_param('name', 'Casa');
        $req->set_param('latitude', -23.5);
        $req->set_param('longitude', -46.6);
        $req->set_param('radius_meters', 100);

        $res = (new SafeZoneController(new AlwaysAllowGate()))->create($req);
        self::assertInstanceOf(WP_REST_Response::class, $res);
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
