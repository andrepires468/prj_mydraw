export interface JwtPayload {
  exp?: number
  iat?: number
  sub?: string | number
  [key: string]: unknown
}

function base64UrlDecode(input: string): string {
  const pad = input.length % 4 === 0 ? '' : '='.repeat(4 - (input.length % 4))
  const base64 = (input + pad).replace(/-/g, '+').replace(/_/g, '/')
  if (typeof atob === 'function') {
    return decodeURIComponent(
      atob(base64)
        .split('')
        .map((c) => '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2))
        .join('')
    )
  }
  return Buffer.from(base64, 'base64').toString('utf-8')
}

export function decodeJwt(token: string): JwtPayload | null {
  try {
    const parts = token.split('.')
    if (parts.length !== 3) return null
    return JSON.parse(base64UrlDecode(parts[1])) as JwtPayload
  } catch {
    return null
  }
}

export function isJwtExpired(token: string | null | undefined, skewSeconds = 0): boolean {
  if (!token) return true
  const payload = decodeJwt(token)
  if (!payload || typeof payload.exp !== 'number') return true
  const nowSeconds = Math.floor(Date.now() / 1000)
  return nowSeconds >= payload.exp - skewSeconds
}
