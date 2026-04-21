import { http } from './http'

export type PostStatus = 'draft' | 'published'

/**
 * URLs das thumbnails geradas durante o upload. As chaves correspondem aos
 * "rótulos" das resoluções máximas aplicadas (a imagem real pode ter
 * dimensões menores, já que a proporção original é preservada).
 *
 * Um valor `null` indica que a thumb correspondente não foi gerada ou não
 * está presente no bucket (ex.: imagens antigas anteriores ao recurso).
 */
export interface GalleryThumbs {
  '320x320': string | null
  '180x180'?: string | null
}

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
  thumbs: GalleryThumbs
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
  status: PostStatus
  draft_uuid: string | null
  author_name?: string | null
  created_at: string
  updated_at: string
  user?: PostUser
  images?: GalleryRow[]
}

export interface Paginated<T> {
  data: T[]
  current_page: number
  last_page?: number
  per_page: number
  total?: number
  next_page_url?: string | null
  prev_page_url?: string | null
}

export interface PostsCounts {
  published: number
  draft: number
}

export interface PostsPage extends Paginated<PostRow> {
  counts?: PostsCounts
}

/**
 * Lista posts paginados com suas imagens (Gallery) já populadas.
 *
 * - `status=published` (padrão): listagem pública global.
 * - `status=draft`: requer usuário autenticado; retorna os rascunhos do
 *   próprio usuário. A API devolve 401 se o token estiver ausente.
 */
export async function listPosts(
  page = 1,
  perPage = 20,
  status: PostStatus = 'published',
  includeCounts = false,
) {
  const { data } = await http.get<PostsPage>('/posts', {
    params: { page, per_page: perPage, status, include_counts: includeCounts ? 1 : 0 },
  })
  return data
}

/**
 * Carrega um post específico com autor e imagens.
 */
export async function getPost(id: number | string): Promise<PostRow> {
  const { data } = await http.get<{ post: PostRow }>(`/posts/${id}`)
  return data.post
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
 * Upload de nova imagem para um post já existente (rascunho ou publicado).
 */
export async function uploadPostImage(
  postId: number,
  file: File,
  onProgress?: (percent: number) => void,
): Promise<GalleryRow> {
  const form = new FormData()
  form.append('file', file)

  const { data } = await http.post<{ gallery: GalleryRow }>(
    `/posts/${postId}/images`,
    form,
    {
      headers: { 'Content-Type': 'multipart/form-data' },
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
 * Remove uma imagem específica de um post (banco + storage).
 */
export async function deletePostImage(
  postId: number,
  galleryId: number,
): Promise<void> {
  await http.delete(`/posts/${postId}/images/${galleryId}`)
}

export interface UpdatePostPayload {
  title?: string | null
  description?: string | null
  status?: PostStatus
}

/**
 * Atualiza metadados do post (título, descrição, status).
 *  - Para publicar: passe `status: 'published'`.
 *  - Para voltar a rascunho: passe `status: 'draft'`.
 *  - Para apenas editar metadados: envie somente `title`/`description`.
 */
export async function updatePost(
  postId: number,
  payload: UpdatePostPayload,
): Promise<PostRow> {
  const { data } = await http.put<{ post: PostRow }>(
    `/posts/${postId}`,
    payload,
    { timeout: 60_000 },
  )
  return data.post
}

/**
 * Exclui um post e todas as imagens/arquivos associados.
 */
export async function deletePost(postId: number): Promise<void> {
  await http.delete(`/posts/${postId}`, { timeout: 60_000 })
}

/**
 * Finaliza o post: cria Post e move todas as imagens do draft
 * (original + thumbs) para o caminho definitivo no storage.
 *
 * O timeout global de 15s do Axios é insuficiente porque o backend executa
 * N × (copy + delete) no MinIO para cada imagem e suas thumbnails; por isso
 * definimos um timeout maior específico para esta chamada.
 */
export async function createPost(
  draftUuid: string,
  title: string,
  description: string | null,
): Promise<PostRow> {
  const { data } = await http.post<{ post: PostRow }>(
    '/create-post',
    {
      draft_uuid: draftUuid,
      title,
      description: description ?? null,
    },
    { timeout: 180_000 },
  )
  return data.post
}
