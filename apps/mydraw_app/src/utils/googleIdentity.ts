/**
 * Google Sign-In — GIS no browser; Credential Manager nativo no Capacitor.
 */
import { Capacitor } from '@capacitor/core'
import { isNativePlatform } from '@/utils/platform'
import {
  formatGoogleSignInError,
  isGoogleSignInCanceled,
} from '@/utils/formatGoogleSignInError'

let gsiInitialized = false
let nativeInitialized = false
let nativeInitPromise: Promise<void> | null = null
let googleSignInModulePromise: Promise<
  typeof import('@capawesome/capacitor-google-sign-in')
> | null = null

let credentialHandler: ((credential: string) => void) | undefined

async function getGoogleSignInPlugin() {
  googleSignInModulePromise ??= import('@capawesome/capacitor-google-sign-in')
  const { GoogleSignIn } = await googleSignInModulePromise
  return GoogleSignIn
}

export function setGoogleCredentialHandler(
  fn: ((credential: string) => void) | undefined,
): void {
  credentialHandler = fn
}

export function getGoogleClientId(): string | undefined {
  const raw = import.meta.env.VITE_GOOGLE_CLIENT_ID as string | undefined
  const id = raw?.trim()
  return id || undefined
}

export function isGoogleSignInConfigured(): boolean {
  return Boolean(getGoogleClientId())
}

export function usesNativeGoogleSignIn(): boolean {
  return isNativePlatform() && isGoogleSignInConfigured()
}

/** Inicializa o plugin nativo (Android/iOS). Chamar uma vez no boot ou antes do signIn. */
export async function initNativeGoogleSignIn(): Promise<void> {
  const clientId = getGoogleClientId()
  if (!clientId || !isNativePlatform()) return
  if (nativeInitialized) return
  if (nativeInitPromise) {
    await nativeInitPromise
    return
  }

  nativeInitPromise = (async () => {
    const GoogleSignIn = await getGoogleSignInPlugin()
    await GoogleSignIn.initialize({ clientId })
    nativeInitialized = true
  })()

  try {
    await nativeInitPromise
  } catch (err) {
    nativeInitialized = false
    nativeInitPromise = null
    throw err
  }
}

export async function whenGoogleLibraryReady(): Promise<boolean> {
  if (!isGoogleSignInConfigured()) return false
  if (isNativePlatform()) {
    try {
      await initNativeGoogleSignIn()
      return nativeInitialized
    } catch {
      return false
    }
  }

  const deadline = Date.now() + 12000
  while (Date.now() < deadline) {
    if (typeof window !== 'undefined' && window.google?.accounts?.id) {
      return true
    }
    await new Promise<void>((r) => setTimeout(r, 50))
  }
  return false
}

function ensureGsiInitialized(): boolean {
  const clientId = getGoogleClientId()
  if (!clientId || typeof window === 'undefined' || !window.google?.accounts?.id) {
    return false
  }
  if (gsiInitialized) return true
  window.google.accounts.id.initialize({
    client_id: clientId,
    callback: (resp) => {
      if (resp.credential) credentialHandler?.(resp.credential)
    },
  })
  gsiInitialized = true
  return true
}

export function renderGoogleSignInButton(
  container: HTMLElement,
  options?: { width?: number },
): void {
  if (isNativePlatform()) return
  if (!ensureGsiInitialized() || !window.google?.accounts?.id) return
  container.replaceChildren()
  const w = options?.width ?? Math.min(320, container.clientWidth || 320)
  window.google.accounts.id.renderButton(container, {
    type: 'standard',
    theme: 'outline',
    size: 'large',
    text: 'continue_with',
    width: w,
  })
}

/** Fluxo nativo (Capacitor) — retorna o ID Token JWT para a API. */
export async function signInWithGoogleNative(): Promise<string | null> {
  const clientId = getGoogleClientId()
  if (!clientId || !isNativePlatform()) return null

  await initNativeGoogleSignIn()

  try {
    const GoogleSignIn = await getGoogleSignInPlugin()
    const result = await GoogleSignIn.signIn()
    const token = result.idToken?.trim()
    if (!token) {
      throw new Error('Google Sign-In returned no ID token.')
    }
    return token
  } catch (err: unknown) {
    if (isGoogleSignInCanceled(err)) {
      return null
    }
    throw err
  }
}

export { formatGoogleSignInError, isGoogleSignInCanceled }

export async function mountGoogleSignInButton(container: HTMLElement): Promise<boolean> {
  if (!isGoogleSignInConfigured()) return false

  if (isNativePlatform()) {
    try {
      await initNativeGoogleSignIn()
      return nativeInitialized
    } catch {
      return false
    }
  }

  const ready = await whenGoogleLibraryReady()
  if (!ready) return false
  renderGoogleSignInButton(container)
  return true
}

export async function handleGoogleRedirectCallback(): Promise<string | null> {
  if (Capacitor.getPlatform() !== 'web' || !isGoogleSignInConfigured()) {
    return null
  }

  await initNativeGoogleSignIn()
  const GoogleSignIn = await getGoogleSignInPlugin()
  const result = await GoogleSignIn.handleRedirectCallback()
  return result.idToken ?? null
}
