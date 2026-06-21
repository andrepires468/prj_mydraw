import type { CapacitorConfig } from '@capacitor/cli'

/**
 * Dev local (sem produção).
 * - Padrão: bundle em `dist` + HTTP (API LAN em cleartext).
 * - Live reload: CAP_USE_LIVE_RELOAD=true → WebView no Vite (5173).
 * - Produção futura: CAP_LOCAL_DEV=false CAP_USE_LIVE_RELOAD=false
 */
const localDev = process.env.CAP_LOCAL_DEV !== 'false'
const useLiveReload = process.env.CAP_USE_LIVE_RELOAD === 'true'
const devServerUrl =
  process.env.CAP_DEV_SERVER_URL?.trim() || 'http://192.168.86.122:5173'

const server: CapacitorConfig['server'] =
  useLiveReload && devServerUrl
    ? {
        url: devServerUrl,
        cleartext: true,
      }
    : {
        /** `https` + API `http://LAN` = mixed content bloqueado no WebView Android. */
        androidScheme: localDev ? 'http' : 'https',
        iosScheme: localDev ? 'http' : 'https',
        ...(localDev ? { cleartext: true } : {}),
      }

const config: CapacitorConfig = {
  appId: 'app.mydrow.gallery',
  appName: 'mydraw',
  webDir: 'dist',
  server,
  plugins: {
    SplashScreen: {
      launchAutoHide: true,
      launchShowDuration: 1200,
      backgroundColor: '#f8f9ff',
      showSpinner: false,
    },
    StatusBar: {
      style: 'DEFAULT',
      backgroundColor: '#f8f9ff',
    },
  },
}

export default config
