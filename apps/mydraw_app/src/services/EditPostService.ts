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

export async function updatePost(
  postId: number,
  payload: UpdatePostPayload,
): Promise<PostRow> {
  const { data } = await http.put<ApiEnvelope<{ post: PostRow }>>(
    routes.posts.update(postId),
    payload,
    { timeout: 60_000 },
  )
  return unwrapEnvelope<{ post: PostRow }>(data).post
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
}

export interface ExtractImageMetadataResponse {
  summary: ExtractImageMetadataSummary
  errors: ExtractImageMetadataErrorItem[]
  post: PostRow
}

export interface ExtractAllPostsMetadataResponse {
  summary: ExtractImageMetadataSummary
  errors: ExtractImageMetadataErrorItem[]
  posts_touched: number
}

export type ExtractGalleryMetadataResponse =
  | ExtractImageMetadataResponse
  | ExtractAllPostsMetadataResponse

export async function extractGalleryMetadata(
  options: { postId: number } | { allPosts: true },
): Promise<ExtractGalleryMetadataResponse> {
  const body =
    'postId' in options
      ? { post_id: options.postId }
      : { all_posts: true }
  const { data } = await http.post<ApiEnvelope<ExtractGalleryMetadataResponse>>(
    routes.posts.metadataExtract,
    body,
    { timeout: 300_000 },
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
    { timeout: 300_000 },
  )
  return unwrapEnvelope<CategorizePostAiResponse>(data)
}
