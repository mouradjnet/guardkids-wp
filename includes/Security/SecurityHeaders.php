<?php

declare(strict_types=1);

namespace GuardKids\Security;

/**
 * Security headers globais em todas as respostas PHP do WP. Hookado em
 * `send_headers` (dispara antes do WP escrever o corpo da resposta).
 *
 * Espelha o snippet `tools/deploy/htaccess-security.txt` pra que os headers
 * viajem com o plugin, sem SSH manual a cada ambiente. Cobre itens 15-20 da
 * auditoria do site público (HSTS / CSP / nosniff / X-Frame-Options /
 * Referrer-Policy / Permissions-Policy).
 *
 * Limitação consciente: `send_headers` só roda em respostas renderizadas pelo
 * WP. Assets estáticos servidos direto pelo Apache (imagens/CSS/JS) não passam
 * por aqui — pra cobertura total nesses, o snippet `.htaccess` continua sendo
 * a opção mais forte.
 */
final class SecurityHeaders
{
    /**
     * CSP idêntica ao snippet `.htaccess`: 'unsafe-eval' liberado pro block
     * editor (Gutenberg), Google Fonts liberado pra UI do plugin.
     */
    private const CSP = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; "
        . "connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; "
        . "form-action 'self'; upgrade-insecure-requests";

    private const PERMISSIONS_POLICY = 'accelerometer=(), autoplay=(), browsing-topics=(), '
        . 'camera=(self), gyroscope=(), magnetometer=(), microphone=(), midi=(), '
        . 'payment=(), usb=(), interest-cohort=()';

    public function register(): void
    {
        add_action('send_headers', [$this, 'send']);
    }

    /**
     * Callback do hook `send_headers`. Idempotente; não escreve nada se os
     * headers já tiverem sido enviados.
     */
    public function send(): void
    {
        if (headers_sent()) {
            return;
        }

        foreach ($this->headers(is_ssl()) as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    /**
     * Mapa de headers a enviar. Método puro (sem efeito colateral) pra ser
     * testável sem disparar `header()`.
     *
     * HSTS só sai sob HTTPS: em HTTP o browser ignora o header e em dev
     * (LocalWP via HTTP) ele só polui a resposta.
     *
     * @return array<string, string>
     */
    public function headers(bool $isSsl): array
    {
        $headers = [
            'Content-Security-Policy' => self::CSP,
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'SAMEORIGIN',
            'Referrer-Policy'         => 'strict-origin-when-cross-origin',
            'Permissions-Policy'      => self::PERMISSIONS_POLICY,
        ];

        if ($isSsl) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains; preload';
        }

        return $headers;
    }
}
