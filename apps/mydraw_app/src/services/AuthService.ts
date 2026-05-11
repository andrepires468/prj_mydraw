import { http } from '@/api/http'
import type { ApiEnvelope } from '@/services/apiContract'
import { unwrapEnvelope } from '@/services/apiContract'
import { routes } from '@/services/routes'

export interface AuthUser {
  id: number
  name: string
  email: string
  is_admin: boolean
  google_linked?: boolean
  avatar_url?: string | null
}

export interface AuthResponse {
  token_type: 'bearer'
  access_token: string
  expires_in: number
  user: AuthUser
}

export async function login(
  email: string,
  password: string,
): Promise<AuthResponse> {
  const { data } = await http.post<ApiEnvelope<AuthResponse>>(routes.auth.login, {
    email,
    password,
  })
  return unwrapEnvelope<AuthResponse>(data)
}

export async function register(
  name: string,
  email: string,
  password: string,
): Promise<AuthResponse> {
  const { data } = await http.post<ApiEnvelope<AuthResponse>>(
    routes.auth.register,
    {
      name,
      email,
      password,
    },
  )
  return unwrapEnvelope<AuthResponse>(data)
}

export async function me(): Promise<AuthUser> {
  const { data } = await http.get<ApiEnvelope<{ user: AuthUser }>>(routes.auth.me)
  return unwrapEnvelope<{ user: AuthUser }>(data).user
}

export async function updateProfile(name: string): Promise<AuthUser> {
  const { data } = await http.put<ApiEnvelope<{ user: AuthUser }>>(
    routes.auth.profile,
    {
      name,
    },
  )
  return unwrapEnvelope<{ user: AuthUser }>(data).user
}

export async function changePassword(payload: {
  current_password: string
  password: string
  password_confirmation: string
}) {
  const { data } = await http.put<ApiEnvelope<{ message?: string }>>(
    routes.auth.password,
    payload,
  )
  unwrapEnvelope<{ message?: string }>(data)
}

export async function logout(token?: string) {
  await http.post(
    routes.auth.logout,
    null,
    token
      ? { headers: { Authorization: `Bearer ${token}` } }
      : undefined,
  )
}

export async function loginWithGoogle(credential: string): Promise<AuthResponse> {
  const { data } = await http.post<ApiEnvelope<AuthResponse>>(routes.auth.google, {
    credential,
  })
  return unwrapEnvelope<AuthResponse>(data)
}

export async function linkGoogleAccount(credential: string): Promise<AuthUser> {
  const { data } = await http.post<ApiEnvelope<{ user: AuthUser }>>(
    routes.auth.googleLink,
    { credential },
  )
  return unwrapEnvelope<{ user: AuthUser }>(data).user
}

export async function unlinkGoogleAccount(): Promise<AuthUser> {
  const { data } = await http.post<ApiEnvelope<{ user: AuthUser }>>(
    routes.auth.googleUnlink,
  )
  return unwrapEnvelope<{ user: AuthUser }>(data).user
}
