/** Bridge para Google Identity Services (um único `initialize` por carregamento da página). */

let gsiInitialized = false

let credentialHandler: ((credential: string) => void) | undefined

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

export async function whenGoogleLibraryReady(): Promise<boolean> {
  if (!isGoogleSignInConfigured()) return false
  const deadline = Date.now() + 12000
  while (Date.now() < deadline) {
    if (typeof window !== 'undefined' && window.google?.accounts?.id) {
      return true
    }
    await new Promise<void>((r) => setTimeout(r, 50))
  }
  return false
}

export function ensureGsiInitialized(): boolean {
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

/**
 * Renderiza o botão oficial do Google no elemento (limpa o conteúdo antes).
 */
export function renderGoogleSignInButton(
  container: HTMLElement,
  options?: { width?: number },
): void {
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
