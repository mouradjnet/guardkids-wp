<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Notifications\WebPush;

use GuardKids\Notifications\WebPush\Base64Url;
use GuardKids\Notifications\WebPush\EcKeys;
use GuardKids\Notifications\WebPush\Vapid;
use GuardKids\Notifications\WebPush\VapidKeys;
use PHPUnit\Framework\TestCase;

final class VapidTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['gk_options'] = [];
    }

    public function testKeysArePersistedAndStable(): void
    {
        $keys = new VapidKeys();
        $pub1 = $keys->publicKey();
        $pub2 = (new VapidKeys())->publicKey();
        self::assertSame($pub1, $pub2);
        self::assertSame(65, strlen(Base64Url::decode($pub1)));
    }

    public function testHeaderJwtVerifiesWithPublicKey(): void
    {
        $keys = new VapidKeys();
        $header = (new Vapid($keys))->header('https://fcm.googleapis.com/fcm/send/abc');

        self::assertStringStartsWith('vapid t=', $header);
        preg_match('/t=([^,]+), k=(.+)$/', $header, $m);
        [$jwt, $k] = [$m[1], $m[2]];
        self::assertSame($keys->publicKey(), $k);

        [$h, $c, $sig] = explode('.', $jwt);
        $claims = json_decode(Base64Url::decode($c), true);
        self::assertSame('https://fcm.googleapis.com', $claims['aud']);
        self::assertStringStartsWith('mailto:', $claims['sub']);

        $jose = Base64Url::decode($sig);
        $der = self::joseToDer($jose);
        $ok = openssl_verify("{$h}.{$c}", $der, EcKeys::publicFromRaw(Base64Url::decode($k)), OPENSSL_ALGO_SHA256);
        self::assertSame(1, $ok);
    }

    private static function joseToDer(string $jose): string
    {
        $r = ltrim(substr($jose, 0, 32), "\x00");
        $s = ltrim(substr($jose, 32, 32), "\x00");
        if ($r === '') {
            $r = "\x00";
        }
        if ($s === '') {
            $s = "\x00";
        }
        if (ord($r[0]) > 0x7f) {
            $r = "\x00" . $r;
        }
        if (ord($s[0]) > 0x7f) {
            $s = "\x00" . $s;
        }
        $seq = "\x02" . chr(strlen($r)) . $r . "\x02" . chr(strlen($s)) . $s;
        return "\x30" . chr(strlen($seq)) . $seq;
    }
}
