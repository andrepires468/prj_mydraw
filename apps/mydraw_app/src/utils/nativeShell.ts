import { Capacitor } from '@capacitor/core'
import { SplashScreen } from '@capacitor/splash-screen'
import { StatusBar, Style } from '@capacitor/status-bar'
import { initNativeGoogleSignIn } from '@/utils/googleIdentity'
import { formatGoogleSignInError } from '@/utils/formatGoogleSignInError'

function resolveStatusBarStyle(): Style {
  const isDark =
    document.documentElement.classList.contains('dark') ||
    window.matchMedia('(prefers-color-scheme: dark)').matches
  return isDark ? Style.Dark : Style.Light
}

/** Plugins nativos (status bar, splash) — no-op no browser. */
export async function initNativeShell(): Promise<void> {
  if (!Capacitor.isNativePlatform()) return

  try {
    await SplashScreen.hide()
  } catch {
    /* plugin opcional */
  }

  try {
    await StatusBar.setStyle({ style: resolveStatusBarStyle() })
  } catch {
    /* plugin opcional */
  }

  try {
    await initNativeGoogleSignIn()
  } catch (err) {
    if (import.meta.env.DEV) {
      console.error('[Google Sign-In] Native init failed:', formatGoogleSignInError(err), err)
    }
  }
}
