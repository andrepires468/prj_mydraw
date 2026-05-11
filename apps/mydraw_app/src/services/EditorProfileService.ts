import { http } from '@/api/http'
import type { ApiEnvelope } from '@/services/apiContract'
import { unwrapEnvelope } from '@/services/apiContract'
import { routes } from '@/services/routes'

export interface EditorProfile {
  id: number
  name: string | null
  avatar_url: string | null
}

export async function fetchEditorProfile(): Promise<EditorProfile> {
  const { data } = await http.get<ApiEnvelope<{ editor: EditorProfile }>>(
    routes.editor.profile,
  )
  return unwrapEnvelope<{ editor: EditorProfile }>(data).editor
}
