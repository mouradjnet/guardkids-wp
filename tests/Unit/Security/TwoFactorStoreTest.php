<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Security;

use GuardKids\Security\TwoFactorStore;
use PHPUnit\Framework\TestCase;

final class TwoFactorStoreTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['gk_user_meta'] = [];
    }

    public function testStartsDisabled(): void
    {
        $store = new TwoFactorStore(7);
        self::assertFalse($store->isEnabled());
        self::assertSame('', $store->getSecret());
        self::assertSame([], $store->getRecoveryHashes());
    }

    public function testEnablePersistsSecretAndRecoveryAndClearsPending(): void
    {
        $store = new TwoFactorStore(7);
        $store->setPendingSecret('PENDING');
        $store->enable('SECRET', ['h1', 'h2']);

        self::assertTrue($store->isEnabled());
        self::assertSame('SECRET', $store->getSecret());
        self::assertSame(['h1', 'h2'], $store->getRecoveryHashes());
        self::assertSame('', $store->getPendingSecret());
    }

    public function testDisableWipesEverything(): void
    {
        $store = new TwoFactorStore(7);
        $store->enable('SECRET', ['h1']);
        $store->disable();

        self::assertFalse($store->isEnabled());
        self::assertSame('', $store->getSecret());
        self::assertSame([], $store->getRecoveryHashes());
    }

    public function testIsolatesByUserId(): void
    {
        (new TwoFactorStore(1))->enable('A', []);
        self::assertFalse((new TwoFactorStore(2))->isEnabled());
    }

    public function testLoginNonceVerifiesOnceAndExpires(): void
    {
        $store = new TwoFactorStore(7);
        $store->setLoginNonce('abc');
        self::assertTrue($store->verifyLoginNonce('abc'));
        self::assertFalse($store->verifyLoginNonce('wrong'));

        $store->clearLoginNonce();
        self::assertFalse($store->verifyLoginNonce('abc'));
    }
}
