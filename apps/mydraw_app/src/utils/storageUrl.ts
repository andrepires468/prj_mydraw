/**
 * Reescreve URLs de storage (MinIO) com localhost para o host LAN do `.env.mobile`.
 * Necessário no Capacitor Android: a API em dev devolve localhost:9100 e o WebView
 * não alcança o MinIO do PC.
 */
export function resolveStorageUrl(url: string | null | undefined): string | null {
  if (url == null) return null
  const trimmed = url.trim()
  if (trimmed === '') return null

  try {
    const parsed = new URL(trimmed)
    if (parsed.hostname !== 'localhost' && parsed.hostname !== '127.0.0.1') {
      return trimmed
    }

    const publicBase = (import.meta.env.VITE_MINIO_PUBLIC_URL as string | undefined)?.trim()
    if (publicBase) {
      const base = new URL(publicBase)
      return `${base.origin}${parsed.pathname}${parsed.search}${parsed.hash}`
    }

    const devHost = (import.meta.env.VITE_DEV_HOST as string | undefined)?.trim()
    if (devHost) {
      parsed.hostname = devHost
      return parsed.toString()
    }
  } catch {
    return trimmed
  }

  return trimmed
}

function shouldRewriteStorageString(value: string): boolean {
  if (!/^https?:\/\//i.test(value)) return false
  try {
    const host = new URL(value).hostname
    return host === 'localhost' || host === '127.0.0.1'
  } catch {
    return false
  }
}

/** Percorre JSON da API e corrige URLs de storage localhost → LAN. */
export function rewriteStorageUrlsInData<T>(value: T): T {
  if (typeof value === 'string') {
    if (!shouldRewriteStorageString(value)) return value
    return (resolveStorageUrl(value) ?? value) as T
  }

  if (Array.isArray(value)) {
    return value.map((item) => rewriteStorageUrlsInData(item)) as T
  }

  if (value !== null && typeof value === 'object') {
    const out: Record<string, unknown> = {}
    for (const [key, nested] of Object.entries(value)) {
      out[key] = rewriteStorageUrlsInData(nested)
    }
    return out as T
  }

  return value
}
