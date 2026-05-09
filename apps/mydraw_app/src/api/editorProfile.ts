import { http } from './http'

export interface EditorProfile {
  id: number
  name: string | null
  avatar_url: string | null
}

interface EditorProfileResponse {
  editor: EditorProfile
}

export async function fetchEditorProfile(): Promise<EditorProfile> {
  const { data } = await http.get<EditorProfileResponse>('/editor/profile')
  return data.editor
}
