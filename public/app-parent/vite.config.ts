import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const wpUrl = env.VITE_WP_URL ?? 'http://guardkids-wp.local';
  const wpHost = new URL(wpUrl).host;

  return {
    plugins: [react()],
    base: './',
    build: {
      outDir: 'dist',
      emptyOutDir: true,
    },
    server: {
      proxy: {
        '/wp-json': {
          // Força IPv4 (LocalWP resolve via hosts mas Node prefere IPv6 -> hang)
          target: 'http://127.0.0.1',
          changeOrigin: true,
          secure: false,
          // Mantém o Host do site .local pro router nginx do LocalWP rotear
          headers: { Host: wpHost },
        },
      },
    },
  };
});
