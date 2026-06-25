<?php

declare(strict_types=1);

namespace GuardKids\Security;

use WP_Session_Tokens;

/**
 * Camada fina sobre o WP_Session_Tokens do usuário atual. A lógica pura de
 * apresentação vive no {@see SessionPresenter}; aqui só buscamos/destruímos.
 * Não-final de propósito: o teste do controller injeta um fake.
 */
class SessionManager
{
    public function __construct(private readonly ?int $userId = null)
    {
    }

    private function uid(): int
    {
        return $this->userId ?? (int) \get_current_user_id();
    }

    /**
     * @return array<int, array{device: string, browser: string, os: string, ip: string, lastAccess: int, current: bool}>
     */
    public function list(): array
    {
        $tokens = WP_Session_Tokens::get_instance($this->uid());
        $all = $tokens->get_all();
        $currentToken = \wp_get_session_token();
        $currentData = $currentToken !== '' ? $tokens->get($currentToken) : null;
        return SessionPresenter::present(
            is_array($all) ? $all : [],
            is_array($currentData) ? $currentData : null,
        );
    }

    public function destroyOthers(): int
    {
        $tokens = WP_Session_Tokens::get_instance($this->uid());
        $before = count($tokens->get_all());
        $currentToken = \wp_get_session_token();
        if ($currentToken !== '') {
            $tokens->destroy_others($currentToken);
        }
        $after = count($tokens->get_all());
        return max(0, $before - $after);
    }
}
