<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Security;

use GuardKids\Security\SecurityHeaders;
use PHPUnit\Framework\TestCase;

/**
 * SecurityHeaders — headers globais via send_headers. HSTS condicional a SSL.
 */
final class SecurityHeadersTest extends TestCase
{
    private SecurityHeaders $headers;

    protected function setUp(): void
    {
        $this->headers = new SecurityHeaders();
    }

    public function testSendsAllNonHstsHeadersOverHttp(): void
    {
        $headers = $this->headers->headers(false);

        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
        self::assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
        self::assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);
        self::assertArrayHasKey('Content-Security-Policy', $headers);
        self::assertArrayHasKey('Permissions-Policy', $headers);
    }

    public function testOmitsHstsOverHttp(): void
    {
        $headers = $this->headers->headers(false);

        self::assertArrayNotHasKey('Strict-Transport-Security', $headers);
    }

    public function testIncludesHstsOverHttps(): void
    {
        $headers = $this->headers->headers(true);

        self::assertSame(
            'max-age=31536000; includeSubDomains; preload',
            $headers['Strict-Transport-Security']
        );
    }

    public function testCspAllowsGutenbergUnsafeEval(): void
    {
        $csp = $this->headers->headers(true)['Content-Security-Policy'];

        self::assertStringContainsString("script-src 'self' 'unsafe-inline' 'unsafe-eval'", $csp);
        self::assertStringContainsString('upgrade-insecure-requests', $csp);
    }
}
