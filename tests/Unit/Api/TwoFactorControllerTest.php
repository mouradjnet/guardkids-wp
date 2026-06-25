<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\TwoFactorController;
use GuardKids\Security\Totp;
use GuardKids\Security\TwoFactorStore;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

final class TwoFactorControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['gk_user_meta']      = [];
        $GLOBALS['gk_transients']     = [];
        $GLOBALS['gk_current_user_id'] = 7;
        $GLOBALS['gk_users'] = [7 => ['ID' => 7, 'user_email' => 'pai@x.com', 'user_login' => 'pai']];
    }

    private function ctrl(): TwoFactorController
    {
        return new TwoFactorController(new TwoFactorStore(7));
    }

    private function codeReq(string $code): WP_REST_Request
    {
        $req = new WP_REST_Request('POST', '/security/2fa');
        $req->set_param('code', $code);
        return $req;
    }

    public function testStatusStartsDisabled(): void
    {
        $data = $this->ctrl()->status()->get_data();
        self::assertFalse($data['enabled']);
        self::assertSame(0, $data['recoveryRemaining']);
    }

    public function testSetupReturnsSecretAndUri(): void
    {
        $data = $this->ctrl()->setup()->get_data();
        self::assertNotEmpty($data['secret']);
        self::assertStringContainsString('otpauth://totp/', $data['otpauthUri']);
        self::assertSame($data['secret'], (new TwoFactorStore(7))->getPendingSecret());
    }

    public function testActivateWithValidCodeEnablesAndReturnsRecovery(): void
    {
        $ctrl   = $this->ctrl();
        $secret = $ctrl->setup()->get_data()['secret'];
        $code   = (new Totp())->codeAt($secret, time());

        $res = $ctrl->activate($this->codeReq($code));
        $data = $res->get_data();
        self::assertTrue($data['enabled']);
        self::assertCount(10, $data['recoveryCodes']);
        self::assertTrue((new TwoFactorStore(7))->isEnabled());
    }

    public function testActivateRejectsWrongCode(): void
    {
        $ctrl = $this->ctrl();
        $ctrl->setup();
        $res = $ctrl->activate($this->codeReq('000000'));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame(422, $res->get_error_data()['status']);
    }

    public function testDisableRequiresValidCode(): void
    {
        $ctrl   = $this->ctrl();
        $secret = $ctrl->setup()->get_data()['secret'];
        $ctrl->activate($this->codeReq((new Totp())->codeAt($secret, time())));

        self::assertInstanceOf(WP_Error::class, $ctrl->disable($this->codeReq('000000')));
        self::assertTrue((new TwoFactorStore(7))->isEnabled());

        $ok = $ctrl->disable($this->codeReq((new Totp())->codeAt($secret, time())));
        self::assertFalse($ok->get_data()['enabled']);
        self::assertFalse((new TwoFactorStore(7))->isEnabled());
    }

    public function testRecoveryCodeWorksAsValidCode(): void
    {
        $ctrl   = $this->ctrl();
        $secret = $ctrl->setup()->get_data()['secret'];
        $codes  = $ctrl->activate($this->codeReq((new Totp())->codeAt($secret, time())))->get_data()['recoveryCodes'];

        $res = $ctrl->disable($this->codeReq($codes[0]));
        self::assertFalse($res->get_data()['enabled']);
    }
}
