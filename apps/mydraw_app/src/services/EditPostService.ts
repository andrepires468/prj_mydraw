import { http } from '@/api/http'
import type { ApiEnvelope } from '@/services/apiContract'
import { unwrapEnvelope } from '@/services/apiContract'
import { routes } from '@/services/routes'
import type { GalleryRow, PostRow, UpdatePostPayload } from '@/services/PostService'

export async function uploadPostImage(
  postId: number,
  file: File,
  onProgress?: (percent: number) => void,
): Promise<GalleryRow> {
  const form = new FormData()
  form.append('file', file)

  const { data } = await http.post<ApiEnvelope<{ gallery: GalleryRow }>>(
    routes.posts.images(postId),
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

export async function deletePostImage(
  postId: number,
  galleryId: number,
): Promise<void> {
  const { data } = await http.delete<ApiEnvelope<{ deleted: boolean }>>(
    routes.posts.imageOne(postId, galleryId),
  )
  unwrapEnvelope<{ deleted: boolean }>(data)
}

export async function reorderPostImages(
  postId: number,
  order: number[],
): Promise<void> {
  const { data } = await http.put<ApiEnvelope<{ ok: boolean }>>(
    routes.posts.reorder(postId),
    { order },
    { timeout: 120_000 },
  )
  unwrapEnvelope<{ ok: boolean }>(data)
}

export interface UpdatePostResult {
  post: PostRow
  metadata_extraction_error?: string
}

export async function updatePost(
  postId: number,
  payload: UpdatePostPayload,
): Promise<UpdatePostResult> {
  const { data } = await http.put<ApiEnvelope<UpdatePostResult>>(
    routes.posts.update(postId),
    payload,
    { timeout: 60_000 },
  )
  return unwrapEnvelope<UpdatePostResult>(data)
}

export async function deletePost(postId: number): Promise<void> {
  const { data } = await http.delete<ApiEnvelope<{ deleted: boolean }>>(
    routes.posts.update(postId),
    { timeout: 60_000 },
  )
  unwrapEnvelope<{ deleted: boolean }>(data)
}

export interface ExtractImageMetadataSummary {
  total: number
  updated: number
  no_metadata: number
  failed: number
}

export interface ExtractImageMetadataErrorItem {
  gallery_id: number
  error: string
  message?: string
}

export interface ExtractImageMetadataResponse {
  summary: ExtractImageMetadataSummary
  errors: ExtractImageMetadataErrorItem[]
  post: PostRow
}

/** Resposta de extract com post_id (opcionalmente com AI). */
export interface AiCategorizationBatchSummary {
  skipped?: boolean
  reason?: string
  summary: {
    posts_total: number
    posts_with_updates: number
    posts_skipped_no_prompt: number
    posts_failed: number
  }
  errors: Array<{ post_id: number; message: string; http_status: number }>
}

/** Eco do backend: post processado. */
export interface AdminGalleryMetadataBatchInfo {
  post_id: number
  scope_post_ids: number[]
}

export interface ExtractPostMetadataResponse {
  summary: ExtractImageMetadataSummary
  errors: ExtractImageMetadataErrorItem[]
  post: PostRow
  ai_categorization_batch?: AiCategorizationBatchSummary
  batch?: AdminGalleryMetadataBatchInfo
}

export type ExtractGalleryMetadataResponse = ExtractPostMetadataResponse

export async function extractGalleryMetadata(options: {
  postId: number
  includeAiCategorize?: boolean
}): Promise<ExtractGalleryMetadataResponse> {
  const body = {
    post_id: options.postId,
    ...(options.includeAiCategorize ? { include_ai_categorize: true } : {}),
  }
  const { data } = await http.post<ApiEnvelope<ExtractGalleryMetadataResponse>>(
    routes.posts.metadataExtract,
    body,
    /** Metadados + até ~4 min de Gemini; margem para um post. */
    { timeout: 360_000 },
  )
  return unwrapEnvelope<ExtractGalleryMetadataResponse>(data)
}

export interface CategorizePostAiSummary {
  metadata_extraction: ExtractImageMetadataSummary | null
  categorization: {
    total_with_prompt: number
    updated: number
    skipped_missing_prompt: number
    failed: number
    source_gallery_id?: number | null
    skipped?: boolean
    reason?: string
  }
}

export interface CategorizePostAiErrorItem {
  gallery_id: number
  error: string
}

export interface CategorizePostAiResponse {
  summary: CategorizePostAiSummary
  errors: CategorizePostAiErrorItem[]
  post: PostRow
}

export async function categorizePostWithAi(
  postId: number,
): Promise<CategorizePostAiResponse> {
  const { data } = await http.post<ApiEnvelope<CategorizePostAiResponse>>(
    routes.posts.aiCategorize(postId),
    {},
    { timeout: 360_000 },
  )
  return unwrapEnvelope<CategorizePostAiResponse>(data)
}
