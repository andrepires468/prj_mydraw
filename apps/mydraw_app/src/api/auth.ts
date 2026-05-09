import { http } from './http'

export interface AuthUser {
  id: number
  name: string
  email: string
}

export interface AuthResponse {
  token_type: 'bearer'
  access_token: string
  expires_in: number
  user: AuthUser
}

export async function login(email: string, password: string) {
  const { data } = await http.post<AuthResponse>('/auth/login', {
    email,
    password,
  })
  return data
}

export async function register(name: string, email: string, password: string) {
  const { data } = await http.post<AuthResponse>('/auth/register', {
    name,
    email,
    password,
  })
  return data
}

export async function me() {
  const { data } = await http.get<{ user: AuthUser }>('/auth/me')
  return data.user
}

export async function updateProfile(name: string) {
  const { data } = await http.put<{ user: AuthUser }>('/auth/profile', {
    name,
  })
  return data.user
}

export async function logout(token?: string) {
  await http.post(
    '/auth/logout',
    null,
    token
      ? { headers: { Authorization: `Bearer ${token}` } }
      : undefined
  )
}
