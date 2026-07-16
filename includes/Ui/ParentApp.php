<?php

declare(strict_types=1);

namespace GuardKids\Ui;

use GuardKids\Auth\GuardianAuth;

/**
 * Serve a SPA `app-parent` numa URL standalone (`/painel-pais`), sem chrome do
 * wp-admin, exigindo `manage_options`. Le `dist/.vite/manifest.json` pra resolver
 * o nome do bundle (hash) gerado pelo Vite e injeta `window.guardkidsApi` com o
 * nonce REST.
 */
final class ParentApp
{
    private const ROUTE_SLUG = 'painel-pais';
    private const QUERY_VAR  = 'guardkids_app';
    private const APP_NAME   = 'parent';

    public function register(): void
    {
        add_action('init', [$this, 'addRewriteRule']);
        add_filter('query_vars', [$this, 'addQueryVar']);
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
        $app = get_query_var(self::QUERY_VAR);
        if ($app !== self::APP_NAME) {
            return;
        }

        if (! is_user_logged_in() || ! GuardianAuth::isCollaboratorOrAbove()) {
            auth_redirect();
            exit;
        }

        $distDir = GUARDKIDS_DIR . 'public/app-parent/dist/';
        $manifestPath = $distDir . '.vite/manifest.json';
        if (! is_readable($manifestPath)) {
            status_header(503);
            nocache_headers();
            echo '<!doctype html><meta charset="utf-8"><title>GuardKids — build pendente</title>';
            echo '<p style="font-family:system-ui;padding:2rem;max-width:60ch;margin:auto">';
            echo 'Build do <code>app-parent</code> não encontrado. ';
            echo 'Rode <code>pnpm install &amp;&amp; pnpm build</code> em ';
            echo '<code>public/app-parent/</code> e tente de novo.';
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

        $distUrl  = plugins_url('public/app-parent/dist/', GUARDKIDS_FILE);
        $jsFile   = isset($entry['file']) ? $distUrl . $entry['file'] : '';
        $cssFiles = isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

        $nonce = wp_create_nonce('wp_rest');
        $root  = esc_url_raw(rest_url('guardkids/v1'));

        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');

        echo '<!doctype html>' . "\n";
        echo '<html lang="pt-BR">' . "\n";
        echo '<head>' . "\n";
        echo '  <meta charset="UTF-8">' . "\n";
        echo '  <meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n";
        echo '  <title>GuardKids WP — Painel dos Pais</title>' . "\n";
        echo '  <link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
        echo '  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
        echo '  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">' . "\n";
        echo '  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet">' . "\n";
        // Manifest: torna o painel instalável. É o que o iOS exige pra entregar
        // Web Push (Safari só faz push em site na Tela de Início). NÃO faz do
        // painel um PWA offline — não há precache nem estratégia de cache.
        echo '  <link rel="manifest" href="' . esc_url($distUrl . 'manifest.webmanifest') . '">' . "\n";
        echo '  <link rel="apple-touch-icon" href="' . esc_url($distUrl . 'apple-touch-icon-180x180.png') . '">' . "\n";
        echo '  <meta name="theme-color" content="#1e3a8a">' . "\n";
        foreach ($cssFiles as $cssFile) {
            $cssUrl = $distUrl . $cssFile;
            echo '  <link rel="stylesheet" href="' . esc_url($cssUrl) . '">' . "\n";
        }
        echo '</head>' . "\n";
        echo '<body class="bg-background">' . "\n";
        echo '  <div id="root"></div>' . "\n";
        $logoutUrl = wp_logout_url(home_url('/painel-pais'));
        echo '  <script>window.guardkidsApi = ' . wp_json_encode([
            'nonce'     => $nonce,
            'root'      => $root,
            'logoutUrl' => $logoutUrl,
            // O SW mora no dist/ (servido por plugins_url), não em /painel-pais/.
            // O scope dele não cobre esta página — e não precisa: push não exige
            // que o SW controle a página.
            'swUrl'     => $distUrl . 'sw.js',
        ]) . ';</script>' . "\n";
        echo '  <script type="module" src="' . esc_url($jsFile) . '"></script>' . "\n";
        echo '</body>' . "\n";
        echo '</html>';

        exit;
    }
}
