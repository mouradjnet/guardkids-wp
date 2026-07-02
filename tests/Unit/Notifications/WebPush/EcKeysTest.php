<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Notifications\WebPush;

use GuardKids\Notifications\WebPush\Base64Url;
use GuardKids\Notifications\WebPush\EcKeys;
use PHPUnit\Framework\TestCase;

final class EcKeysTest extends TestCase
{
    public function testBase64UrlRoundtrip(): void
    {
        $raw = random_bytes(20);
        self::assertSame($raw, Base64Url::decode(Base64Url::encode($raw)));
        self::assertStringNotContainsString('=', Base64Url::encode($raw));
    }

    public function testGenerateProducesRaw65PublicAnd32Private(): void
    {
        $k = EcKeys::generate();
        self::assertSame(65, strlen($k['public']));
        self::assertSame("\x04", $k['public'][0]);
        self::assertSame(32, strlen($k['privateRaw']));
    }

    public function testEcdhAgreesBothDirections(): void
    {
        $a = EcKeys::generate();
        $b = EcKeys::generate();
        $secretAB = openssl_pkey_derive(EcKeys::publicFromRaw($b['public']), $a['key'], 32);
        $secretBA = openssl_pkey_derive(EcKeys::publicFromRaw($a['public']), $b['key'], 32);
        self::assertSame($secretAB, $secretBA);
    }

    public function testPrivateFromRawRebuildsUsableKey(): void
    {
        $k = EcKeys::generate();
        $rebuilt = EcKeys::privateFromRaw($k['privateRaw'], $k['public']);
        $peer = EcKeys::generate();
        $s1 = openssl_pkey_derive(EcKeys::publicFromRaw($peer['public']), $k['key'], 32);
        $s2 = openssl_pkey_derive(EcKeys::publicFromRaw($peer['public']), $rebuilt, 32);
        self::assertSame($s1, $s2);
    }
}
