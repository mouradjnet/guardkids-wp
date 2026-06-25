<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Security;

use GuardKids\Security\UserAgent;
use PHPUnit\Framework\TestCase;

final class UserAgentTest extends TestCase
{
    public function testChromeOnWindows(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
        self::assertSame(['browser' => 'Chrome', 'os' => 'Windows'], UserAgent::parse($ua));
    }

    public function testFirefox(): void
    {
        $ua = 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0';
        self::assertSame(['browser' => 'Firefox', 'os' => 'Linux'], UserAgent::parse($ua));
    }

    public function testSafariOnMac(): void
    {
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';
        self::assertSame(['browser' => 'Safari', 'os' => 'macOS'], UserAgent::parse($ua));
    }

    public function testEdgeBeatsChrome(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120.0 Safari/537.36 Edg/120.0';
        self::assertSame(['browser' => 'Edge', 'os' => 'Windows'], UserAgent::parse($ua));
    }

    public function testAndroidBeatsLinux(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 13; Pixel) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36';
        self::assertSame(['browser' => 'Chrome', 'os' => 'Android'], UserAgent::parse($ua));
    }

    public function testIphone(): void
    {
        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';
        self::assertSame(['browser' => 'Safari', 'os' => 'iOS'], UserAgent::parse($ua));
    }

    public function testEmptyIsUnknown(): void
    {
        self::assertSame(['browser' => 'Desconhecido', 'os' => 'Desconhecido'], UserAgent::parse(''));
    }
}
