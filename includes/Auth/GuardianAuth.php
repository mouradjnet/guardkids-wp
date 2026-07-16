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
        // WP `manage_options` é autoridade final: um admin WP pode sempre
        // mexer em tudo via wp-admin direto, então a UI/REST também respeita.
        // Uma entry em guardians como `collaborator` não rebaixa o admin.
        $isWpAdmin = function_exists('current_user_can') && current_user_can('manage_options');
        if ($isWpAdmin) {
            return 'admin';
        }

        $userId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($userId === 0) {
            return null;
        }

        $repo ??= new GuardianRepository();

        $row = $repo->findByWpUserId($userId);
        if ($row === null && function_exists('get_userdata')) {
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

        return null;
    }

    /**
     * Um usuário arbitrário pode receber push de guardião?
     *
     * Espelha currentRole(), mas pra um user id qualquer: no momento do envio
     * quem fez a request foi a CRIANÇA, então não há guardião logado e
     * current_user_can() não serve — daí user_can($id, ...).
     *
     * Inclui o admin WP sem linha em `guardians` de propósito: currentRole()
     * dá 'admin' por manage_options, então resolver destinatários só por
     * GuardianRepository::findActive() deixaria o dono da instalação sem push.
     *
     * Não faz o fallback por email que o currentRole() faz: a subscription
     * sempre grava um wp_user_id real.
     */
    public static function isActiveGuardian(int $wpUserId, ?GuardianRepository $repo = null): bool
    {
        if ($wpUserId <= 0) {
            return false;
        }

        if (function_exists('user_can') && user_can($wpUserId, 'manage_options')) {
            return true;
        }

        $repo ??= new GuardianRepository();
        $row = $repo->findByWpUserId($wpUserId);

        return $row !== null && ($row['status'] ?? '') === 'active';
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
