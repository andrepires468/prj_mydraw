import { http } from './http'

export interface Drawing {
  id: number
  user_id: number
  title: string
  description: string | null
  prompt: string | null
  model: string | null
  object_key: string
  mime_type: string | null
  size: number | null
  url: string | null
  created_at: string
  updated_at: string
  user?: { id: number; name: string }
}

export interface Paginated<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export async function listDrawings(page = 1) {
  const { data } = await http.get<Paginated<Drawing>>('/drawings', {
    params: { page },
  })
  return data
}

export interface CreateDrawingInput {
  file: File
  title: string
  description?: string | null
  prompt?: string | null
  model?: string | null
}

export async function createDrawing(input: CreateDrawingInput) {
  const form = new FormData()
  form.append('file', input.file)
  form.append('title', input.title)
  if (input.description) form.append('description', input.description)
  if (input.prompt) form.append('prompt', input.prompt)
  if (input.model) form.append('model', input.model)

  const { data } = await http.post<{ drawing: Drawing }>('/drawings', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.drawing
}
