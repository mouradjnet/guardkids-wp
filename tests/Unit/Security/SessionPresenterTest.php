<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Security;

use GuardKids\Security\SessionPresenter;
use PHPUnit\Framework\TestCase;

final class SessionPresenterTest extends TestCase
{
    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36';
    private const FIREFOX = 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Firefox/121.0';

    public function testMapsAndSortsByLoginDescAndFlagsCurrent(): void
    {
        $old = ['login' => 100, 'ip' => '1.1.1.1', 'ua' => self::CHROME, 'expiration' => 200];
        $new = ['login' => 300, 'ip' => '2.2.2.2', 'ua' => self::FIREFOX, 'expiration' => 400];

        $out = SessionPresenter::present([$old, $new], $new);

        self::assertCount(2, $out);
        // ordenado por login desc → o "new" (firefox) primeiro
        self::assertSame(300, $out[0]['lastAccess']);
        self::assertSame('Firefox · Linux', $out[0]['device']);
        self::assertSame('2.2.2.2', $out[0]['ip']);
        self::assertTrue($out[0]['current']);
        // o "old" (chrome) depois, não-atual
        self::assertSame('Chrome · Windows', $out[1]['device']);
        self::assertFalse($out[1]['current']);
    }

    public function testNoCurrentWhenNull(): void
    {
        $s = ['login' => 100, 'ip' => '1.1.1.1', 'ua' => self::CHROME, 'expiration' => 200];
        $out = SessionPresenter::present([$s], null);
        self::assertFalse($out[0]['current']);
    }

    public function testEmptyIpAndUaFallBackToUnknown(): void
    {
        $s = ['login' => 100, 'ip' => '', 'ua' => '', 'expiration' => 200];
        $out = SessionPresenter::present([$s], null);
        self::assertSame('Desconhecido', $out[0]['ip']);
        self::assertSame('Desconhecido · Desconhecido', $out[0]['device']);
    }

    public function testEmptyListReturnsEmpty(): void
    {
        self::assertSame([], SessionPresenter::present([], null));
    }
}
