<?php

declare(strict_types=1);

namespace GuardKids\Tests\Integration\Api;

use GuardKids\Api\RestApi;
use GuardKids\Database\GuardianRepository;
use GuardKids\Tests\Integration\IntegrationTestCase;

/**
 * Verifica que os permission_callbacks expostos por RestApi
 * (requireAdmin / requireCollaboratorOrAbove) integram corretamente
 * com GuardianAuth + lookup real em wp_guardkids_guardians.
 */
final class RolePermissionsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['gk_current_user_id'] = 0;
        $GLOBALS['gk_user_caps']       = ['manage_options' => false];
        $GLOBALS['gk_users']           = [];
    }

    public function test_manage_options_user_is_admin(): void
    {
        $GLOBALS['gk_user_caps']['manage_options'] = true;
        $GLOBALS['gk_current_user_id'] = 1;
        $GLOBALS['gk_users'][1] = ['ID' => 1, 'user_email' => 'admin@x.com'];

        self::assertTrue(RestApi::requireAdmin());
        self::assertTrue(RestApi::requireCollaboratorOrAbove());
    }

    public function test_manage_options_overrides_guardian_row_role(): void
    {
        // Regressão do smoke E2E: admin WP cujo email foi cadastrado como
        // collaborator continua sendo admin, porque manage_options é autoridade.
        $GLOBALS['gk_user_caps']['manage_options'] = true;
        $GLOBALS['gk_current_user_id'] = 1;
        $GLOBALS['gk_users'][1] = ['ID' => 1, 'user_email' => 'admin@familia.com'];
        (new GuardianRepository())->insert([
            'wp_user_id' => 1,
            'name'       => 'Admin via collab row',
            'email'      => 'admin@familia.com',
            'role'       => 'collaborator',
            'status'     => 'active',
        ]);

        self::assertTrue(RestApi::requireAdmin());
        self::assertTrue(RestApi::requireCollaboratorOrAbove());
    }

    public function test_collaborator_guardian_passes_collab_but_not_admin(): void
    {
        $GLOBALS['gk_current_user_id'] = 5;
        $GLOBALS['gk_users'][5] = ['ID' => 5, 'user_email' => 'marina@familia.com'];
        (new GuardianRepository())->insert([
            'wp_user_id' => 5,
            'name'       => 'Marina',
            'email'      => 'marina@familia.com',
            'role'       => 'collaborator',
            'status'     => 'active',
        ]);

        self::assertFalse(RestApi::requireAdmin());
        self::assertTrue(RestApi::requireCollaboratorOrAbove());
    }

    public function test_admin_guardian_without_manage_options_passes_admin(): void
    {
        $GLOBALS['gk_current_user_id'] = 6;
        $GLOBALS['gk_users'][6] = ['ID' => 6, 'user_email' => 'cohead@familia.com'];
        (new GuardianRepository())->insert([
            'wp_user_id' => 6,
            'name'       => 'Co-head',
            'email'      => 'cohead@familia.com',
            'role'       => 'admin',
            'status'     => 'active',
        ]);

        self::assertTrue(RestApi::requireAdmin());
        self::assertTrue(RestApi::requireCollaboratorOrAbove());
    }

    public function test_pending_guardian_does_not_grant_access(): void
    {
        $GLOBALS['gk_current_user_id'] = 7;
        $GLOBALS['gk_users'][7] = ['ID' => 7, 'user_email' => 'avo@familia.com'];
        (new GuardianRepository())->insert([
            'wp_user_id' => 7,
            'name'       => 'Avo',
            'email'      => 'avo@familia.com',
            'role'       => 'collaborator',
            'status'     => 'pending',
        ]);

        self::assertFalse(RestApi::requireAdmin());
        self::assertFalse(RestApi::requireCollaboratorOrAbove());
    }

    public function test_email_match_fallback_when_wp_user_id_missing(): void
    {
        $GLOBALS['gk_current_user_id'] = 8;
        $GLOBALS['gk_users'][8] = ['ID' => 8, 'user_email' => 'naofamilia@x.com'];
        (new GuardianRepository())->insert([
            'wp_user_id' => null,
            'name'       => 'Sem WP',
            'email'      => 'naofamilia@x.com',
            'role'       => 'collaborator',
            'status'     => 'active',
        ]);

        self::assertTrue(RestApi::requireCollaboratorOrAbove());
        self::assertFalse(RestApi::requireAdmin());
    }

    public function test_random_logged_user_without_guardian_is_blocked(): void
    {
        $GLOBALS['gk_current_user_id'] = 99;
        $GLOBALS['gk_users'][99] = ['ID' => 99, 'user_email' => 'stranger@nowhere.com'];

        self::assertFalse(RestApi::requireAdmin());
        self::assertFalse(RestApi::requireCollaboratorOrAbove());
    }
}
