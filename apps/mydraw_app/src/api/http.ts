import axios, { type AxiosInstance } from 'axios'

const apiUrl = import.meta.env.VITE_API_URL ?? 'http://localhost:8080'
const apiVersion = import.meta.env.VITE_API_VERSION ?? 'v1'

export const http: AxiosInstance = axios.create({
  baseURL: `${apiUrl.replace(/\/$/, '')}/api/${apiVersion}`,
  headers: {
    Accept: 'application/json',
  },
  timeout: 15000,
})

http.interceptors.request.use((config) => {
  const token = localStorage.getItem('mydraw:token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

http.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('mydraw:token')
    }
    return Promise.reject(error)
  }
)
