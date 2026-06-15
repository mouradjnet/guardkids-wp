<?php

declare(strict_types=1);

namespace GuardKids\Ui;

use GuardKids\Database\GuardianRepository;
use GuardKids\Invite\InviteToken;

/**
 * Serve a página pública `/aceitar-convite/{token}`.
 *
 * GET: valida token + expira; mostra form pra collaborator definir senha
 * (ou faz login direto se o e-mail já bate com um WP user existente).
 * POST: cria WP user (subscriber) OU liga ao existente, consome o convite,
 * faz auto-login e redireciona pra /painel-pais.
 *
 * Sem auth na entrada (anyone com o token entra). Auth real vem do match
 * hash do token contra a coluna `invite_token` (hash sha256).
 */
final class AcceptInviteApp
{
    private const ROUTE_REGEX = '^aceitar-convite/([a-f0-9]{64})/?$';
    private const QUERY_VAR   = 'guardkids_invite_token';

    public function register(): void
    {
        add_action('init', [$this, 'addRewriteRule']);
        add_filter('query_vars', [$this, 'addQueryVar']);
        add_action('template_redirect', [$this, 'maybeServe']);
    }

    public function addRewriteRule(): void
    {
        add_rewrite_rule(
            self::ROUTE_REGEX,
            'index.php?' . self::QUERY_VAR . '=$matches[1]',
            'top',
        );
    }

    /**
     * @param array<int, string> $vars
     * @return array<int, string>
     */
    public function addQueryVar(array $vars): array
    {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public function maybeServe(): void
    {
        $token = get_query_var(self::QUERY_VAR);
        if (! is_string($token) || $token === '') {
            return;
        }
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            $this->renderError('Token inválido.', 400);
            exit;
        }

        $repo = new GuardianRepository();
        $row  = $repo->findByInviteTokenHash(InviteToken::hash($token));

        if ($row === null) {
            $this->renderError('Esse link de convite não vale mais ou nunca existiu.', 404);
            exit;
        }
        $expiresAt = isset($row['invite_expires_at']) && is_string($row['invite_expires_at'])
            ? strtotime($row['invite_expires_at'] . ' UTC')
            : 0;
        if ($expiresAt > 0 && $expiresAt < time()) {
            $this->renderError('Esse convite expirou. Peça pro admin reenviar.', 410);
            exit;
        }
        if (($row['status'] ?? '') !== 'pending') {
            $this->renderError('Esse convite já foi usado.', 409);
            exit;
        }

        $email = (string) $row['email'];
        $existingUser = email_exists($email) ? get_user_by('email', $email) : null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleAccept($repo, $row, $existingUser, $token);
            exit;
        }

