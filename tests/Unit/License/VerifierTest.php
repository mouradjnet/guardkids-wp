<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\License;

use GuardKids\License\Verifier;
use PHPUnit\Framework\TestCase;

final class VerifierTest extends TestCase
{
    /** @var array{pubkey: string, signKey: string} */
    private array $keys;

    protected function setUp(): void
    {
        $keypair       = sodium_crypto_sign_keypair();
        $this->keys    = [
            'pubkey'  => sodium_crypto_sign_publickey($keypair),
            'signKey' => sodium_crypto_sign_secretkey($keypair),
        ];
    }

    public function testVerifyReturnsPayloadForValidKey(): void
    {
        $key      = $this->sign($this->basePayload());
        $verifier = $this->verifier();

        $payload = $verifier->verify($key);

        self::assertNotNull($payload);
        self::assertSame('guardkids', $payload->iss);
        self::assertSame('https://example.test', $payload->sub);
        self::assertSame('premium', $payload->plan);
        self::assertContains('browser', $payload->features);
        self::assertSame('djair@example.test', $payload->email);
    }

    public function testVerifyRejectsTamperedPayload(): void
    {
        $key   = $this->sign($this->basePayload());
        $parts = explode('.', $key);
        // Troca o payload por outro válido em formato — mas a sig original
        // continua sendo do payload antigo, então tem que falhar.
        $tamperedPayload = self::b64url(json_encode([
            'iss' => 'guardkids', 'sub' => 'https://attacker.test',
            'jti' => 'fake', 'iat' => 0, 'exp' => time() + 3600,
            'plan' => 'premium', 'features' => [],
        ]));
        $tampered = $tamperedPayload . '.' . $parts[1];

        self::assertNull($this->verifier()->verify($tampered));
    }

    public function testVerifyRejectsWrongSignature(): void
    {
        $key   = $this->sign($this->basePayload());
        $parts = explode('.', $key);
        // Inverte os últimos 2 bytes da sig — mantém comprimento, quebra sig.
        $rawSig                                = base64_decode(strtr($parts[1], '-_', '+/'));
        $rawSig[\strlen($rawSig) - 1]          = "\x00";
        $broken                                = $parts[0] . '.' . self::b64url($rawSig);

        self::assertNull($this->verifier()->verify($broken));
    }

    public function testVerifyRejectsWrongPubkey(): void
    {
        $key = $this->sign($this->basePayload());

        $otherKeypair = sodium_crypto_sign_keypair();
        $verifier     = new Verifier(base64_encode(sodium_crypto_sign_publickey($otherKeypair)));

        self::assertNull($verifier->verify($key));
    }

    public function testVerifyRejectsMalformedKey(): void
    {
        $verifier = $this->verifier();

        self::assertNull($verifier->verify(''));
        self::assertNull($verifier->verify('semponto'));
        self::assertNull($verifier->verify('um.dois.tres'));
        self::assertNull($verifier->verify('===.==='));
    }

    public function testVerifyRejectsPayloadMissingRequiredFields(): void
    {
        $incomplete = [
            'iss' => 'guardkids',
            // sem 'sub'
            'jti' => 'x', 'iat' => 1, 'exp' => 2,
            'plan' => 'premium', 'features' => [],
        ];
        $key = $this->sign($incomplete);

        self::assertNull($this->verifier()->verify($key));
    }

    public function testVerifyAcceptsPayloadWithoutEmail(): void
    {
        $payload = $this->basePayload();
        unset($payload['email']);
        $key = $this->sign($payload);

        $verified = $this->verifier()->verify($key);
        self::assertNotNull($verified);
        self::assertNull($verified->email);
    }

    public function testDefaultPubkeyPlaceholderProducesNullPubkey(): void
    {
        // Sanity check: o placeholder embarcado não decodifica em 32 bytes,
        // então o Verifier de produção (sem CLI rodado) recusa qualquer chave.
        $defaultVerifier = new Verifier();
        self::assertNull($defaultVerifier->verify('a.b'));
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
            'features' => ['browser', 'categories', 'schedule', 'reports', 'location', 'unlimited_kids', 'full_history'],
            'email'    => 'djair@example.test',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sign(array $payload): string
    {
        $json      = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $b64       = self::b64url($json);
        $signature = sodium_crypto_sign_detached($b64, $this->keys['signKey']);
        return $b64 . '.' . self::b64url($signature);
    }

    private function verifier(): Verifier
    {
        return new Verifier(base64_encode($this->keys['pubkey']));
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
