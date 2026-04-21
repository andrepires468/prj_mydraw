import axios, { type AxiosInstance } from 'axios'
import { isJwtExpired } from '@/utils/jwt'

const apiUrl = import.meta.env.VITE_API_URL ?? 'http://localhost:8080'
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
