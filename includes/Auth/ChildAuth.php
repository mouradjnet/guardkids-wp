<?php

declare(strict_types=1);

namespace GuardKids\Auth;

use GuardKids\Database\SettingsRepository;
use WP_Error;
use WP_REST_Request;

/**
 * Auth simples pro app-child via token de dispositivo.
 *
 * Fluxo: o responsável logado no app-parent chama
 * `POST /children/{id}/pair` que gera um token aleatório de 32 bytes
 * (64 chars hex), salva no `wp_guardkids_settings` com a chave
 * `child_token:<sha256(token)>` e devolve o token plain text UMA vez.
 *
 * O dispositivo da criança guarda o token em localStorage e o envia em
 * todas as chamadas no header `X-GuardKids-Token`. Aqui hashamos de novo
 * e procuramos a chave — se achar, o `childId` do payload sai como
 * sujeito autenticado.
 *
 * Defesa em profundidade: o DB nunca vê o token em claro; se o dump
 * vazar, o atacante não consegue se passar por criança sem brute
 * force de SHA-256 sobre 256 bits de entropia (inviável).
 */
final class ChildAuth
{
    private const HEADER       = 'X-GuardKids-Token';
    private const KEY_PREFIX   = 'child_token:';
    private const TOKEN_BYTES  = 32;
    public  const TOKEN_LENGTH = self::TOKEN_BYTES * 2;

    private readonly SettingsRepository $settings;

    public function __construct()
    {
        $this->settings = new SettingsRepository();
    }

    /**
     * Gera um token novo, persiste o hash e devolve o token em claro.
     *
     * @return array{token: string, hash: string}
     */
    public function issueToken(int $childId, ?string $label = null): array
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $hash  = hash('sha256', $token);
        $this->settings->set(self::KEY_PREFIX . $hash, [
            'childId'   => $childId,
            'label'     => $label,
            'createdAt' => gmdate('c'),
        ]);
        return ['token' => $token, 'hash' => $hash];
    }

    /**
     * Lê o token do request, valida e devolve o childId, ou null.
     */
    public function resolveChildId(WP_REST_Request $request): ?int
    {
        $raw = (string) $request->get_header(self::HEADER);
        if ($raw === '' || strlen($raw) !== self::TOKEN_LENGTH) {
            return null;
        }
        if (preg_match('/^[a-f0-9]+$/i', $raw) !== 1) {
            return null;
        }

        $hash = hash('sha256', strtolower($raw));
        $data = $this->settings->get(self::KEY_PREFIX . $hash);
        if (! is_array($data) || ! isset($data['childId'])) {
            return null;
        }

        return (int) $data['childId'];
    }

    /**
     * `permission_callback` para rotas do child.
     *
     * @return \Closure(WP_REST_Request): (true|WP_Error)
     */
    public function requireToken(): \Closure
    {
        return function (WP_REST_Request $request): true|WP_Error {
            if ($this->resolveChildId($request) === null) {
                return new WP_Error(
                    'child_auth_required',
                    'Token de dispositivo inválido ou ausente.',
                    ['status' => 401]
                );
            }
            return true;
        };
    }
}
