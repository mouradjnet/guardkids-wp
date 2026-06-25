<?php

declare(strict_types=1);

namespace GuardKids\Security;

use WP_User;

/**
 * Exige o 2º fator no wp-login.php pra usuários com 2FA ativa. Modelado no
 * fluxo do plugin oficial Two-Factor: o filtro `authenticate` (depois da senha)
 * intercepta, limpa o cookie, emite um login_nonce de vida curta e renderiza a
 * tela do código; o POST é validado em `login_form_guardkids_2fa`.
 *
 * A lógica pura de verificação de código vive em `passes()` (testável); o resto
 * é I/O do wp-login.
 */
final class TwoFactorLogin
{
    public const ACTION = 'guardkids_2fa';

    private readonly Totp $totp;
    private readonly RecoveryCodes $recovery;

    public function __construct(?Totp $totp = null, ?RecoveryCodes $recovery = null)
    {
        $this->totp     = $totp ?? new Totp();
        $this->recovery = $recovery ?? new RecoveryCodes();
    }

    public function register(): void
    {
        add_filter('authenticate', [$this, 'maybeIntercept'], 30, 1);
        add_action('login_form_' . self::ACTION, [$this, 'handleValidation']);
    }

    /**
     * Verifica TOTP ou código de recuperação (consumindo-o). Fail-closed.
     */
    public function passes(TwoFactorStore $store, string $code): bool
    {
        if ($this->totp->verify($store->getSecret(), $code)) {
            return true;
        }
        $consumed = $this->recovery->verifyAndConsume($code, $store->getRecoveryHashes());
        if ($consumed !== null) {
            $store->setRecoveryHashes($consumed);
            return true;
        }
        return false;
    }

    /**
     * Filtro `authenticate` (prioridade 30, depois da senha). Se o login deu
     * certo e o usuário tem 2FA, mostra a tela do código e encerra.
     *
     * @param mixed $user
     * @return mixed
     */
    public function maybeIntercept($user)
    {
        if (! ($user instanceof WP_User)) {
            return $user;
        }
        $store = new TwoFactorStore($user->ID);
        if (! $store->isEnabled()) {
            return $user;
        }

        $nonce = bin2hex(random_bytes(16));
        $store->setLoginNonce($nonce);
        wp_clear_auth_cookie();

        $redirectTo = isset($_REQUEST['redirect_to']) ? (string) $_REQUEST['redirect_to'] : admin_url();
        $this->renderForm($user->ID, $nonce, $redirectTo, false);
        exit;
    }

    /**
     * Ação `login_form_guardkids_2fa`: valida o nonce + o código e completa o
     * login (seta o cookie) ou re-renderiza com erro.
     */
    public function handleValidation(): void
    {
        $userId     = isset($_POST['wp-auth-id']) ? (int) $_POST['wp-auth-id'] : 0;
        $nonce      = isset($_POST['wp-auth-nonce']) ? (string) $_POST['wp-auth-nonce'] : '';
        $code       = isset($_POST['gk-2fa-code']) ? (string) wp_unslash($_POST['gk-2fa-code']) : '';
        $redirectTo = isset($_POST['redirect_to']) ? (string) $_POST['redirect_to'] : admin_url();

        $store = new TwoFactorStore($userId);
        if ($userId <= 0 || ! $store->isEnabled() || ! $store->verifyLoginNonce($nonce)) {
            wp_safe_redirect(wp_login_url());
            exit;
        }

        $limiter = new RateLimiter(10);
        if (! $limiter->allow('2fa-login', $userId) || ! $this->passes($store, $code)) {
            $newNonce = bin2hex(random_bytes(16));
            $store->setLoginNonce($newNonce);
            $this->renderForm($userId, $newNonce, $redirectTo, true);
            exit;
        }

        $store->clearLoginNonce();
        wp_set_auth_cookie($userId, true);
        wp_safe_redirect($redirectTo !== '' ? $redirectTo : admin_url());
        exit;
    }

    private function renderForm(int $userId, string $nonce, string $redirectTo, bool $error): void
    {
        login_header(__('Verificação em duas etapas', 'guardkids'));
        if ($error) {
            echo '<div id="login_error">' . esc_html__('Código inválido. Tente de novo.', 'guardkids') . '</div>';
        }
        $action = esc_url(site_url('wp-login.php?action=' . self::ACTION, 'login_post'));
        echo '<form name="validate_2fa_form" id="loginform" action="' . $action . '" method="post">';
        echo '<p><label for="gk-2fa-code">' . esc_html__('Código do app autenticador (ou um código de recuperação)', 'guardkids') . '</label>';
        echo '<input type="text" name="gk-2fa-code" id="gk-2fa-code" class="input" autocomplete="one-time-code" inputmode="numeric" autofocus></p>';
        echo '<input type="hidden" name="wp-auth-id" value="' . (int) $userId . '">';
        echo '<input type="hidden" name="wp-auth-nonce" value="' . esc_attr($nonce) . '">';
        echo '<input type="hidden" name="redirect_to" value="' . esc_attr($redirectTo) . '">';
        echo '<p class="submit"><input type="submit" class="button button-primary button-large" value="' . esc_attr__('Verificar', 'guardkids') . '"></p>';
        echo '</form>';
        login_footer();
    }
}
