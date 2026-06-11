<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\MeController;
use PHPUnit\Framework\TestCase;

/**
 * MeController::index — devolve a role do user logado + email/name.
 */
final class MeControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['gk_current_user_id'] = 0;
        $GLOBALS['gk_user_caps']       = [];
        $GLOBALS['gk_users']           = [];

        $GLOBALS['wpdb'] = new class () extends \wpdb {
            public string $prefix = 'wp_';

            public function __construct()
            {
            }

            public function prepare($query, ...$args)
            {
                return (string) $query;
            }

            public function get_results($sql, $output = OBJECT)
            {
                return [];
            }
        };
    }

    public function testAnonymousReturnsNullRole(): void
    {
        $resp = (new MeController())->index();
        $data = $resp->get_data();
        self::assertNull($data['role']);
        self::assertSame('', $data['email']);
        self::assertSame('', $data['name']);
    }

    public function testAdminUserReturnsAdminRole(): void
    {
        $GLOBALS['gk_current_user_id'] = 10;
        $GLOBALS['gk_user_caps']['manage_options'] = true;
        $GLOBALS['gk_users'][10] = [
            'ID'           => 10,
            'user_email'   => 'admin@x.com',
            'display_name' => 'Admin',
            'user_login'   => 'admin',
        ];

        $resp = (new MeController())->index();
        $data = $resp->get_data();
        self::assertSame('admin', $data['role']);
        self::assertSame('admin@x.com', $data['email']);
        self::assertSame('Admin', $data['name']);
    }

    public function testFallsBackToLoginWhenDisplayNameEmpty(): void
    {
        $GLOBALS['gk_current_user_id'] = 11;
        $GLOBALS['gk_user_caps']['manage_options'] = true;
        $GLOBALS['gk_users'][11] = [
            'ID'           => 11,
            'user_email'   => 'x@y.z',
            'display_name' => '',
            'user_login'   => 'just-login',
        ];

        $data = (new MeController())->index()->get_data();
        self::assertSame('just-login', $data['name']);
    }
}
