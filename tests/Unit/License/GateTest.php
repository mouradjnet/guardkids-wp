<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\License;

use GuardKids\License\Gate;
use GuardKids\License\Verifier;
use PHPUnit\Framework\TestCase;

final class GateTest extends TestCase
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
        $GLOBALS['gk_options']                           = [];
        $GLOBALS['gk_options']['siteurl']                = 'https://example.test';
        $GLOBALS['gk_transients']                        = [];
    }

    public function testWithoutLicenseFallsBackToFreePlan(): void
    {
        $gate = $this->gate();

        self::assertSame('none', $gate->status());
        self::assertSame('free', $gate->plan());
        self::assertNull($gate->expiresAt());
        self::assertNull($gate->daysLeft());
        self::assertNull($gate->payload());
    }

    public function testFreeFeaturesAreAlwaysAllowed(): void
    {
        $gate = $this->gate();

        // Features fora de PREMIUM_FEATURES — sempre true
        self::assertTrue($gate->can('blacklist'));
        self::assertTrue($gate->can('time_basic'));
        self::assertTrue($gate->can('something_random'));
    }

    public function testPremiumFeaturesBlockedWithoutLicense(): void
    {
        $gate = $this->gate();

        foreach (Gate::PREMIUM_FEATURES as $f) {
            self::assertFalse($gate->can($f), "Feature {$f} deveria estar bloqueada sem licença");
        }
    }

    public function testActiveLicenseUnlocksPremiumFeatures(): void
    {
        $this->installLicense($this->basePayload());

        $gate = $this->gate();

        self::assertSame('active', $gate->status());
        self::assertSame('premium', $gate->plan());
        self::assertGreaterThan(0, $gate->daysLeft());

        foreach (Gate::PREMIUM_FEATURES as $f) {
            self::assertTrue($gate->can($f), "Feature {$f} deveria estar liberada");
        }
    }

    public function testPremiumPartialEmitsOnlyFeaturesInPayload(): void
    {
        // Premium parcial: só `browser` e `categories` — `reports` permanece bloqueado.
        $payload             = $this->basePayload();
        $payload['features'] = ['browser', 'categories'];
        $this->installLicense($payload);

        $gate = $this->gate();

        self::assertTrue($gate->can('browser'));
        self::assertTrue($gate->can('categories'));
        self::assertFalse($gate->can('reports'));
        self::assertFalse($gate->can('location'));
    }

    public function testExpiredLicenseFallsBackToFreeButPreservesPayloadForUi(): void
    {
        $payload        = $this->basePayload();
        $payload['exp'] = time() - 86_400;
        $this->installLicense($payload);

        $gate = $this->gate();

        self::assertSame('expired', $gate->status());
        self::assertSame('free', $gate->plan());
        self::assertSame(0, $gate->daysLeft());
        self::assertNotNull($gate->payload(), 'UI ainda precisa do payload pra mostrar dados antigos');

        // Premium features ficam bloqueadas, mas dados antigos não somem
        self::assertFalse($gate->can('browser'));
    }

    public function testDomainMismatchBlocksLicense(): void
    {
        $payload        = $this->basePayload();
        $payload['sub'] = 'https://outro-dominio.com';
        $this->installLicense($payload);

        $gate = $this->gate();

        self::assertSame('domain_mismatch', $gate->status());
        self::assertSame('free', $gate->plan());
        self::assertFalse($gate->can('browser'));
    }

    public function testRevokedJtiDowngrades(): void
    {
        $payload                                     = $this->basePayload();
        $payload['jti']                              = '01HJREVOKED';
        $this->installLicense($payload);
        $GLOBALS['gk_options']['guardkids_license_revoked'] = ['01HJREVOKED', '01HJOTHER'];

        $gate = $this->gate();

        self::assertSame('revoked', $gate->status());
        self::assertSame('free', $gate->plan());
        self::assertFalse($gate->can('browser'));
    }

    public function testRevokedViaRemoteCacheDowngrades(): void
    {
        $payload        = $this->basePayload();
        $payload['jti'] = '01HJREMOTE';
        $this->installLicense($payload);
        $GLOBALS['gk_transients']['gk_revoked_jti'] = ['01HJREMOTE'];

        $gate = $this->gate();

        self::assertSame('revoked', $gate->status());
        self::assertSame('free', $gate->plan());
        self::assertFalse($gate->can('browser'));
    }

    public function testCorruptedLicenseInOptionDegradesToFree(): void
    {
        // Algum dia o option pode acabar com dados podres (rollback de versão,
        // dump manual, etc.). Nunca deve levantar exceção — só vira free.
        $GLOBALS['gk_options']['guardkids_license'] = ['key_b64' => 'lixo.malformado'];

        $gate = $this->gate();

        self::assertSame('none', $gate->status());
        self::assertSame('free', $gate->plan());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function installLicense(array $payload): void
    {
        $json      = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $b64       = self::b64url($json);
        $signature = sodium_crypto_sign_detached($b64, $this->keys['signKey']);
        $key       = $b64 . '.' . self::b64url($signature);

        $GLOBALS['gk_options']['guardkids_license'] = [
            'key_b64'      => $key,
            'activated_at' => '2026-06-08 14:23:00',
        ];
    }

    private function gate(): Gate
    {
        return new Gate(new Verifier(base64_encode($this->keys['pubkey'])));
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
