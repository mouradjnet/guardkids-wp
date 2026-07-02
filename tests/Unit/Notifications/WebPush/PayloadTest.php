<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Notifications\WebPush;

use GuardKids\Notifications\WebPush\Base64Url;
use GuardKids\Notifications\WebPush\EcKeys;
use GuardKids\Notifications\WebPush\Payload;
use PHPUnit\Framework\TestCase;

final class PayloadTest extends TestCase
{
    // RFC 8291, Section 5.
    private const PLAINTEXT   = 'When I grow up, I want to be a watermelon';
    private const UA_PUBLIC   = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
    private const AUTH        = 'BTBZMqHH6r4Tts7J_aSIgg';
    private const AS_PUBLIC   = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
    private const AS_PRIVATE  = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';
    private const SALT        = 'DGv6ra1nlYgDCS1FRnbzlw';
    private const EXPECTED    = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

    public function testMatchesRfc8291TestVector(): void
    {
        $asPublic  = Base64Url::decode(self::AS_PUBLIC);
        $asPrivate = Base64Url::decode(self::AS_PRIVATE);
        $server = [
            'key'    => EcKeys::privateFromRaw($asPrivate, $asPublic),
            'public' => $asPublic,
        ];

        $body = (new Payload())->encrypt(
            self::PLAINTEXT,
            Base64Url::decode(self::UA_PUBLIC),
            Base64Url::decode(self::AUTH),
            Base64Url::decode(self::SALT),
            $server,
        );

        self::assertSame(self::EXPECTED, Base64Url::encode($body));
    }
}
