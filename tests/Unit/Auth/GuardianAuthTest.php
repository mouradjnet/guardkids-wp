<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Auth;

use GuardKids\Auth\GuardianAuth;
use GuardKids\Database\GuardianRepository;
use PHPUnit\Framework\TestCase;

/**
 * GuardianAuth::currentRole — resolve role efetiva a partir de capabilities
 * e linha em guardians (match por wp_user_id ou email).
 */
final class GuardianAuthTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        $GLOBALS['gk_current_user_id'] = 0;
        $GLOBALS['gk_user_caps']       = [];
        $GLOBALS['gk_users']           = [];

        $this->wpdb = new class () extends \wpdb {
            public string $prefix = 'wp_';
            /** @var array<int, array<string, mixed>> */
            public array $rowsByEmail = [];
            /** @var array<int, array<string, mixed>> */
            public array $rowsByWpUserId = [];

            public function __construct()
            {
            }

            public function prepare($query, ...$args)
            {
                $flat = $args[0] ?? null;
                if (is_array($flat)) {
                    $args = $flat;
                }
                return vsprintf(str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%F'], (string) $query), $args);
            }

            public function get_results($sql, $output = OBJECT)
            {
                if (str_contains((string) $sql, "wp_user_id =")) {
                    return $this->rowsByWpUserId;
                }
                if (str_contains((string) $sql, 'email =')) {
                    return $this->rowsByEmail;
                }
                return [];
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;
    }

    public function testAnonymousReturnsNull(): void
    {
        self::assertNull(GuardianAuth::currentRole(new GuardianRepository()));
    }

    public function testManageOptionsAlwaysReturnsAdmin(): void
    {
        $GLOBALS['gk_user_caps']['manage_options'] = true;
        $GLOBALS['gk_current_user_id'] = 10;
        $GLOBALS['gk_users'][10] = ['ID' => 10, 'user_email' => 'admin@x.com'];

        self::assertSame('admin', GuardianAuth::currentRole(new GuardianRepository()));
    }

    public function testGuardianAdminActiveByWpUserIdReturnsAdmin(): void
    {
        $GLOBALS['gk_current_user_id'] = 5;
        $this->wpdb->rowsByWpUserId = [
            ['id' => 1, 'wp_user_id' => 5, 'role' => 'admin', 'status' => 'active', 'email' => 'a@b.c'],
        ];

        self::assertSame('admin', GuardianAuth::currentRole(new GuardianRepository()));
    }

    public function testGuardianCollaboratorActiveReturnsCollaborator(): void
    {
        $GLOBALS['gk_current_user_id'] = 5;
        $this->wpdb->rowsByWpUserId = [
            ['id' => 2, 'wp_user_id' => 5, 'role' => 'collaborator', 'status' => 'active', 'email' => 'c@x.com'],
        ];

        self::assertSame('collaborator', GuardianAuth::currentRole(new GuardianRepository()));
    }

    public function testFallsBackToEmailMatchWhenNoWpUserIdRow(): void
    {
        $GLOBALS['gk_current_user_id'] = 7;
        $GLOBALS['gk_users'][7] = ['ID' => 7, 'user_email' => 'marina@familia.com'];
        $this->wpdb->rowsByWpUserId = [];
        $this->wpdb->rowsByEmail = [
            ['id' => 3, 'wp_user_id' => null, 'role' => 'collaborator', 'status' => 'active', 'email' => 'marina@familia.com'],
        ];

        self::assertSame('collaborator', GuardianAuth::currentRole(new GuardianRepository()));
    }

    public function testPendingStatusReturnsNullEvenWithMatch(): void
    {
        $GLOBALS['gk_current_user_id'] = 5;
        $this->wpdb->rowsByWpUserId = [
            ['id' => 1, 'wp_user_id' => 5, 'role' => 'collaborator', 'status' => 'pending', 'email' => 'x@y.z'],
        ];

        self::assertNull(GuardianAuth::currentRole(new GuardianRepository()));
    }

    public function testLoggedInWithoutGuardianAndWithoutManageReturnsNull(): void
    {
        $GLOBALS['gk_current_user_id'] = 99;
        $GLOBALS['gk_users'][99] = ['ID' => 99, 'user_email' => 'random@noone.com'];

        self::assertNull(GuardianAuth::currentRole(new GuardianRepository()));
    }

    public function testIsAdminConvenience(): void
    {
        $GLOBALS['gk_user_caps']['manage_options'] = true;
        self::assertTrue(GuardianAuth::isAdmin());
        self::assertTrue(GuardianAuth::isCollaboratorOrAbove());
    }

    public function testIsCollaboratorOrAboveAcceptsCollaborator(): void
    {
        $GLOBALS['gk_current_user_id'] = 5;
        $this->wpdb->rowsByWpUserId = [
            ['id' => 2, 'wp_user_id' => 5, 'role' => 'collaborator', 'status' => 'active'],
        ];

        self::assertFalse(GuardianAuth::isAdmin());
        self::assertTrue(GuardianAuth::isCollaboratorOrAbove());
    }
}
