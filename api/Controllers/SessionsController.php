<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Security\SessionManager;
use WP_REST_Response;

/**
 * Sessões ativas do usuário logado. Auth via `requireAdmin`. Só lista e encerra
 * as OUTRAS (a atual é preservada; "sair daqui" é o logout do menu).
 */
final class SessionsController
{
    public function __construct(private readonly ?SessionManager $manager = null)
    {
    }

    private function manager(): SessionManager
    {
        return $this->manager ?? new SessionManager();
    }

    public function index(): WP_REST_Response
    {
        return \rest_ensure_response(['sessions' => $this->manager()->list()]);
    }

    public function destroyOthers(): WP_REST_Response
    {
        $manager   = $this->manager();
        $destroyed = $manager->destroyOthers();
        return \rest_ensure_response(['destroyed' => $destroyed, 'sessions' => $manager->list()]);
    }
}
