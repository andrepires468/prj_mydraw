import { http } from '@/api/http'
import type { ApiEnvelope } from '@/services/apiContract'
import { unwrapEnvelope } from '@/services/apiContract'
import { routes } from '@/services/routes'
import type { GalleryRow, PostRow } from '@/services/PostService'

export async function uploadGalleryImage(
  draftUuid: string,
  file: File,
  onProgress?: (percent: number) => void,
): Promise<GalleryRow> {
  const form = new FormData()
  form.append('file', file)
  form.append('draft_uuid', draftUuid)

  const { data } = await http.post<ApiEnvelope<{ gallery: GalleryRow }>>(
    routes.createPost.upload,
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
  return unwrapEnvelope<{ gallery: GalleryRow }>(data).gallery
}

export async function createPost(
  draftUuid: string,
  title: string,
  description: string | null,
): Promise<PostRow> {
  const { data } = await http.post<ApiEnvelope<{ post: PostRow }>>(
    routes.createPost.finalize,
    {
      draft_uuid: draftUuid,
      title,
      description: description ?? null,
    },
    { timeout: 180_000 },
  )
  return unwrapEnvelope<{ post: PostRow }>(data).post
}
