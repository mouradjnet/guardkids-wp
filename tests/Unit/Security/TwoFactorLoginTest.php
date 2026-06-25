<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Security;

use GuardKids\Security\Totp;
use GuardKids\Security\TwoFactorLogin;
use GuardKids\Security\TwoFactorStore;
use PHPUnit\Framework\TestCase;

final class TwoFactorLoginTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['gk_user_meta'] = [];
    }

    public function testPassesAcceptsValidTotp(): void
    {
        $totp   = new Totp();
        $secret = $totp->generateSecret();
        $store  = new TwoFactorStore(7);
        $store->enable($secret, []);

        $login = new TwoFactorLogin();
        self::assertTrue($login->passes($store, $totp->codeAt($secret, time())));
        self::assertFalse($login->passes($store, '000000'));
    }

    public function testPassesConsumesRecoveryCode(): void
    {
        $rc     = new \GuardKids\Security\RecoveryCodes();
        $codes  = $rc->generate();
        $store  = new TwoFactorStore(7);
        $store->enable((new Totp())->generateSecret(), $rc->hashAll($codes));

        $login = new TwoFactorLogin();
        self::assertTrue($login->passes($store, $codes[0]));
        self::assertFalse($login->passes($store, $codes[0]));
    }
}
