/** Mensagens amigáveis para erros do Google Sign-In nativo (Capacitor). */

const ANDROID_PACKAGE = 'app.mydrow.gallery'

interface CapacitorLikeError {
  message?: string
  code?: string
  errorMessage?: string
}

function readCapacitorError(err: unknown): { message: string; code: string } {
  if (typeof err !== 'object' || err === null) {
    return { message: '', code: '' }
  }
  const o = err as CapacitorLikeError
  return {
    message: (o.message ?? o.errorMessage ?? '').trim(),
    code: (o.code ?? '').trim(),
  }
}

export function isGoogleSignInCanceled(err: unknown): boolean {
  const { message, code } = readCapacitorError(err)
  return (
    code === 'SIGN_IN_CANCELED' ||
    code === '12501' ||
    /cancel/i.test(message)
  )
}

export function formatGoogleSignInError(err: unknown): string {
  if (isGoogleSignInCanceled(err)) {
    return ''
  }

  const { message, code } = readCapacitorError(err)
  const combined = `${code} ${message}`.toLowerCase()

  if (
    code === '10' ||
    code === '12500' ||
    code === '16' ||
    combined.includes('developer_error') ||
    combined.includes('developer error') ||
    combined.includes(' 10:')
  ) {
    return (
      `Google Sign-In Android is not configured. In Google Cloud Console, add an OAuth client ` +
      `type Android with package ${ANDROID_PACKAGE} and your debug SHA-1 ` +
      `(run npm run android:sha1 in apps/mydraw_app). ` +
      `Keep the Web Client ID in GOOGLE_CLIENT_ID / VITE_GOOGLE_CLIENT_ID (used by the app and API).`
    )
  }

  if (combined.includes('not initialized') || combined.includes('initialize')) {
    return 'Google Sign-In failed to initialize. Rebuild the app and try again.'
  }

  if (message) return message
  if (err instanceof Error && err.message) return err.message
  return 'Google sign-in failed. Try again.'
}
