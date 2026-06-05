import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  // VITE_WP_PROXY_TARGET = upstream IPv4 do nginx do site (ex.: http://127.0.0.1:10034).
  // Mesmo bypass do router LocalWP usado pelo app-parent — ver
  // feedback-vite-proxy-localwp-windows nos memos.
  const proxyTarget = env.VITE_WP_PROXY_TARGET ?? 'http://127.0.0.1:10034';

  return {
    plugins: [
      react(),
      VitePWA({
        registerType: 'autoUpdate',
        // Inclui assets estáticos pra precache
        includeAssets: [
          'favicon.ico',
          'apple-touch-icon-180x180.png',
          'icon.svg',
        ],
        manifest: {
          name: 'GuardKids — Painel Infantil',
          short_name: 'GuardKids',
          description: 'Ambiente seguro para crianças navegarem com o controle dos pais.',
          // Em produção via WP, a SPA mora em /painel-filho/. Em dev (Vite) mora em /.
          // Esse start_url só vale quando instalado; o usuário precisa instalar a partir
          // de /painel-filho/ pra essa URL fazer sentido como entry point.
          start_url: '/painel-filho/',
          scope: '/painel-filho/',
          display: 'standalone',
          orientation: 'portrait',
          background_color: '#f8f9ff',
          theme_color: '#1d4ed8',
          icons: [
            { src: 'pwa-64x64.png', sizes: '64x64', type: 'image/png' },
            { src: 'pwa-192x192.png', sizes: '192x192', type: 'image/png' },
            { src: 'pwa-512x512.png', sizes: '512x512', type: 'image/png' },
            {
              src: 'maskable-icon-512x512.png',
              sizes: '512x512',
              type: 'image/png',
              purpose: 'maskable',
            },
          ],
        },
        workbox: {
          // Precacheia tudo do build (JS/CSS/HTML/ícones)
          globPatterns: ['**/*.{js,css,html,ico,png,svg,webmanifest}'],
          // Fonts do Google são cache-first em runtime
          runtimeCaching: [
            {
              urlPattern: /^https:\/\/fonts\.googleapis\.com\/.*/i,
              handler: 'CacheFirst',
              options: {
                cacheName: 'gfonts-stylesheets',
                expiration: { maxEntries: 10, maxAgeSeconds: 60 * 60 * 24 * 365 },
              },
            },
            {
              urlPattern: /^https:\/\/fonts\.gstatic\.com\/.*/i,
              handler: 'CacheFirst',
              options: {
                cacheName: 'gfonts-webfonts',
                expiration: { maxEntries: 30, maxAgeSeconds: 60 * 60 * 24 * 365 },
              },
            },
          ],
          // API REST do plugin nunca entra no SW — sempre rede.
          navigateFallback: null,
        },
        devOptions: {
          enabled: false,
        },
      }),
    ],
    base: './',
    build: {
      outDir: 'dist',
      emptyOutDir: true,
      manifest: true,
    },
    server: {
      proxy: {
        '/wp-json': {
          target: proxyTarget,
          changeOrigin: false,
          secure: false,
          configure: (proxy) => {
            proxy.on('error', (err, _req, res) => {
              console.error('[vite proxy error]', err.message);
              if (res && !res.writableEnded) {
                res.writeHead(502, { 'Content-Type': 'text/plain' });
                res.end(`proxy error: ${err.message}`);
              }
            });
          },
        },
      },
    },
  };
});
