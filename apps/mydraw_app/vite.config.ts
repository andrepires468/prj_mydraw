import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'
import path from 'node:path'

const appDir = fileURLToPath(new URL('.', import.meta.url))
const repoRoot = path.resolve(appDir, '../..')

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const appEnv = loadEnv(mode, appDir, '')
  const rootEnv = loadEnv(mode, repoRoot, '')

  /** Herda variáveis da raiz do monorepo quando ausentes no app (ex.: build mobile). */
  const envDefine: Record<string, string> = {}
  const devHost = appEnv.VITE_DEV_HOST?.trim() || '192.168.86.122'

  function lanUrl(raw: string | undefined, fallbackPort: string): string | undefined {
    if (raw?.trim()) {
      try {
        const u = new URL(raw.trim())
        if (u.hostname === 'localhost' || u.hostname === '127.0.0.1') {
          u.hostname = devHost
          return u.origin
        }
        return u.origin
      } catch {
        return raw.trim()
      }
    }
    return `http://${devHost}:${fallbackPort}`
  }

  if (mode === 'mobile') {
    const apiUrl = lanUrl(appEnv.VITE_API_URL || rootEnv.APP_VITE_API_URL, '8088')
    const minioUrl = lanUrl(
      appEnv.VITE_MINIO_PUBLIC_URL || rootEnv.APP_VITE_MINIO_PUBLIC_URL,
      '9100',
    )
    if (apiUrl) envDefine['import.meta.env.VITE_API_URL'] = JSON.stringify(apiUrl)
    if (minioUrl) envDefine['import.meta.env.VITE_MINIO_PUBLIC_URL'] = JSON.stringify(minioUrl)
    if (devHost) envDefine['import.meta.env.VITE_DEV_HOST'] = JSON.stringify(devHost)
  }

  const googleClientId = appEnv.VITE_GOOGLE_CLIENT_ID || rootEnv.APP_VITE_GOOGLE_CLIENT_ID
  if (googleClientId && !appEnv.VITE_GOOGLE_CLIENT_ID) {
    envDefine['import.meta.env.VITE_GOOGLE_CLIENT_ID'] = JSON.stringify(googleClientId)
  }

  return {
    /** Relativo só no WebView Capacitor. Na web, `/` evita 404 de assets em rotas aninhadas. */
    base: mode === 'mobile' ? './' : '/',
    define: envDefine,
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
    optimizeDeps: {
      include: ['vue', 'pinia', 'vue-router', 'axios'],
    },
    server: {
      host: true,
      port: 5173,
      strictPort: true,
      hmr: {
        host: 'localhost',
      },
      watch: {
        usePolling: true,
        interval: 1000,
      },
      warmup: {
        clientFiles: [
          './index.html',
          './src/main.ts',
          './src/App.vue',
          './src/router/index.ts',
          './src/views/GalleryView.vue',
          './src/components/layout/*.vue',
          './src/components/gallery/*.vue',
          './src/components/common/{AppModal,MaterialIcon}.vue',
          './src/assets/styles/main.css',
        ],
      },
    },
  }
})
