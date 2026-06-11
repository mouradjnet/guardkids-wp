<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Invite;

use GuardKids\Invite\InviteToken;
use PHPUnit\Framework\TestCase;

final class InviteTokenTest extends TestCase
{
    public function testGenerateReturns64HexChars(): void
    {
        $token = InviteToken::generate();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
    }

    public function testGenerateIsDifferentEachCall(): void
    {
        self::assertNotSame(InviteToken::generate(), InviteToken::generate());
    }

    public function testHashIsSha256Deterministic(): void
    {
        $token = '00ff' . str_repeat('a', 60);
        $expected = hash('sha256', $token);
        self::assertSame($expected, InviteToken::hash($token));
        self::assertSame($expected, InviteToken::hash($token));
        self::assertSame(64, strlen(InviteToken::hash($token)));
    }

    public function testTtlIsSevenDays(): void
    {
        self::assertSame(7 * 24 * 3600, InviteToken::TTL_SECONDS);
    }
}
