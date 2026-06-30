import type {
  GalleryRow,
  PostRow,
  PostsCounts,
  PostsPage,
  PostStatus,
} from '@/api/posts'
import type { GallerySort } from '@/components/gallery/types'
import { isNativePlatform } from '@/utils/platform'
import { rewriteStorageUrlsInData } from '@/utils/storageUrl'

/** v4: payload enxuto (sem metadados SD por imagem) + limpeza de chaves legadas. */
export const GALLERY_CACHE_PREFIX = 'mydraw:gallery-cache:v4'

export interface GalleryCachePayload {
  page: PostsPage
  counts: PostsCounts
  updatedAt: string
}

const LEGACY_CACHE_PREFIXES = [
  'mydraw:gallery-cache:v1',
  'mydraw:gallery-cache:v2',
  'mydraw:gallery-cache:v3',
]

function getGalleryCacheKey(postStatus: PostStatus, sortOrder: GallerySort): string {
  return `${GALLERY_CACHE_PREFIX}:${postStatus}:sort:${sortOrder}:first`
}

function slimGalleryImage(image: GalleryRow): GalleryRow {
  return {
    id: image.id,
    post_id: image.post_id,
    user_id: image.user_id,
    draft_uuid: image.draft_uuid,
    object_key: image.object_key,
    mime_type: image.mime_type,
    size: image.size,
    position: image.position,
    url: image.url,
    thumbs: {
      '320x320': image.thumbs?.['320x320'] ?? null,
      '180x180': image.thumbs?.['180x180'] ?? null,
    },
    created_at: image.created_at,
    updated_at: image.updated_at,
  }
}

function slimPostForCache(post: PostRow): PostRow {
  return {
    id: post.id,
    user_id: post.user_id,
    title: post.title,
    description: post.description,
    status: post.status,
    draft_uuid: post.draft_uuid,
    author_name: post.author_name,
    author_avatar_url: post.author_avatar_url,
    created_at: post.created_at,
    updated_at: post.updated_at,
    user: post.user
      ? {
          id: post.user.id,
          name: post.user.name,
          avatar_url: post.user.avatar_url,
        }
      : undefined,
    images: (post.images ?? []).map(slimGalleryImage),
  }
}

function slimPostsPageForCache(page: PostsPage): PostsPage {
  return {
    ...page,
    data: page.data.map(slimPostForCache),
  }
}

/** Libera ~3 MB de caches antigos (v1–v3) que ficaram após upgrades. */
export function pruneLegacyGalleryCacheKeys(): void {
  if (typeof window === 'undefined') return
  try {
    for (const key of Object.keys(window.localStorage)) {
      if (LEGACY_CACHE_PREFIXES.some((prefix) => key.startsWith(prefix))) {
        window.localStorage.removeItem(key)
      }
    }
  } catch {
    /* storage indisponível */
  }
}

pruneLegacyGalleryCacheKeys()

function maybeRewritePayload<T>(value: T): T {
  if (!isNativePlatform()) return value
  return rewriteStorageUrlsInData(value)
}

export function readGalleryCache(
  postStatus: PostStatus,
  sortOrder: GallerySort,
): GalleryCachePayload | null {
  if (typeof window === 'undefined') return null
  try {
    const raw = window.localStorage.getItem(getGalleryCacheKey(postStatus, sortOrder))
    if (!raw) return null
    const parsed = JSON.parse(raw) as GalleryCachePayload
    if (!parsed?.page || !Array.isArray(parsed.page.data) || !parsed.counts) return null
    return maybeRewritePayload(parsed)
  } catch {
    return null
  }
}

function removeOtherGalleryCacheKeys(keepKey: string): void {
  if (typeof window === 'undefined') return
  for (const key of Object.keys(window.localStorage)) {
    if (key.startsWith('mydraw:gallery-cache:') && key !== keepKey) {
      window.localStorage.removeItem(key)
    }
  }
}

export function writeGalleryCache(
  postStatus: PostStatus,
  sortOrder: GallerySort,
  nextPage: PostsPage,
  counts: PostsCounts,
): void {
  if (typeof window === 'undefined') return

  const cacheKey = getGalleryCacheKey(postStatus, sortOrder)
  const payload: GalleryCachePayload = {
    page: slimPostsPageForCache(nextPage),
    counts,
    updatedAt: new Date().toISOString(),
  }
  const serialized = JSON.stringify(payload)

  const persist = (): boolean => {
    try {
      window.localStorage.setItem(cacheKey, serialized)
      return true
    } catch {
      return false
    }
  }

  if (persist()) return

  removeOtherGalleryCacheKeys(cacheKey)
  persist()
}

/** Clears all gallery list caches (e.g. after manual post reorder). */
export function clearGalleryCache(): void {
  if (typeof window === 'undefined') return
  try {
    for (const key of Object.keys(window.localStorage)) {
      if (key.startsWith('mydraw:gallery-cache:')) {
        window.localStorage.removeItem(key)
      }
    }
  } catch {
    /* storage indisponível */
  }
}
