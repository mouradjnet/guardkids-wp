<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration\Api;

use GuardKids\Api\Controllers\LicenseController;
use GuardKids\Database\SettingsRepository;
use GuardKids\License\Gate;
use GuardKids\License\Verifier;
use GuardKids\Tests\Integration\ControllerIntegrationTestCase;
use WP_REST_Request;

/**
 * Integration tests do LicenseController contra MySQL real.
 *
 * Unit (tests/Unit/Api/LicenseControllerTest.php) ja cobre os 11 casos
 * de validacao do payload e rollback. Aqui o foco e nos pontos que
 * stub nao cobre:
 *  - upgradeUrl persistido no SettingsRepository real (tabela MySQL)
 *  - estado cross-instance (activate persiste em option, proxima call le)
 *  - delete_option real ao deactivate / rollback
 */
final class LicenseControllerTest extends ControllerIntegrationTestCase
{
    /** @var array{pubkey: string, signKey: string} */
    private array $keys;

    protected function setUp(): void
    {
        parent::setUp();

        $keypair = sodium_crypto_sign_keypair();
        $this->keys = [
            'pubkey'  => sodium_crypto_sign_publickey($keypair),
            'signKey' => sodium_crypto_sign_secretkey($keypair),
        ];

        // Reseta options globais por teste (TRUNCATE so afeta tabelas).
        $GLOBALS['gk_options'] = [
            'siteurl' => 'https://example.test',
        ];
    }

    private function controller(): LicenseController
    {
        $verifier = new Verifier(base64_encode($this->keys['pubkey']));
        $gate     = new Gate($verifier);
        return new LicenseController($gate, $verifier, new SettingsRepository());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function signKey(array $payload): string
    {
        $json      = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
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

    public function test_index_returns_free_snapshot_without_license(): void
    {
        $data = $this->dataOf($this->controller()->index());
        $this->assertSame('free', $data['plan']);
        $this->assertSame('none', $data['status']);
        $this->assertSame([], $data['features']);
        $this->assertNull($data['expiresAt']);
        $this->assertNull($data['activatedAt']);
        $this->assertNull($data['upgradeUrl']);
    }

    public function test_index_reads_upgradeUrl_from_settings_table(): void
    {
        // Persiste no SettingsRepository real (tabela MySQL)
        (new SettingsRepository())->set('upgrade_url', 'https://compre.test/upgrade');

        $data = $this->dataOf($this->controller()->index());
        $this->assertSame('https://compre.test/upgrade', $data['upgradeUrl']);
    }

    public function test_activate_persists_license_in_wp_options(): void
    {
        $key = $this->signKey($this->basePayload());
        $req = $this->makeRequest('POST', '/license');
        $req->set_param('key', $key);

        $data = $this->dataOf($this->controller()->activate($req));
        $this->assertSame('active', $data['status']);
        $this->assertSame('premium', $data['plan']);
        $this->assertContains('browser', $data['features']);
        $this->assertSame('djair@example.test', $data['email']);
        $this->assertNotNull($data['activatedAt']);

        // Verifica persistencia real na "option" (gk_options simula wp_options)
        $stored = $GLOBALS['gk_options']['guardkids_license'] ?? null;
        $this->assertIsArray($stored);
        $this->assertSame($key, $stored['key_b64']);
    }

    public function test_activate_then_separate_index_reads_persisted_state(): void
    {
        $key = $this->signKey($this->basePayload());
        $req = $this->makeRequest('POST', '/license');
        $req->set_param('key', $key);

        // Activate na instancia A
        $this->controller()->activate($req);

        // Index numa instancia B "fresca" — la o state vem da option persistida
        $data = $this->dataOf($this->controller()->index());
        $this->assertSame('active', $data['status']);
        $this->assertSame('premium', $data['plan']);
    }

    public function test_activate_rollback_removes_option_on_domain_mismatch(): void
    {
        $payload        = $this->basePayload();
        $payload['sub'] = 'https://outro-dominio.test';
        $key            = $this->signKey($payload);

        $req = $this->makeRequest('POST', '/license');
        $req->set_param('key', $key);

        $resp = $this->controller()->activate($req);
        $this->assertWpError('license_domain_mismatch', $resp);
        $this->assertResponseStatus(422, $resp);

        $this->assertArrayNotHasKey('guardkids_license', $GLOBALS['gk_options']);
    }

    public function test_activate_rollback_on_expired_license(): void
    {
        $payload        = $this->basePayload();
        $payload['exp'] = time() - 3600;
        $key            = $this->signKey($payload);

        $req = $this->makeRequest('POST', '/license');
        $req->set_param('key', $key);

        $resp = $this->controller()->activate($req);
        $this->assertWpError('license_expired', $resp);
        $this->assertArrayNotHasKey('guardkids_license', $GLOBALS['gk_options']);
    }

    public function test_activate_rejects_malformed_key(): void
    {
        $req = $this->makeRequest('POST', '/license');
        $req->set_param('key', 'lixo.formato.errado');

        $resp = $this->controller()->activate($req);
        $this->assertWpError('invalid_license', $resp);
        $this->assertArrayNotHasKey('guardkids_license', $GLOBALS['gk_options']);
    }

    public function test_activate_rejects_empty_key(): void
    {
        $req = $this->makeRequest('POST', '/license');
        $req->set_param('key', '');

        $resp = $this->controller()->activate($req);
        $this->assertWpError('invalid_payload', $resp);
        $this->assertResponseStatus(422, $resp);
    }

    public function test_deactivate_removes_persisted_license(): void
    {
        // Ativa primeiro
        $key = $this->signKey($this->basePayload());
        $req = $this->makeRequest('POST', '/license');
        $req->set_param('key', $key);
        $this->controller()->activate($req);
        $this->assertArrayHasKey('guardkids_license', $GLOBALS['gk_options']);

        // Desativa
        $data = $this->dataOf($this->controller()->deactivate());
        $this->assertSame('none', $data['status']);
        $this->assertSame('free', $data['plan']);
        $this->assertArrayNotHasKey('guardkids_license', $GLOBALS['gk_options']);
    }

    public function test_deactivate_is_idempotent_without_license(): void
    {
        $data = $this->dataOf($this->controller()->deactivate());
        $this->assertSame('none', $data['status']);
    }

    public function test_full_cycle_activate_then_deactivate_then_activate_again(): void
    {
        $key = $this->signKey($this->basePayload());
        $req = $this->makeRequest('POST', '/license');
        $req->set_param('key', $key);

        // Activate 1
        $this->assertSame('active', $this->dataOf($this->controller()->activate($req))['status']);
        // Deactivate
        $this->assertSame('none', $this->dataOf($this->controller()->deactivate())['status']);
        // Activate 2 (mesma chave)
        $this->assertSame('active', $this->dataOf($this->controller()->activate($req))['status']);
    }

    public function test_upgradeUrl_persists_across_activate_calls(): void
    {
        (new SettingsRepository())->set('upgrade_url', 'https://compre.test');

        $key = $this->signKey($this->basePayload());
        $req = $this->makeRequest('POST', '/license');
        $req->set_param('key', $key);

        $data = $this->dataOf($this->controller()->activate($req));
        $this->assertSame('active', $data['status']);
        $this->assertSame('https://compre.test', $data['upgradeUrl']);
    }
}
