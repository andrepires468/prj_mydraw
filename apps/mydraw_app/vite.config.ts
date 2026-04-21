import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  // O Vite 8 usa LightningCSS como transformer/minifier padrão, que inlina
  // CSS vars definidas em `:root`, quebrando a troca de tema (light/dark)
  // via classe `.dark` no HTML. Forçamos o PostCSS/esbuild para preservar
  // os `var(--color-...)` no CSS final.
  css: {
    transformer: 'postcss',
  },
  build: {
    cssMinify: 'esbuild',
  },
  server: {
    host: true,
    port: 5173,
    strictPort: true,
    watch: {
      usePolling: true,
    },
  },
})