        $this->renderForm($row, $existingUser, $token);
        exit;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function handleAccept(
        GuardianRepository $repo,
        array $row,
        ?\WP_User $existingUser,
        string $token,
    ): void {
        check_admin_referer('guardkids_accept_invite_' . $token);

        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $passwordConfirm = isset($_POST['password_confirm']) ? (string) $_POST['password_confirm'] : '';

        if ($existingUser === null) {
            if (strlen($password) < 8) {
                $this->renderForm($row, $existingUser, $token, 'A senha precisa ter pelo menos 8 caracteres.');
                return;
            }
            if ($password !== $passwordConfirm) {
                $this->renderForm($row, $existingUser, $token, 'As senhas não batem.');
                return;
            }
            $email = (string) $row['email'];
            $login = $this->makeUniqueLogin($email);
            $userId = wp_create_user($login, $password, $email);
            if (is_wp_error($userId)) {
                $this->renderError('Não foi possível criar a conta: ' . $userId->get_error_message(), 500);
                return;
            }
            $user = new \WP_User($userId);
            $user->set_role('subscriber');
            $user->display_name = (string) $row['name'];
            wp_update_user($user);
        } else {
            $userId = (int) $existingUser->ID;
        }

        if (! $repo->consumeInvite((int) $row['id'], $userId)) {
            $this->renderError('Falha ao registrar a aceitação. Tente de novo.', 500);
            return;
        }

        wp_set_current_user($userId);
        // remember=false: cookie de sessão (válido até fechar browser).
        // Convite admin não merece persistência de 14d — exige re-login
        // após reboot/timeout, reduzindo janela de hijack se device for
        // perdido. Trade-off de UX aceito.
        wp_set_auth_cookie($userId, false);

        wp_safe_redirect(home_url('/painel-pais'));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function renderForm(array $row, ?\WP_User $existingUser, string $token, string $error = ''): void
    {
        $nonceAction = 'guardkids_accept_invite_' . $token;
        $siteName    = (string) get_bloginfo('name');
        $name        = (string) $row['name'];
        $email       = (string) $row['email'];

        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');

        echo '<!doctype html>' . "\n";
        echo '<html lang="pt-BR"><head>' . "\n";
        echo '<meta charset="UTF-8">' . "\n";
        echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n";
        echo '<title>Aceitar convite — ' . esc_html($siteName) . '</title>' . "\n";
        echo '<style>body{font-family:system-ui,-apple-system,sans-serif;background:#f5f6fa;color:#111;margin:0;padding:2rem;display:flex;justify-content:center;align-items:center;min-height:100vh}.card{background:#fff;border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,.08);padding:2rem;max-width:420px;width:100%}h1{margin:0 0 .5rem;font-size:1.5rem}p{color:#555;line-height:1.5}label{display:block;margin-top:1rem;font-size:.9rem;font-weight:600}input{width:100%;padding:.6rem;margin-top:.3rem;border:1px solid #d0d4dc;border-radius:8px;font-size:1rem;box-sizing:border-box}button{margin-top:1.5rem;background:#00236f;color:#fff;border:0;border-radius:8px;padding:.8rem 1rem;font-size:1rem;font-weight:600;cursor:pointer;width:100%}button:hover{background:#1b3a8a}.error{background:#fde8e8;color:#9b1c1c;padding:.75rem 1rem;border-radius:8px;margin-top:1rem;font-size:.9rem}.hint{font-size:.85rem;color:#666;margin-top:.4rem}</style>' . "\n";
        echo '</head><body><div class="card">' . "\n";
        echo '<h1>Convite pra administrar</h1>' . "\n";
        echo '<p>Olá, <strong>' . esc_html($name) . '</strong>. Aceite o convite pra acessar o painel dos pais em <em>' . esc_html($siteName) . '</em>.</p>' . "\n";

        if ($error !== '') {
            echo '<div class="error">' . esc_html($error) . '</div>' . "\n";
        }

        echo '<form method="post">' . "\n";
        wp_nonce_field($nonceAction);
        echo '<label>E-mail</label><input type="email" value="' . esc_attr($email) . '" disabled>' . "\n";

        if ($existingUser !== null) {
            echo '<p class="hint">Você já tem uma conta com esse e-mail. Clique abaixo pra aceitar usando ela.</p>' . "\n";
            echo '<button type="submit">Aceitar convite</button>' . "\n";
        } else {
            echo '<label>Senha (mín. 8 caracteres)</label><input type="password" name="password" minlength="8" required autocomplete="new-password">' . "\n";
            echo '<label>Confirmar senha</label><input type="password" name="password_confirm" minlength="8" required autocomplete="new-password">' . "\n";
            echo '<button type="submit">Criar conta e aceitar</button>' . "\n";
        }
        echo '</form></div></body></html>';
    }

    private function renderError(string $message, int $status): void
    {
        status_header($status);
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');

        echo '<!doctype html>' . "\n";
        echo '<html lang="pt-BR"><head><meta charset="UTF-8"><title>Convite</title>' . "\n";
        echo '<style>body{font-family:system-ui,sans-serif;max-width:540px;margin:4rem auto;padding:2rem;color:#333}h1{font-size:1.4rem}p{line-height:1.5}</style>' . "\n";
        echo '</head><body><h1>Convite indisponível</h1>' . "\n";
        echo '<p>' . esc_html($message) . '</p></body></html>';
    }

    private function makeUniqueLogin(string $email): string
    {
        $base = sanitize_user(strtok($email, '@') ?: 'guardian', true);
        if ($base === '') {
            $base = 'guardian';
        }
        $candidate = $base;
        $i = 2;
        while (username_exists($candidate)) {
            $candidate = $base . $i;
            $i++;
            if ($i > 9999) {
                $candidate = $base . '_' . wp_generate_password(6, false);
                break;
            }
        }
        return $candidate;
    }
}
