import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  // VITE_WP_PROXY_TARGET = upstream IPv4 do nginx do site (ex.: http://127.0.0.1:10034).
  // O router do LocalWP em :80 trava quando chamado via http-proxy do Vite no Windows,
  // entao apontamos direto pra nginx do site (porta visivel no LocalWP -> Site -> Advanced).
  const proxyTarget = env.VITE_WP_PROXY_TARGET ?? 'http://127.0.0.1:10034';

  return {
    plugins: [react()],
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
