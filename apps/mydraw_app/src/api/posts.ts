import { http } from './http'
import type { GallerySort } from '@/components/gallery/types'

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
  /** Present in post listings; whether more items exist after this page/chunk. */
  has_more?: boolean
}

/**
 * Paginated posts with gallery images eager-loaded.
 *
 * - `status=published` (default): global public feed.
 * - `status=draft`: requires auth; returns the current user's drafts (401 if no token).
 */
export async function listPosts(
  page = 1,
  perPage = 20,
  status: PostStatus = 'published',
  includeCounts = false,
  sort: GallerySort = 'newest',
  /** Absolute offset in the sorted list (API may ignore `page` when set). */
  offset?: number | null,
) {
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
  const { data } = await http.get<PostsPage>('/posts', { params })
  return data
}

/** Loads a single post with author and images. */
export async function getPost(id: number | string): Promise<PostRow> {
  const { data } = await http.get<{ post: PostRow }>(`/posts/${id}`)
  return data.post
}

/** Upload an image for a draft identified by UUID. */
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
      // Remote MinIO upload can be slow; default 15s client timeout is too low.
      timeout: 120_000,
      onUploadProgress: (evt) => {
        if (!onProgress || !evt.total) return
        onProgress(Math.round((evt.loaded * 100) / evt.total))
      },
    },
  )
  return data.gallery
}

/** Upload a new image to an existing post (draft or published). */
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

/** Remove one image from a post (DB + storage). */
export async function deletePostImage(
  postId: number,
  galleryId: number,
): Promise<void> {
  await http.delete(`/posts/${postId}/images/${galleryId}`)
}

/**
 * Persist image order (`gallery.position`).
 * `order` must list each `gallery.id` for the post exactly once.
 */
export async function reorderPostImages(
  postId: number,
  order: number[],
): Promise<void> {
  await http.put(`/posts/${postId}/images/reorder`, { order }, { timeout: 120_000 })
}

export interface UpdatePostPayload {
  title?: string | null
  description?: string | null
  status?: PostStatus
}

/**
 * Update post metadata (title, description, status).
 *  - Publish: `status: 'published'`.
 *  - Move back to draft: `status: 'draft'`.
 *  - Metadata only: send `title` / `description` only.
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

/** Delete a post and all associated images/files. */
export async function deletePost(postId: number): Promise<void> {
  await http.delete(`/posts/${postId}`, { timeout: 60_000 })
}

/**
 * Finalize post creation: creates the Post row and moves draft images
 * (original + thumbs) to their final storage paths.
 *
 * Uses an extended timeout because the backend may run N×(copy+delete) on MinIO
 * per image and thumbnails; the default 15s Axios limit is not enough.
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
