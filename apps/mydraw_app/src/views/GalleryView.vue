<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import {
  listPosts,
  type PostsPage,
  type PostStatus,
  type PostsCounts,
  type PostRow,
} from '@/api/posts'
import { fetchEditorProfile, type EditorProfile } from '@/api/editorProfile'
import {
  isEditorProfileCacheFresh,
  readEditorProfileCache,
  writeEditorProfileCache,
} from '@/utils/editorProfileCache'
import GalleryEditorProfile from '@/components/gallery/GalleryEditorProfile.vue'
import { useAuthStore } from '@/stores/auth'
import GalleryFilters from '@/components/gallery/GalleryFilters.vue'
import GalleryStatusToggle from '@/components/gallery/GalleryStatusToggle.vue'
import MasonryGrid from '@/components/gallery/MasonryGrid.vue'
import GalleryCard, {
  type GalleryCardImage,
} from '@/components/gallery/GalleryCard.vue'
import LoadingIndicator from '@/components/gallery/LoadingIndicator.vue'
import type { GallerySort } from '@/components/gallery/types'
import AppModal from '@/components/common/AppModal.vue'
import PostView from '@/views/PostView.vue'

interface PostCard {
  id: number
  title: string
  category: string
  authorAvatarUrl: string | null
  images: GalleryCardImage[]
  /** Rascunhos abrem em modo de edição; publicados abrem em visualização. */
  isDraft: boolean
}

const GALLERY_PAGE_INITIAL = 10
const GALLERY_PAGE_MORE = 12
const GALLERY_SORT_STORAGE_KEY = 'mydraw:gallery-sort:v1'
const LEGACY_GALLERY_LIST_CACHE_PREFIX = 'mydraw:gallery-cache:'

function clearLegacyGalleryListCache(): void {
  if (typeof window === 'undefined') return
  try {
    for (let i = window.localStorage.length - 1; i >= 0; i--) {
      const key = window.localStorage.key(i)
      if (key?.startsWith(LEGACY_GALLERY_LIST_CACHE_PREFIX)) {
        window.localStorage.removeItem(key)
      }
    }
  } catch {
    /* storage indisponível */
  }
}

function readStoredSort(): GallerySort {
  if (typeof window === 'undefined') return 'newest'
  try {
    const raw = window.localStorage.getItem(GALLERY_SORT_STORAGE_KEY)
    if (raw === 'newest' || raw === 'oldest') return raw
  } catch {
    /* storage indisponível */
  }
  return 'newest'
}

function writeStoredSort(value: GallerySort): void {
  if (typeof window === 'undefined') return
  try {
    window.localStorage.setItem(GALLERY_SORT_STORAGE_KEY, value)
  } catch {
    /* quota / privado */
  }
}

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const editorProfile = ref<EditorProfile | null>(null)

function hydrateEditorProfileFromCache(): void {
  const stored = readEditorProfileCache()
  if (stored) {
    editorProfile.value = stored.profile
  }
}

async function refreshEditorProfileIfStale(): Promise<void> {
  const stored = readEditorProfileCache()
  if (stored && isEditorProfileCacheFresh(stored.fetchedAt)) {
    return
  }
  try {
    const profile = await fetchEditorProfile()
    editorProfile.value = profile
    writeEditorProfileCache(profile)
  } catch {
    if (!editorProfile.value && stored) {
      editorProfile.value = stored.profile
    }
  }
}

const loading = ref(false)
const error = ref<string | null>(null)
const page = ref<PostsPage | null>(null)
const sort = ref<GallerySort>(readStoredSort())
const counts = ref<PostsCounts>({ published: 0, draft: 0 })

/** Alinhado à rota (`/gallery/published` vs `/gallery/drafts`). */
const galleryStatus = computed<PostStatus>(() => {
  if (route.name === 'gallery-drafts' || route.name === 'gallery-drafts-post') {
    return 'draft'
  }
  return 'published'
})

