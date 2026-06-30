import { http } from '@/api/http'
import type { GallerySort } from '@/components/gallery/types'
import type { ApiEnvelope } from '@/services/apiContract'
import { unwrapEnvelope } from '@/services/apiContract'
import { routes } from '@/services/routes'

export type PostStatus = 'draft' | 'published'

/**
 * URLs for thumbnails generated on upload. Keys match the max-resolution
 * labels applied (the stored image may be smaller if aspect ratio is preserved).
 *
 * `null` means that thumbnail was not generated or is missing in the bucket
 * (e.g. legacy images from before the feature existed).
 */
export interface GalleryThumbs {
  '320x320': string | null
  '180x180'?: string | null
}

/** Matches backend `gallery.metadata_type` when extracted from PNG/WebUI. */
export type GalleryMetadataType = 'stable_diffusion'

export interface StableDiffusionLoraMetadata {
  name: string
  weight: number | null
  hash: string | null
  creator?: string | null
  creator_url?: string | null
  model_url?: string | null
  source?: string | null
}

/** Civitai-style audience rating stored per image / post. */
export type ContentRating =
  | 'PG'
  | 'PG-13'
  | 'R'
  | 'X'
  | 'XXX'

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
  metadata_type?: GalleryMetadataType | null
  metadata_prompt?: string | null
  metadata_negative_prompt?: string | null
  metadata_steps?: number | null
  metadata_sampler?: string | null
  metadata_schedule_type?: string | null
  metadata_cfg_scale?: number | null
  metadata_seed?: number | null
  metadata_width?: number | null
  metadata_height?: number | null
  metadata_model?: string | null
  metadata_model_hash?: string | null
  metadata_clip_skip?: number | null
  metadata_version?: string | null
  metadata_loras?: StableDiffusionLoraMetadata[] | null
  metadata_raw?: Record<string, unknown> | null
  ai_tags?: string[] | null
  ai_rating?: ContentRating | null
  ai_rating_reason?: string | null
  ai_categorized_at?: string | null
  created_at: string
  updated_at: string
}

export interface PostUser {
  id: number
  name: string | null
  email?: string | null
  avatar_url?: string | null
}

export interface PostRow {
  id: number
  user_id: number
  title: string
  description: string | null
  status: PostStatus
  draft_uuid: string | null
  sort_order?: number
  author_name?: string | null
  author_avatar_url?: string | null
  ai_tags?: string[] | null
  content_rating?: ContentRating | null
  ai_categorized_at?: string | null
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
  has_more?: boolean
}

export interface UpdatePostPayload {
  title?: string | null
  description?: string | null
  status?: PostStatus
}

export async function listPosts(
  page = 1,
  perPage = 20,
  status: PostStatus = 'published',
  includeCounts = false,
  sort: GallerySort = 'newest',
  offset?: number | null,
): Promise<PostsPage> {
  const params: Record<string, string | number> = {
    page,
    per_page: perPage,
    status,
    include_counts: includeCounts ? 1 : 0,
    sort,
  }
  if (offset != null && offset >= 0) {
    params.offset = offset
  }
  const { data } = await http.get<ApiEnvelope<PostsPage>>(routes.posts.list, {
    params,
  })
  return unwrapEnvelope<PostsPage>(data)
}

export async function getPost(id: number | string): Promise<PostRow> {
  const { data } = await http.get<ApiEnvelope<{ post: PostRow }>>(
    routes.posts.one(id),
  )
  return unwrapEnvelope<{ post: PostRow }>(data).post
}

export interface PostReorderRow {
  id: number
  title: string
  sort_order: number
  cover_url: string | null
}

export async function listPostsForReorder(): Promise<PostReorderRow[]> {
  const { data } = await http.get<ApiEnvelope<{ posts: PostReorderRow[] }>>(
    routes.posts.reorderList,
  )
  return unwrapEnvelope<{ posts: PostReorderRow[] }>(data).posts
}

export async function reorderPosts(order: number[]): Promise<void> {
  const { data } = await http.put<ApiEnvelope<{ ok: boolean }>>(
    routes.posts.reorderPosts,
    { order },
  )
  unwrapEnvelope<{ ok: boolean }>(data)
}
