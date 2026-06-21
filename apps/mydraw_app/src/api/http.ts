import axios, { type AxiosInstance } from 'axios'
import { isJwtExpired } from '@/utils/jwt'
import { rewriteStorageUrlsInData } from '@/utils/storageUrl'

/**
 * Resolve a base da API.
 * - Mobile build: URL LAN do `.env.mobile`.
 * - Live reload / browser dev: troca localhost → IP da página ou `VITE_DEV_HOST`.
 */
function resolveApiBaseUrl(): string {
  const fromEnv =
    (import.meta.env.VITE_API_URL as string | undefined) ?? 'http://localhost:8088'

  if (typeof window === 'undefined') return fromEnv

  const devHost = (import.meta.env.VITE_DEV_HOST as string | undefined)?.trim()
  const pageHost = window.location.hostname
  const rewriteHost =
    pageHost && pageHost !== 'localhost' && pageHost !== '127.0.0.1'
      ? pageHost
      : devHost || null

  if (rewriteHost) {
    try {
      const u = new URL(fromEnv)
      if (u.hostname === 'localhost' || u.hostname === '127.0.0.1') {
        u.hostname = rewriteHost
        return u.origin
      }
    } catch {
      /* mantém fromEnv */
    }
  }

  return fromEnv
}

const apiUrl = resolveApiBaseUrl()
const apiVersion = import.meta.env.VITE_API_VERSION ?? 'v1'

const TOKEN_KEY = 'mydraw:token'
const USER_KEY = 'mydraw:user'

function clearStoredSession() {
  localStorage.removeItem(TOKEN_KEY)
  localStorage.removeItem(USER_KEY)
}

export const http: AxiosInstance = axios.create({
  baseURL: `${apiUrl.replace(/\/$/, '')}/api/${apiVersion}`,
  headers: {
    Accept: 'application/json',
  },
  timeout: 15000,
})

http.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY)
  if (token) {
    if (isJwtExpired(token)) {
      clearStoredSession()
    } else {
      config.headers.Authorization = `Bearer ${token}`
    }
  }
  return config
})

http.interceptors.response.use(
  (response) => {
    if (response.data !== null && typeof response.data === 'object') {
      response.data = rewriteStorageUrlsInData(response.data)
    }
    return response
  },
  (error) => {
    if (error.response?.status === 401) {
      clearStoredSession()
    }
    return Promise.reject(error)
  }
)

/** Útil para debug de conectividade no dispositivo. */
export function getResolvedApiBaseUrl(): string {
  return apiUrl
}
