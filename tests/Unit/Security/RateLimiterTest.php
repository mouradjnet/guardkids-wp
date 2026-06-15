<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Security;

use GuardKids\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * RateLimiter — janela rolling por endpoint+childId via transient.
 */
final class RateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['gk_transients'] = [];
    }

    public function testAllowsRequestsUnderLimit(): void
    {
        $limiter = new RateLimiter(limit: 3, window: 60);
        self::assertTrue($limiter->allow('events', 1));
        self::assertTrue($limiter->allow('events', 1));
        self::assertTrue($limiter->allow('events', 1));
    }

    public function testRejectsRequestsOverLimit(): void
    {
        $limiter = new RateLimiter(limit: 3, window: 60);
        $limiter->allow('events', 1);
        $limiter->allow('events', 1);
        $limiter->allow('events', 1);
        self::assertFalse($limiter->allow('events', 1));
        self::assertFalse($limiter->allow('events', 1));
    }

    public function testIsolatesPerChild(): void
    {
        $limiter = new RateLimiter(limit: 2, window: 60);
        $limiter->allow('events', 1);
        $limiter->allow('events', 1);
        self::assertFalse($limiter->allow('events', 1));
        // child diferente continua com a quota intacta
        self::assertTrue($limiter->allow('events', 2));
    }

    public function testIsolatesPerEndpoint(): void
    {
        $limiter = new RateLimiter(limit: 2, window: 60);
        $limiter->allow('events', 1);
        $limiter->allow('events', 1);
        self::assertFalse($limiter->allow('events', 1));
        // endpoint diferente pro mesmo child também tem quota separada
        self::assertTrue($limiter->allow('location', 1));
    }

    public function testRetryAfterReturnsWindow(): void
    {
        $limiter = new RateLimiter(limit: 1, window: 60);
        self::assertSame(60, $limiter->retryAfter());
    }

    public function testDefaultLimitAndWindow(): void
    {
        $limiter = new RateLimiter();
        // 60 chamadas na janela default devem caber; a 61ª rejeita.
        for ($i = 0; $i < 60; $i++) {
            self::assertTrue($limiter->allow('events', 99), "request {$i} should pass");
        }
        self::assertFalse($limiter->allow('events', 99));
    }
}
