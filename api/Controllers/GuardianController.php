<?php

declare(strict_types=1);

namespace GuardKids\Api\Controllers;

use GuardKids\Database\GuardianRepository;
use GuardKids\Invite\InviteToken;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class GuardianController
{
    private readonly GuardianRepository $repo;

    public function __construct()
    {
        $this->repo = new GuardianRepository();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function createArgs(): array
    {
        return [
            'name'  => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            'email' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_email'],
            'role'  => ['type' => 'string', 'enum' => ['admin', 'collaborator']],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function updateRoleArgs(): array
    {
        return [
            'role' => ['type' => 'string', 'required' => true, 'enum' => ['admin', 'collaborator']],
        ];
    }

    public function index(): WP_REST_Response
    {
        $this->ensureSelfPresent();
        return rest_ensure_response(array_map([$this, 'toJson'], $this->repo->findAll('created_at', 'ASC')));
    }

    public function create(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $name  = trim((string) $req->get_param('name'));
        $email = strtolower(trim((string) $req->get_param('email')));
        $role  = (string) ($req->get_param('role') ?? 'collaborator');

        if ($name === '') {
            return new WP_Error('invalid_payload', 'Nome obrigatório.', ['status' => 422]);
        }
        if (! is_email($email)) {
            return new WP_Error('invalid_payload', 'E-mail inválido.', ['status' => 422]);
        }
        if ($this->repo->findByEmail($email) !== null) {
            return new WP_Error('email_exists', 'Já existe um guardião com esse e-mail.', ['status' => 409]);
        }

        $this->ensureSelfPresent();

        $token = InviteToken::generate();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + InviteToken::TTL_SECONDS);

        $id = $this->repo->insert([
            'wp_user_id'        => null,
            'name'              => $name,
            'email'             => $email,
            'role'              => $role,
            'status'            => 'pending',
            'invite_token'      => InviteToken::hash($token),
            'invite_expires_at' => $expiresAt,
        ]);
        if ($id === 0) {
            return new WP_Error('db_error', 'Não foi possível salvar.', ['status' => 500]);
        }

        $url = $this->buildInviteUrl($token);
        $this->sendInviteEmail($email, $name, $url);

        $payload = $this->toJson($this->repo->findById($id) ?? []);
        $payload['inviteUrl']   = $url;
        $payload['inviteToken'] = $token;
        return new WP_REST_Response($payload, 201);
    }

    public function resend(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id  = (int) $req['id'];
        $row = $this->repo->findById($id);
        if ($row === null) {
            return new WP_Error('not_found', 'Guardião não encontrado.', ['status' => 404]);
        }
        if (($row['status'] ?? '') !== 'pending') {
            return new WP_Error(
                'not_pending',
                'Só dá pra reenviar convite de guardião pendente.',
                ['status' => 422],
            );
        }

        $token = InviteToken::generate();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + InviteToken::TTL_SECONDS);

        if (! $this->repo->update($id, [
            'invite_token'      => InviteToken::hash($token),
            'invite_expires_at' => $expiresAt,
        ])) {
            return new WP_Error('db_error', 'Falha ao regenerar convite.', ['status' => 500]);
        }

        $url = $this->buildInviteUrl($token);
        $this->sendInviteEmail((string) $row['email'], (string) $row['name'], $url);

        $payload = $this->toJson($this->repo->findById($id) ?? []);
        $payload['inviteUrl']   = $url;
        $payload['inviteToken'] = $token;
        return rest_ensure_response($payload);
    }

    private function buildInviteUrl(string $token): string
    {
        return home_url('/aceitar-convite/' . $token);
    }

    private function sendInviteEmail(string $email, string $name, string $url): void
    {
        if (! function_exists('wp_mail')) {
            return;
        }
        $subject = sprintf('[%s] Convite pra administrar a familia', wp_specialchars_decode((string) get_bloginfo('name')));
        $body = sprintf(
            "Ola %s,\n\n" .
            "Voce foi convidado pra administrar a familia em %s.\n\n" .
            "Pra aceitar, clique no link abaixo (valido por 7 dias):\n%s\n\n" .
            "Se voce nao esperava esse convite, pode ignorar este e-mail.\n",
            $name,
            home_url('/'),
            $url,
        );
        @wp_mail($email, $subject, $body);
    }

    public function updateRole(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id  = (int) $req['id'];
        $row = $this->repo->findById($id);
        if ($row === null) {
            return new WP_Error('not_found', 'Guardião não encontrado.', ['status' => 404]);
        }
        $role = (string) $req->get_param('role');

        if ($row['role'] === 'admin' && $role !== 'admin' && $this->repo->countAdmins() <= 1) {
            return new WP_Error(
                'last_admin',
                'Não é possível rebaixar o último administrador.',
                ['status' => 422],
            );
        }

        if (! $this->repo->update($id, ['role' => $role])) {
            return new WP_Error('db_error', 'Falha ao atualizar.', ['status' => 500]);
        }
        return rest_ensure_response($this->toJson($this->repo->findById($id) ?? []));
    }

    public function activate(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id  = (int) $req['id'];
        $row = $this->repo->findById($id);
        if ($row === null) {
            return new WP_Error('not_found', 'Guardião não encontrado.', ['status' => 404]);
        }
        if (! $this->repo->update($id, ['status' => 'active'])) {
            return new WP_Error('db_error', 'Falha ao atualizar.', ['status' => 500]);
        }
        return rest_ensure_response($this->toJson($this->repo->findById($id) ?? []));
    }

    public function destroy(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $id  = (int) $req['id'];
        $row = $this->repo->findById($id);
        if ($row === null) {
            return new WP_Error('not_found', 'Guardião não encontrado.', ['status' => 404]);
        }

        $selfId = get_current_user_id();
        if ($selfId > 0 && (int) ($row['wp_user_id'] ?? 0) === $selfId) {
            return new WP_Error('self_delete', 'Você não pode remover a si mesmo.', ['status' => 422]);
        }

        if ($row['role'] === 'admin' && $this->repo->countAdmins() <= 1) {
            return new WP_Error(
                'last_admin',
                'Não é possível remover o último administrador.',
                ['status' => 422],
            );
        }

        if (! $this->repo->delete($id)) {
            return new WP_Error('db_error', 'Falha ao remover.', ['status' => 500]);
        }
        return rest_ensure_response(['deleted' => true, 'id' => $id]);
    }

    /**
     * Garante que o usuário WP atual (manage_options) tem uma linha como admin
     * ativo. Cobre o caso "primeira abertura da seção Família" sem precisar
     * de migration seed (que não tem contexto de user).
     */
    private function ensureSelfPresent(): void
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return;
        }
        if ($this->repo->findByWpUserId($userId) !== null) {
            return;
        }
        $user = get_userdata($userId);
        if (! $user) {
            return;
        }
        $email = strtolower((string) $user->user_email);
        if ($email === '') {
            return;
        }
        if ($this->repo->findByEmail($email) !== null) {
            // E-mail já cadastrado por outro caminho — só anexa o wp_user_id.
            $existing = $this->repo->findByEmail($email);
            if ($existing !== null) {
                $this->repo->update((int) $existing['id'], [
                    'wp_user_id' => $userId,
                    'status'     => 'active',
                ]);
            }
            return;
        }
        $this->repo->insert([
            'wp_user_id' => $userId,
            'name'       => (string) ($user->display_name ?: $user->user_login),
            'email'      => $email,
            'role'       => 'admin',
            'status'     => 'active',
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toJson(array $row): array
    {
        $expiresAt = isset($row['invite_expires_at']) && $row['invite_expires_at']
            ? (string) $row['invite_expires_at']
            : null;
        $invitePending = ($row['status'] ?? '') === 'pending'
            && $expiresAt !== null
            && strtotime($expiresAt . ' UTC') > time();

        return [
            'id'              => (int) ($row['id'] ?? 0),
            'wpUserId'        => isset($row['wp_user_id']) ? (int) $row['wp_user_id'] : null,
            'name'            => (string) ($row['name'] ?? ''),
            'email'           => (string) ($row['email'] ?? ''),
            'role'            => (string) ($row['role'] ?? 'collaborator'),
            'status'          => (string) ($row['status'] ?? 'pending'),
            'invitePending'   => $invitePending,
            'inviteExpiresAt' => $expiresAt,
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
        ];
    }
}
