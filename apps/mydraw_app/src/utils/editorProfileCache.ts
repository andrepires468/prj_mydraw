import type { EditorProfile } from '@/api/editorProfile'

const STORAGE_KEY = 'mydraw:editor-profile:v1'
/** Atualização remota no máximo uma vez por dia. */
export const EDITOR_PROFILE_TTL_MS = 24 * 60 * 60 * 1000

interface StoredPayload {
  profile: EditorProfile
  fetchedAt: number
}

export function readEditorProfileCache(): StoredPayload | null {
  if (typeof window === 'undefined') return null
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as StoredPayload
    if (
      !parsed?.profile ||
      typeof parsed.fetchedAt !== 'number' ||
      typeof parsed.profile.id !== 'number'
    ) {
      return null
    }
    return parsed
  } catch {
    return null
  }
}

export function isEditorProfileCacheFresh(fetchedAt: number): boolean {
  return Date.now() - fetchedAt < EDITOR_PROFILE_TTL_MS
}

export function writeEditorProfileCache(profile: EditorProfile): void {
  if (typeof window === 'undefined') return
  try {
    const payload: StoredPayload = {
      profile,
      fetchedAt: Date.now(),
    }
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(payload))
  } catch {
    /* quota / privado */
  }
}
