/** Nickname format: ASCII letters, digits, underscore, hyphen only (no accents or spaces). */
export const NICKNAME_REGEX = /^[a-zA-Z0-9_-]+$/

export const NICKNAME_MIN_LEN = 2
export const NICKNAME_MAX_LEN = 50

export function sanitizeNicknameKeystrokes(value: string): string {
  return value.replace(/[^a-zA-Z0-9_-]/g, '')
}

export function isValidNickname(value: string): boolean {
  const len = value.length
  if (len < NICKNAME_MIN_LEN || len > NICKNAME_MAX_LEN) return false
  return NICKNAME_REGEX.test(value)
}
