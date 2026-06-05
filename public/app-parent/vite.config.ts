import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const wpUrl = env.VITE_WP_URL ?? 'http://guardkids-wp.local';

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
          target: wpUrl,
          changeOrigin: true,
          secure: false,
        },
      },
    },
  };
});
