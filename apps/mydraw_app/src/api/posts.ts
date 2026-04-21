import { http } from './http'

export interface GalleryRow {
  id: number
  post_id: number | null
  user_id: number
  draft_uuid: string | null
  object_key: string
  mime_type: string | null
  size: number | null
  position: number
  url: string | null
  created_at: string
  updated_at: string
}

export interface PostUser {
  id: number
  name: string | null
  email?: string | null
}

export interface PostRow {
  id: number
  user_id: number
  title: string
  description: string | null
  created_at: string
  updated_at: string
  user?: PostUser
  images?: GalleryRow[]
}

export interface Paginated<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

/**
 * Lista posts paginados com suas imagens (Gallery) já populadas.
 */
export async function listPosts(page = 1, perPage = 20) {
  const { data } = await http.get<Paginated<PostRow>>('/posts', {
    params: { page, per_page: perPage },
  })
  return data
}

/**
 * Upload de uma imagem vinculada a um rascunho (draft UUID).
 */
export async function uploadGalleryImage(
  draftUuid: string,
  file: File,
  onProgress?: (percent: number) => void,
): Promise<GalleryRow> {
  const form = new FormData()
  form.append('file', file)
  form.append('draft_uuid', draftUuid)

  const { data } = await http.post<{ gallery: GalleryRow }>(
    '/create-post/upload',
    form,
    {
      headers: { 'Content-Type': 'multipart/form-data' },
      // Upload para MinIO remoto pode demorar; o timeout global de 15s do
      // cliente HTTP causa cancelamento prematuro. 2 minutos por arquivo.
      timeout: 120_000,
      onUploadProgress: (evt) => {
        if (!onProgress || !evt.total) return
        onProgress(Math.round((evt.loaded * 100) / evt.total))
      },
    },
  )
  return data.gallery
}

/**
 * Finaliza o post: cria Post e vincula todas as imagens do draft.
 */
export async function createPost(
  draftUuid: string,
  title: string,
  description: string | null,
): Promise<PostRow> {
  const { data } = await http.post<{ post: PostRow }>('/create-post', {
    draft_uuid: draftUuid,
    title,
    description: description ?? null,
  })
  return data.post
}