const cards = computed<PostCard[]>(() => {
  if (!page.value) return []

  const result: PostCard[] = []
  for (const post of page.value.data) {
    // Para o card usamos preferencialmente a thumb 320x320 (mais leve);
    // caímos de volta para a original caso a thumb não exista (imagens
    // antigas, falha na geração, etc.).
    const images: GalleryCardImage[] = (post.images ?? [])
      .slice()
      .sort(
        (a, b) =>
          (a.position ?? 0) - (b.position ?? 0) || a.id - b.id,
      )
      .map((img): GalleryCardImage | null => {
        const src = img.thumbs?.['320x320'] ?? img.url
        if (!src) return null
        return {
          id: img.id,
          url: src,
          alt: post.description ?? post.title,
        }
      })
      .filter((img): img is GalleryCardImage => img !== null)

    if (images.length === 0) continue

    const fallbackTitle = post.status === 'draft' ? '(untitled draft)' : 'Post'

    result.push({
      id: post.id,
      title: post.title && post.title.trim().length > 0 ? post.title : fallbackTitle,
      category: post.user?.name ?? post.author_name ?? 'Post',
      authorAvatarUrl: post.user?.avatar_url ?? post.author_avatar_url ?? null,
      images,
      isDraft: post.status === 'draft',
    })
  }
  return result
})

const isEmptyState = computed(
  () => !loading.value && !error.value && cards.value.length === 0,
)

const emptyMessage = computed(() =>
  galleryStatus.value === 'draft'
    ? "You don't have any drafts yet. Start a new post from the top bar."
    : 'No published posts yet. Be the first to create one!',
)

const activePostId = computed<number | null>(() => {
  if (
    route.name !== 'gallery-published-post' &&
    route.name !== 'gallery-drafts-post'
  ) {
    return null
  }
  const rawId = route.params.id
  if (typeof rawId !== 'string') return null
  const parsed = Number(rawId)
  return Number.isFinite(parsed) ? parsed : null
})

/** Post já presente na página atual da lista; evita novo GET ao abrir o modal. */
const selectedPostFromList = computed<PostRow | null>(() => {
  if (activePostId.value === null || !page.value) return null
  return page.value.data.find((p) => p.id === activePostId.value) ?? null
})

const canEditActivePost = computed(() => {
  if (!auth.isAdmin || activePostId.value === null || !auth.user) return false
  const row = selectedPostFromList.value
  if (!row) return false
  return row.user_id === auth.user.id
})

/** Rota dedicada do post (`/admin/posts/:id`) é só para admin. */
const canOpenActivePostStandalone = computed(
  () => auth.isAdmin && activePostId.value !== null,
)

function listPayloadHasMore(payload: PostsPage, perPageForChunk: number): boolean {
  if (payload.has_more === true) return true
  if (payload.has_more === false) return false
  return payload.data.length >= perPageForChunk && Boolean(payload.next_page_url)
}

const loadingMore = ref(false)
const listHasMore = ref(false)
const nextOffset = ref(0)
const loadSentinelRef = ref<HTMLElement | null>(null)
let loadMoreObserver: IntersectionObserver | null = null

function resetGalleryListState(): void {
  page.value = null
  listHasMore.value = false
  nextOffset.value = 0
}

function detachLoadMoreObserver(): void {
  loadMoreObserver?.disconnect()
  loadMoreObserver = null
}

function attachLoadMoreObserver(): void {
  detachLoadMoreObserver()
  const el = loadSentinelRef.value
  if (!el) return
  loadMoreObserver = new IntersectionObserver(
    (entries) => {
      if (!entries.some((e) => e.isIntersecting)) return
      void loadMore()
    },
    { root: null, rootMargin: '280px 0px', threshold: 0 },
  )
  loadMoreObserver.observe(el)
}

async function loadInitial(includeCounts = false) {
  const snapshot =
    page.value !== null
      ? {
          page: page.value,
          hasMore: listHasMore.value,
          offset: nextOffset.value,
        }
      : null

  loading.value = page.value === null
  loadingMore.value = false
  error.value = null
  if (page.value === null) {
    listHasMore.value = false
    nextOffset.value = 0
  }

  const fetchPromise = listPosts(
    1,
    GALLERY_PAGE_INITIAL,
    galleryStatus.value,
    includeCounts,
    sort.value,
    null,
  )

  try {
    const nextPage = await fetchPromise
    page.value = nextPage
    if (nextPage.counts) {
      counts.value = nextPage.counts
    }
    nextOffset.value = nextPage.data.length
    listHasMore.value = listPayloadHasMore(nextPage, GALLERY_PAGE_INITIAL)
  } catch (err: unknown) {
    error.value = err instanceof Error ? err.message : 'Unknown error'
    if (snapshot) {
      page.value = snapshot.page
      listHasMore.value = snapshot.hasMore
      nextOffset.value = snapshot.offset
    } else {
      if (!page.value) {
        counts.value = { published: 0, draft: 0 }
      }
    }
  } finally {
    loading.value = false
  }
}

