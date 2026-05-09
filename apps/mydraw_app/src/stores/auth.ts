import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import * as authApi from '@/api/auth'
import { isJwtExpired } from '@/utils/jwt'

const TOKEN_KEY = 'mydraw:token'
const USER_KEY = 'mydraw:user'

function loadStoredUser(): authApi.AuthUser | null {
  try {
    const raw = localStorage.getItem(USER_KEY)
    return raw ? (JSON.parse(raw) as authApi.AuthUser) : null
  } catch {
    return null
  }
}

function loadStoredToken(): string | null {
  const stored = localStorage.getItem(TOKEN_KEY)
  if (!stored) return null
  // Descarta token já expirado logo na inicialização.
  if (isJwtExpired(stored)) {
    localStorage.removeItem(TOKEN_KEY)
    localStorage.removeItem(USER_KEY)
    return null
  }
  return stored
}

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(loadStoredToken())
  const user = ref<authApi.AuthUser | null>(token.value ? loadStoredUser() : null)

  const isAuthenticated = computed(
    () => token.value !== null && !isJwtExpired(token.value)
  )
  const isAdmin = computed(() => user.value?.is_admin === true)

  function setToken(value: string | null) {
    token.value = value
    if (value) {
      localStorage.setItem(TOKEN_KEY, value)
    } else {
      localStorage.removeItem(TOKEN_KEY)
    }
  }

  function setUser(value: authApi.AuthUser | null) {
    user.value = value
    if (value) {
      localStorage.setItem(USER_KEY, JSON.stringify(value))
    } else {
      localStorage.removeItem(USER_KEY)
    }
  }

  function clearSession() {
    setToken(null)
    setUser(null)
  }

  async function login(email: string, password: string) {
    const response = await authApi.login(email, password)
    setToken(response.access_token)
    setUser(response.user)
    return response
  }

  async function register(name: string, email: string, password: string) {
    const response = await authApi.register(name, email, password)
    setToken(response.access_token)
    setUser(response.user)
    return response
  }

  async function fetchMe() {
    if (!token.value || isJwtExpired(token.value)) {
      clearSession()
      return null
    }
    try {
      const me = await authApi.me()
      setUser(me)
      return me
    } catch {
      clearSession()
      return null
    }
  }

  async function updateProfile(name: string) {
    const meUser = await authApi.updateProfile(name)
    setUser(meUser)
    return meUser
  }

  async function loginWithGoogle(credential: string) {
    const response = await authApi.loginWithGoogle(credential)
    setToken(response.access_token)
    setUser(response.user)
    return response
  }

  async function linkGoogleAccount(credential: string) {
    const meUser = await authApi.linkGoogleAccount(credential)
    setUser(meUser)
    return meUser
  }

  async function unlinkGoogleAccount() {
    const meUser = await authApi.unlinkGoogleAccount()
    setUser(meUser)
    return meUser
  }

  async function logout() {
    // Remove a sessão localmente primeiro para a UI refletir o logout
    // imediatamente; só então dispara a invalidação no servidor com o
    // token que estava em uso (fire-and-forget).
    const previousToken = token.value
    clearSession()
    if (previousToken) {
      void authApi.logout(previousToken).catch(() => {
        // ignora falhas de rede / token já invalidado
      })
    }
  }

  return {
    token,
    user,
    isAuthenticated,
    isAdmin,
    setUser,
    login,
    register,
    fetchMe,
    updateProfile,
    loginWithGoogle,
    linkGoogleAccount,
    unlinkGoogleAccount,
    logout,
    clearSession,
  }
})
