<?php

declare(strict_types=1);

namespace GuardKids\Ui;

/**
 * Serve a SPA `app-child` numa URL standalone (`/painel-filho`).
 *
 * Diferente do ParentApp, esta rota é **pública** — quem autentica é o
 * token de dispositivo (X-GuardKids-Token) gerenciado pelo próprio SPA
 * via tela de pareamento. Sem auth do WordPress aqui.
 */
final class ChildApp
{
    private const ROUTE_SLUG = 'painel-filho';
    private const QUERY_VAR  = 'guardkids_app';
    private const APP_NAME   = 'child';

    public function register(): void
    {
        add_action('init', [$this, 'addRewriteRule']);
        add_action('template_redirect', [$this, 'maybeServe']);
    }

    public function addRewriteRule(): void
    {
        add_rewrite_rule(
            '^' . self::ROUTE_SLUG . '/?$',
            'index.php?' . self::QUERY_VAR . '=' . self::APP_NAME,
            'top'
        );
    }

    public function maybeServe(): void
    {
        $app = get_query_var(self::QUERY_VAR);
        if ($app !== self::APP_NAME) {
            return;
        }

        $distDir = GUARDKIDS_DIR . 'public/app-child/dist/';
        $manifestPath = $distDir . '.vite/manifest.json';
        if (! is_readable($manifestPath)) {
            status_header(503);
            nocache_headers();
            echo '<!doctype html><meta charset="utf-8"><title>GuardKids — build pendente</title>';
            echo '<p style="font-family:system-ui;padding:2rem;max-width:60ch;margin:auto">';
            echo 'Build do <code>app-child</code> não encontrado. ';
            echo 'Rode <code>pnpm install &amp;&amp; pnpm build</code> em ';
            echo '<code>public/app-child/</code> e tente de novo.';
            echo '</p>';
            exit;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest)) {
            status_header(500);
            exit('GuardKids: manifest do Vite invalido.');
        }

        $entry = null;
        foreach ($manifest as $item) {
            if (is_array($item) && ! empty($item['isEntry'])) {
                $entry = $item;
                break;
            }
        }
        if ($entry === null) {
            status_header(500);
            exit('GuardKids: entry point nao encontrado no manifest.');
        }

        $distUrl  = plugins_url('public/app-child/dist/', GUARDKIDS_FILE);
        $jsFile   = isset($entry['file']) ? $distUrl . $entry['file'] : '';
        $cssFiles = isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');

        echo '<!doctype html>' . "\n";
        echo '<html lang="pt-BR">' . "\n";
        echo '<head>' . "\n";
        echo '  <meta charset="UTF-8">' . "\n";
        echo '  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">' . "\n";
        echo '  <meta name="theme-color" content="#2563EB">' . "\n";
        echo '  <title>GuardKids — Painel Infantil</title>' . "\n";
        echo '  <link rel="manifest" href="' . esc_url(plugins_url('public/app-child/public/manifest.webmanifest', GUARDKIDS_FILE)) . '">' . "\n";
        echo '  <link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
        echo '  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
        echo '  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">' . "\n";
        echo '  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet">' . "\n";
        foreach ($cssFiles as $cssFile) {
            $cssUrl = $distUrl . $cssFile;
            echo '  <link rel="stylesheet" href="' . esc_url($cssUrl) . '">' . "\n";
        }
        echo '</head>' . "\n";
        echo '<body class="bg-surface">' . "\n";
        echo '  <div id="root"></div>' . "\n";
        echo '  <script type="module" src="' . esc_url($jsFile) . '"></script>' . "\n";
        echo '</body>' . "\n";
        echo '</html>';

        exit;
    }
}
