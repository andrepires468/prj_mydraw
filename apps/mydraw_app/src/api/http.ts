import axios, { type AxiosInstance } from 'axios'
import { isJwtExpired } from '@/utils/jwt'

/**
 * Em dev, se a página foi aberta por IP/hostname da LAN (não localhost),
 * troca só o hostname da URL da API — mantém porta e protocolo do .env —
 * para o browser não chamar localhost no dispositivo errado.
 */
function resolveApiBaseUrl(): string {
  const fromEnv = (import.meta.env.VITE_API_URL as string | undefined) ?? 'http://localhost:8088'
  if (!import.meta.env.DEV || typeof window === 'undefined') {
    return fromEnv
  }
  const pageHost = window.location.hostname
  if (!pageHost || pageHost === 'localhost' || pageHost === '127.0.0.1') {
    return fromEnv
  }
  try {
    const u = new URL(fromEnv)
    u.hostname = pageHost
    return u.origin
  } catch {
    return fromEnv
  }
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
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      clearStoredSession()
    }
    return Promise.reject(error)
  }
)