async function loadMore() {
  if (!listHasMore.value || loading.value || loadingMore.value || !page.value) return

  loadingMore.value = true
  error.value = null
  try {
    const chunk = await listPosts(
      1,
      GALLERY_PAGE_MORE,
      galleryStatus.value,
      false,
      sort.value,
      nextOffset.value,
    )
    const merged: PostsPage = {
      ...chunk,
      data: [...page.value.data, ...chunk.data],
      counts: page.value.counts ?? chunk.counts,
    }
    page.value = merged
    nextOffset.value = merged.data.length
    listHasMore.value = listPayloadHasMore(chunk, GALLERY_PAGE_MORE)
  } catch (err: unknown) {
    error.value = err instanceof Error ? err.message : 'Unknown error'
  } finally {
    loadingMore.value = false
  }
}

function changeStatus(next: PostStatus) {
  if (next === galleryStatus.value) return
  router.push({
    name: next === 'draft' ? 'gallery-drafts' : 'gallery-published',
  })
}

function closePostModal() {
  router.push({
    name:
      route.name === 'gallery-drafts-post'
        ? 'gallery-drafts'
        : 'gallery-published',
  })
}

watch(galleryStatus, () => {
  resetGalleryListState()
  void loadInitial(false)
})

watch(sort, () => {
  writeStoredSort(sort.value)
  resetGalleryListState()
  void loadInitial(false)
})

onMounted(async () => {
  clearLegacyGalleryListCache()
  hydrateEditorProfileFromCache()
  void refreshEditorProfileIfStale()
  await loadInitial(true)
  await nextTick()
  attachLoadMoreObserver()
})

onUnmounted(() => {
  detachLoadMoreObserver()
})
</script>

<template>
  <main class="app-page-inner app-page-inner--mobile-nav pt-12 w-full">
    <div class="gallery-toolbar">
      <GalleryStatusToggle
        :model-value="galleryStatus"
        :counts="counts"
        :show-draft="auth.isAdmin"
        @update:model-value="changeStatus"
      />
      <div class="gallery-toolbar__actions">
        <RouterLink
          v-if="galleryStatus === 'published' && auth.isAdmin"
          :to="{ name: 'gallery-published-reorder' }"
          class="gallery-toolbar__reorder-link"
        >
          Reorder posts
        </RouterLink>
        <GalleryFilters :sort="sort" @update:sort="sort = $event" />
      </div>
    </div>

    <p
      v-if="error"
      class="mb-6 text-sm text-on-error bg-error/10 border border-error/30 rounded-lg px-4 py-3"
    >
      Failed to load posts: {{ error }}.
    </p>

    <p
      v-else-if="isEmptyState"
      class="mb-6 text-sm text-on-surface-variant italic"
    >
      {{ emptyMessage }}
    </p>

    <MasonryGrid class="mb-8">
      <article class="gallery-editor-card">
        <GalleryEditorProfile :profile="editorProfile" />
      </article>

      <RouterLink
        v-for="card in cards"
        :key="card.id"
        :to="
          card.isDraft
            ? { name: 'edit-post', params: { post_id: card.id } }
            : {
                name: 'gallery-published-post',
                params: { id: card.id },
              }
        "
        class="gallery-card-link"
        :aria-label="
          card.isDraft
            ? `Edit draft ${card.title}`
            : `Open post ${card.title}`
        "
      >
        <GalleryCard
          :title="card.title"
          :category="card.category"
          :author-avatar-url="card.authorAvatarUrl"
          :images="card.images"
        />
      </RouterLink>
    </MasonryGrid>

    <div
      ref="loadSentinelRef"
      class="gallery-load-sentinel h-px w-full shrink-0"
      aria-hidden="true"
    />

    <AppModal
      v-if="activePostId !== null"
      title="Post preview"
      @close="closePostModal"
    >
      <template #actions>
        <RouterLink
          v-if="canEditActivePost"
          :to="{ name: 'edit-post', params: { post_id: activePostId } }"
          class="app-modal__edit-post"
        >
          Edit post
        </RouterLink>
        <RouterLink
          v-if="canOpenActivePostStandalone"
          :to="{ name: 'post', params: { id: activePostId! } }"
          class="app-modal__open-window"
        >
          Open in full page
        </RouterLink>
      </template>

      <PostView
        :post-id="activePostId"
        :initial-post="selectedPostFromList"
        embedded
        @close="closePostModal"
      />
    </AppModal>

    <LoadingIndicator v-if="loading" label="Loading posts..." />
    <LoadingIndicator
      v-else-if="loadingMore"
      label="Loading more posts..."
    />

    <RouterView class="hidden" aria-hidden="true" />
  </main>
</template>
