<?php

declare(strict_types=1);

namespace GuardKids\Ui;

/**
 * Serve a SPA `app-child` numa URL standalone (`/painel-filho`).
 *
 * Diferente do ParentApp, esta rota é **pública** — quem autentica é o
 * token de dispositivo (X-GuardKids-Token) gerenciado pelo próprio SPA
 * via tela de pareamento.
 *
 * Diferente também porque a SPA é uma PWA: o service worker precisa
 * ter scope `/painel-filho/` pra controlar essa origem-path. Isso
 * obriga que **todos** os assets relativos sejam servidos a partir
 * de `/painel-filho/*`, não do plugins URL — senão o precache do
 * Workbox quebra. Por isso essa classe faz o papel de servidor
 * estático para qualquer suffix sob `/painel-filho/`.
 */
final class ChildApp
{
    private const ROUTE_PREFIX = '/painel-filho';

    /**
     * @var array<string, string>
     */
    private const MIME = [
        'js'           => 'text/javascript',
        'mjs'          => 'text/javascript',
        'css'          => 'text/css',
        'html'         => 'text/html',
        'json'         => 'application/json',
        'webmanifest'  => 'application/manifest+json',
        'svg'          => 'image/svg+xml',
        'png'          => 'image/png',
        'ico'          => 'image/x-icon',
        'webp'         => 'image/webp',
        'woff'         => 'font/woff',
        'woff2'        => 'font/woff2',
        'map'          => 'application/json',
    ];

    public function register(): void
    {
        // parse_request roda muito cedo, antes de qualquer query do WP. Nessa hora
        // já temos REQUEST_URI no $_SERVER e podemos curto-circuitar respondendo
        // direto sem invocar o resto do WordPress.
        add_action('parse_request', [$this, 'maybeServe'], 1);
    }

    /**
     * @deprecated Mantido só pra Plugin::onActivate() não quebrar; rewrite rules
     * não são mais usadas — a rota é gerenciada via parse_request.
     */
    public function addRewriteRule(): void
    {
        // intencionalmente vazio
    }

    public function maybeServe(): void
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = parse_url($uri, PHP_URL_PATH);
        if (! is_string($path)) {
            return;
        }
        if (! str_starts_with($path, self::ROUTE_PREFIX)) {
            return;
        }

        $suffix = substr($path, strlen(self::ROUTE_PREFIX));
        // Root da SPA — serve HTML
        if ($suffix === '' || $suffix === '/') {
            $this->serveHtml();
        }

        // Caminho de arquivo — serve do dist/
        $relative = ltrim($suffix, '/');
        if (str_contains($relative, '..')) {
            status_header(400);
            exit;
        }

        $distDir = GUARDKIDS_DIR . 'public/app-child/dist/';
        $file = $distDir . $relative;
        if (! is_readable($file) || ! is_file($file)) {
            status_header(404);
            nocache_headers();
            exit;
        }

        $this->serveFile($file, $relative);
    }

    private function serveFile(string $file, string $relative): void
    {
        $ext = strtolower((string) pathinfo($relative, PATHINFO_EXTENSION));
        $mime = self::MIME[$ext] ?? 'application/octet-stream';

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($file));

        // SW: dá permissão pra controlar /painel-filho/ inteiro mesmo servido
        // de subpath (default scope seria onde o arquivo mora).
        if ($relative === 'sw.js') {
            header('Service-Worker-Allowed: /painel-filho/');
            // SW nunca pode ficar cacheado pelo browser
            header('Cache-Control: no-cache');
        } elseif (str_starts_with($relative, 'assets/')) {
            // assets com hash no nome — cache longo
            header('Cache-Control: public, max-age=31536000, immutable');
        } else {
            header('Cache-Control: no-cache');
        }

        readfile($file);
        exit;
    }

    private function serveHtml(): never
    {
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

        // URLs relativas ao /painel-filho/ — o browser resolve contra o path atual
        $jsFile   = isset($entry['file']) ? '/painel-filho/' . $entry['file'] : '';
        $cssFiles = isset($entry['css']) && is_array($entry['css']) ? $entry['css'] : [];

        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');

        echo '<!doctype html>' . "\n";
        echo '<html lang="pt-BR">' . "\n";
        echo '<head>' . "\n";
        echo '  <meta charset="UTF-8">' . "\n";
        echo '  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">' . "\n";
        echo '  <meta name="theme-color" content="#1d4ed8">' . "\n";
        echo '  <title>GuardKids — Painel Infantil</title>' . "\n";
        echo '  <link rel="manifest" href="/painel-filho/manifest.webmanifest">' . "\n";
        echo '  <link rel="icon" href="/painel-filho/favicon.ico" sizes="any">' . "\n";
        echo '  <link rel="icon" href="/painel-filho/icon.svg" type="image/svg+xml">' . "\n";
        echo '  <link rel="apple-touch-icon" href="/painel-filho/apple-touch-icon-180x180.png">' . "\n";
        echo '  <link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
        echo '  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
        echo '  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">' . "\n";
        echo '  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet">' . "\n";
        foreach ($cssFiles as $cssFile) {
            echo '  <link rel="stylesheet" href="' . esc_url('/painel-filho/' . $cssFile) . '">' . "\n";
        }
        echo '</head>' . "\n";
        echo '<body class="bg-surface">' . "\n";
        echo '  <div id="root"></div>' . "\n";
        echo '  <script type="module" src="' . esc_url($jsFile) . '"></script>' . "\n";
        // Registra o SW manualmente — vite-plugin-pwa em registerType=autoUpdate
        // espera o registerSW.js, mas como geramos HTML fora do Vite, inlinamos.
        echo '  <script>' . "\n";
        echo '    if ("serviceWorker" in navigator) {' . "\n";
        echo '      window.addEventListener("load", function () {' . "\n";
        echo '        navigator.serviceWorker.register("/painel-filho/sw.js", { scope: "/painel-filho/" })' . "\n";
        echo '          .catch(function (e) { console.warn("[gk] SW register failed", e); });' . "\n";
        echo '      });' . "\n";
        echo '    }' . "\n";
        echo '  </script>' . "\n";
        echo '</body>' . "\n";
        echo '</html>';

        exit;
    }
}
