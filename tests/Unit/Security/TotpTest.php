<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Security;

use GuardKids\Security\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    // Segredo dos vetores oficiais RFC 4226 ("12345678901234567890" em base32).
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testBase32RoundTrip(): void
    {
        $bytes = random_bytes(20);
        self::assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
    }

    public function testCodeAtMatchesRfcVectors(): void
    {
        $totp = new Totp();
        // counter = floor(t/30); RFC 4226 Appendix D: counter 0=755224, 1=287082, 3=969429.
        self::assertSame('755224', $totp->codeAt(self::RFC_SECRET, 0));
        self::assertSame('755224', $totp->codeAt(self::RFC_SECRET, 29));
        self::assertSame('287082', $totp->codeAt(self::RFC_SECRET, 30));
        self::assertSame('969429', $totp->codeAt(self::RFC_SECRET, 90));
    }

    public function testVerifyAcceptsCurrentCode(): void
    {
        $totp = new Totp();
        $secret = $totp->generateSecret();
        $code = $totp->codeAt($secret, time());
        self::assertTrue($totp->verify($secret, $code));
    }

    public function testVerifyRejectsWrongCodeAndBadFormat(): void
    {
        $totp = new Totp();
        $secret = $totp->generateSecret();
        self::assertFalse($totp->verify($secret, '000000'));
        self::assertFalse($totp->verify($secret, 'abc'));
        self::assertFalse($totp->verify($secret, '12345'));
    }

    public function testProvisioningUriHasSecretAndIssuer(): void
    {
        $uri = (new Totp())->provisioningUri('ABC234', 'pai@x.com', 'GuardKids');
        self::assertStringStartsWith('otpauth://totp/', $uri);
        self::assertStringContainsString('secret=ABC234', $uri);
        self::assertStringContainsString('issuer=GuardKids', $uri);
    }
}
