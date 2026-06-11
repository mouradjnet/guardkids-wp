<?php

declare(strict_types=1);

namespace GuardKids\Auth;

use GuardKids\Database\GuardianRepository;

/**
 * Resolve a role efetiva do usuário WP logado dentro do contexto guardkids.
 *
 * - `admin`: tem `manage_options`, OU é uma linha em `guardians` com role=admin e status=active.
 * - `collaborator`: linha em `guardians` com role=collaborator e status=active.
 * - `null`: nenhuma das opções (anônimo ou WP user sem ligação com guardians).
 *
 * O match com guardians é por `wp_user_id` primeiro; se faltar, cai pra
 * `user_email` (case-insensitive). Isso cobre o fluxo "admin cria WP user
 * + cadastra como guardian" sem precisar de accept-invite.
 */
final class GuardianAuth
{
    /**
     * @return 'admin'|'collaborator'|null
     */
    public static function currentRole(?GuardianRepository $repo = null): ?string
    {
        $userId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;

        $isWpAdmin = function_exists('current_user_can') && current_user_can('manage_options');
        if ($userId === 0 && ! $isWpAdmin) {
            return null;
        }

        $repo ??= new GuardianRepository();

        $row = $userId > 0 ? $repo->findByWpUserId($userId) : null;
        if ($row === null && $userId > 0 && function_exists('get_userdata')) {
            $user = get_userdata($userId);
            if ($user) {
                $email = strtolower((string) ($user->user_email ?? ''));
                if ($email !== '') {
                    $row = $repo->findByEmail($email);
                }
            }
        }

        if ($row !== null && ($row['status'] ?? '') === 'active') {
            $role = (string) ($row['role'] ?? 'collaborator');
            if ($role === 'admin' || $role === 'collaborator') {
                return $role;
            }
        }

        return $isWpAdmin ? 'admin' : null;
    }

    public static function isAdmin(): bool
    {
        return self::currentRole() === 'admin';
    }

    public static function isCollaboratorOrAbove(): bool
    {
        $role = self::currentRole();
        return $role === 'admin' || $role === 'collaborator';
    }
}
