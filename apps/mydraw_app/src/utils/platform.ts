import { Capacitor } from '@capacitor/core'

/** App rodando dentro do WebView Capacitor (Android/iOS). */
export function isNativePlatform(): boolean {
  return Capacitor.isNativePlatform()
}

/** Origem típica do WebView Capacitor. */
export function isCapacitorWebView(): boolean {
  if (typeof window === 'undefined') return false
  const protocol = window.location.protocol
  return protocol === 'capacitor:' || protocol === 'ionic:'
}
