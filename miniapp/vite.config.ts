import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// Telegram Mini App SPA.
// Збирається у ../public/app, щоб віддаватися тим самим вебсервером, що й Symfony API
// (одне походження → без CORS і без проблем з токеном у WebView).
export default defineConfig({
  plugins: [vue()],
  base: '/app/',
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  build: {
    outDir: '../public/app',
    emptyOutDir: true,
  },
  server: {
    port: 5173,
    // У dev проксі на локальний Symfony, щоб /api працював без CORS.
    proxy: {
      '/api': {
        target: process.env.VITE_DEV_API_TARGET ?? 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
});
