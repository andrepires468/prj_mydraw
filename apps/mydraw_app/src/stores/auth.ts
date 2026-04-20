import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import * as authApi from '@/api/auth'

const TOKEN_KEY = 'mydraw:token'

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(localStorage.getItem(TOKEN_KEY))
  const user = ref<authApi.AuthUser | null>(null)

  const isAuthenticated = computed(() => token.value !== null)

  function setToken(value: string | null) {
    token.value = value
    if (value) {
      localStorage.setItem(TOKEN_KEY, value)
    } else {
      localStorage.removeItem(TOKEN_KEY)
    }
  }

  async function login(email: string, password: string) {
    const response = await authApi.login(email, password)
    setToken(response.access_token)
    user.value = response.user
    return response
  }

  async function register(name: string, email: string, password: string) {
    const response = await authApi.register(name, email, password)
    setToken(response.access_token)
    user.value = response.user
    return response
  }

  async function fetchMe() {
    if (!token.value) return null
    try {
      user.value = await authApi.me()
      return user.value
    } catch {
      setToken(null)
      user.value = null
      return null
    }
  }

  async function logout() {
    try {
      await authApi.logout()
    } catch {
      // ignora erro de rede no logout
    }
    setToken(null)
    user.value = null
  }

  return { token, user, isAuthenticated, login, register, fetchMe, logout }
})
