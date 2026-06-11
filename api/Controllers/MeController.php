<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Auth\GuardianAuth;
use WP_REST_Response;

/**
 * GET /me — devolve a role efetiva do user logado dentro do guardkids.
 *
 * O frontend usa pra esconder seções (Settings/Children/etc) quando o
 * user é apenas collaborator. Permission callback é `__return_true`
 * porque o response ja distingue (anônimo retorna role=null).
 */
final class MeController
{
    public function index(): WP_REST_Response
    {
        $role = GuardianAuth::currentRole();

        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        $email = $user && isset($user->user_email) ? (string) $user->user_email : '';
        $name  = $user && isset($user->display_name) && $user->display_name !== ''
            ? (string) $user->display_name
            : ($user && isset($user->user_login) ? (string) $user->user_login : '');

        return rest_ensure_response([
            'role'  => $role,
            'email' => $email,
            'name'  => $name,
        ]);
    }
}
