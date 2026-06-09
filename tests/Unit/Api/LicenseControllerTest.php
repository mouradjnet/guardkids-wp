<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\LicenseController;
use GuardKids\Database\SettingsRepository;
use GuardKids\License\Gate;
use GuardKids\License\Verifier;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class LicenseControllerTest extends TestCase
{
    /** @var array{pubkey: string, signKey: string} */
    private array $keys;

    protected function setUp(): void
    {
        $keypair          = sodium_crypto_sign_keypair();
        $this->keys       = [
            'pubkey'  => sodium_crypto_sign_publickey($keypair),
            'signKey' => sodium_crypto_sign_secretkey($keypair),
        ];
        $GLOBALS['gk_options']            = [];
        $GLOBALS['gk_options']['siteurl'] = 'https://example.test';
        // SettingsRepository default: wpdb stub vazio (get_var → null → cai no $default)
        $GLOBALS['wpdb'] = new \wpdb();
    }

    public function testIndexReturnsNoneSnapshotWithoutLicense(): void
    {
        $res = $this->controller()->index();

        self::assertInstanceOf(WP_REST_Response::class, $res);
        $data = $res->get_data();
        self::assertSame('free', $data['plan']);
        self::assertSame('none', $data['status']);
        self::assertSame([], $data['features']);
        self::assertNull($data['expiresAt']);
        self::assertNull($data['daysLeft']);
        self::assertNull($data['email']);
        self::assertNull($data['activatedAt']);
        self::assertNull($data['upgradeUrl']);
    }

    public function testIndexReturnsUpgradeUrlWhenSettingPresent(): void
    {
        $settings = $this->settingsWith(['upgrade_url' => 'https://comprar.exemplo.com/premium']);

        $res = $this->controller($settings)->index();
        self::assertSame(
            'https://comprar.exemplo.com/premium',
            $res->get_data()['upgradeUrl'],
        );
    }

    public function testActivateRejectsEmptyKey(): void
    {
        $req = new WP_REST_Request('POST', '/license');
        $req->set_param('key', '');

        $res = $this->controller()->activate($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('invalid_payload', $res->get_error_code());
        self::assertSame(422, $res->get_error_data()['status']);
    }

    public function testActivateRejectsMalformedKey(): void
    {
        $req = new WP_REST_Request('POST', '/license');
        $req->set_param('key', 'lixo.malformado');

        $res = $this->controller()->activate($req);
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('invalid_license', $res->get_error_code());
        self::assertArrayNotHasKey('guardkids_license', $GLOBALS['gk_options']);
    }

    public function testActivatePersistsAndReturnsActiveSnapshot(): void
    {
        $key = $this->signKey($this->basePayload());
        $req = new WP_REST_Request('POST', '/license');
        $req->set_param('key', $key);

        $res = $this->controller()->activate($req);

        self::assertInstanceOf(WP_REST_Response::class, $res);
        $data = $res->get_data();
        self::assertSame('premium', $data['plan']);
        self::assertSame('active', $data['status']);
        self::assertContains('browser', $data['features']);
        self::assertNotNull($data['expiresAt']);
        self::assertGreaterThan(0, $data['daysLeft']);
        self::assertSame('djair@example.test', $data['email']);
        self::assertNotNull($data['activatedAt']);

        self::assertArrayHasKey('guardkids_license', $GLOBALS['gk_options']);
        self::assertSame($key, $GLOBALS['gk_options']['guardkids_license']['key_b64']);
    }

    public function testActivateRollsBackWhenDomainDoesNotMatch(): void
    {
        $payload        = $this->basePayload();
        $payload['sub'] = 'https://outro-dominio.com';
        $key            = $this->signKey($payload);

        $req = new WP_REST_Request('POST', '/license');
        $req->set_param('key', $key);

        $res = $this->controller()->activate($req);

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('license_domain_mismatch', $res->get_error_code());
        self::assertArrayNotHasKey(
            'guardkids_license',
            $GLOBALS['gk_options'],
            'Rollback deve apagar a option pra evitar deixar estado podre',
        );
    }

    public function testActivateRollsBackWhenLicenseExpired(): void
    {
        $payload        = $this->basePayload();
        $payload['exp'] = time() - 86_400;
        $key            = $this->signKey($payload);

        $req = new WP_REST_Request('POST', '/license');
        $req->set_param('key', $key);

        $res = $this->controller()->activate($req);

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('license_expired', $res->get_error_code());
        self::assertArrayNotHasKey('guardkids_license', $GLOBALS['gk_options']);
    }

    public function testActivateRollsBackWhenLicenseRevoked(): void
    {
        $payload                                            = $this->basePayload();
        $payload['jti']                                     = '01HJREVOKED';
        $GLOBALS['gk_options']['guardkids_license_revoked'] = ['01HJREVOKED'];

        $req = new WP_REST_Request('POST', '/license');
        $req->set_param('key', $this->signKey($payload));

        $res = $this->controller()->activate($req);

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('license_revoked', $res->get_error_code());
    }

    public function testActivateTrimsWhitespaceAroundKey(): void
    {
        $key = $this->signKey($this->basePayload());
        $req = new WP_REST_Request('POST', '/license');
        $req->set_param('key', "  \n" . $key . "  \t");

        $res = $this->controller()->activate($req);
        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertSame('active', $res->get_data()['status']);
    }

    public function testDeactivateRemovesLicenseAndReturnsFreeSnapshot(): void
    {
        $GLOBALS['gk_options']['guardkids_license'] = [
            'key_b64'      => $this->signKey($this->basePayload()),
            'activated_at' => '2026-06-08 14:00:00',
        ];

        $res = $this->controller()->deactivate();

        self::assertInstanceOf(WP_REST_Response::class, $res);
        $data = $res->get_data();
        self::assertSame('free', $data['plan']);
        self::assertSame('none', $data['status']);
        self::assertArrayNotHasKey('guardkids_license', $GLOBALS['gk_options']);
    }

    public function testDeactivateIsIdempotentWhenNoLicense(): void
    {
        $res = $this->controller()->deactivate();
        self::assertInstanceOf(WP_REST_Response::class, $res);
        self::assertSame('none', $res->get_data()['status']);
    }

    private function controller(?SettingsRepository $settings = null): LicenseController
    {
        $verifier = new Verifier(base64_encode($this->keys['pubkey']));
        $gate     = new Gate($verifier);
        return new LicenseController($gate, $verifier, $settings);
    }

    /**
     * Cria SettingsRepository real apoiado num wpdb stub in-memory.
     * Segue o padrão de SettingsRepositoryTest (SettingsRepository é final, então
     * stubamos pela camada de baixo).
     *
     * @param array<string, mixed> $values
     */
    private function settingsWith(array $values): SettingsRepository
    {
        $jsonValues = [];
        foreach ($values as $k => $v) {
            $jsonValues[$k] = (string) json_encode($v);
        }
        $GLOBALS['wpdb'] = new class ($jsonValues) extends \wpdb {
            private ?string $lastKey = null;

            /** @param array<string, string> $values key → JSON-encoded value */
            public function __construct(private array $values)
            {
            }

            public function prepare($query, ...$args)
            {
                $this->lastKey = isset($args[0]) ? (string) $args[0] : null;
                return $query;
            }

            public function get_var($sql, $x = 0, $y = 0)
            {
                return $this->values[$this->lastKey ?? ''] ?? null;
            }
        };
        return new SettingsRepository();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function signKey(array $payload): string
    {
        $json      = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $b64       = self::b64url($json);
        $signature = sodium_crypto_sign_detached($b64, $this->keys['signKey']);
        return $b64 . '.' . self::b64url($signature);
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(): array
    {
        return [
            'iss'      => 'guardkids',
            'sub'      => 'https://example.test',
            'jti'      => '01HJ0K7CABCDEF',
            'iat'      => time(),
            'exp'      => time() + 86_400 * 365,
            'plan'     => 'premium',
            'features' => Gate::PREMIUM_FEATURES,
            'email'    => 'djair@example.test',
        ];
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
