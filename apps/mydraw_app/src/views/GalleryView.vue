<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import {
  listPosts,
  type PostsPage,
  type PostStatus,
  type PostsCounts,
  type PostRow,
} from '@/api/posts'
import { useAuthStore } from '@/stores/auth'
import GalleryHero from '@/components/gallery/GalleryHero.vue'
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
  images: GalleryCardImage[]
  /** Rascunhos abrem em modo de edição; publicados abrem em visualização. */
  isDraft: boolean
}

const GALLERY_CACHE_PREFIX = 'mydraw:gallery-cache:v1'
const GALLERY_SORT_STORAGE_KEY = 'mydraw:gallery-sort:v1'

interface GalleryCachePayload {
  page: PostsPage
  counts: PostsCounts
  updatedAt: string
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

    const fallbackTitle = post.status === 'draft' ? '(rascunho sem título)' : 'Post'

    result.push({
      id: post.id,
      title: post.title && post.title.trim().length > 0 ? post.title : fallbackTitle,
      category: post.user?.name ?? post.author_name ?? 'Post',
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
    ? 'Você ainda não tem rascunhos. Comece um novo post na barra superior.'
    : 'Ainda não há posts publicados. Seja o primeiro a criar um!',
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
  if (!auth.isAuthenticated || activePostId.value === null || !auth.user) return false
  const row = selectedPostFromList.value
  if (!row) return false
  return row.user_id === auth.user.id
})

function getGalleryCacheKey(
  postStatus: PostStatus,
  sortOrder: GallerySort,
  pageNumber: number,
): string {
  return `${GALLERY_CACHE_PREFIX}:${postStatus}:sort:${sortOrder}:page:${pageNumber}`
}

function readGalleryCache(
  postStatus: PostStatus,
  sortOrder: GallerySort,
  pageNumber: number,
): GalleryCachePayload | null {
  if (typeof window === 'undefined') return null
  const raw = window.localStorage.getItem(getGalleryCacheKey(postStatus, sortOrder, pageNumber))
  if (!raw) return null
  try {
    const parsed = JSON.parse(raw) as GalleryCachePayload
    if (!parsed?.page || !Array.isArray(parsed.page.data) || !parsed.counts) return null
    return parsed
  } catch {
    return null
  }
}

function writeGalleryCache(
  postStatus: PostStatus,
  sortOrder: GallerySort,
  pageNumber: number,
  nextPage: PostsPage,
): void {
  if (typeof window === 'undefined') return
  const payload: GalleryCachePayload = {
    page: nextPage,
    counts: nextPage.counts ?? counts.value,
    updatedAt: new Date().toISOString(),
  }
  window.localStorage.setItem(
    getGalleryCacheKey(postStatus, sortOrder, pageNumber),
    JSON.stringify(payload),
  )
}

function hydrateGalleryFromCache(
  postStatus: PostStatus,
  sortOrder: GallerySort,
  pageNumber: number,
): boolean {
  const cached = readGalleryCache(postStatus, sortOrder, pageNumber)
  if (!cached) return false
  page.value = cached.page
  counts.value = cached.counts
  return true
}

async function load(p = 1, includeCounts = false) {
  loading.value = true
  error.value = null
  try {
    const nextPage = await listPosts(
      p,
      20,
      galleryStatus.value,
      includeCounts,
      sort.value,
    )
    page.value = nextPage
    if (nextPage.counts) {
      counts.value = nextPage.counts
    }
    writeGalleryCache(galleryStatus.value, sort.value, p, nextPage)
  } catch (err: unknown) {
    error.value = err instanceof Error ? err.message : 'Erro desconhecido'
    if (!page.value) {
      counts.value = { published: 0, draft: 0 }
    }
  } finally {
    loading.value = false
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
  hydrateGalleryFromCache(galleryStatus.value, sort.value, 1)
  void load(1, false)
})

watch(sort, () => {
  writeStoredSort(sort.value)
  hydrateGalleryFromCache(galleryStatus.value, sort.value, 1)
  void load(1, false)
})

onMounted(() => {
  hydrateGalleryFromCache(galleryStatus.value, sort.value, 1)
  void load(1, true)
})
</script>

<template>
  <main class="app-page-inner pt-12 pb-32 md:pb-24 w-full">
    <div class="gallery-toolbar">
      <GalleryStatusToggle
        :model-value="galleryStatus"
        :counts="counts"
        :show-draft="auth.isAuthenticated"
        @update:model-value="changeStatus"
      />
      <GalleryFilters :sort="sort" @update:sort="sort = $event" />
    </div>

    <p
      v-if="error"
      class="mb-6 text-sm text-on-error bg-error/10 border border-error/30 rounded-lg px-4 py-3"
    >
      Erro ao carregar posts: {{ error }}.
    </p>

    <p
      v-else-if="isEmptyState"
      class="mb-6 text-sm text-on-surface-variant italic"
    >
      {{ emptyMessage }}
    </p>

    <MasonryGrid class="mb-8">
      <article class="gallery-hero-card">
        <GalleryHero />
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
            ? `Editar rascunho ${card.title}`
            : `Abrir post ${card.title}`
        "
      >
        <GalleryCard
          :title="card.title"
          :category="card.category"
          :images="card.images"
        />
      </RouterLink>
    </MasonryGrid>

    <AppModal
      v-if="activePostId !== null"
      title="Visualização do post"
      @close="closePostModal"
    >
      <template #actions>
        <RouterLink
          v-if="canEditActivePost"
          :to="{ name: 'edit-post', params: { post_id: activePostId } }"
          class="app-modal__edit-post"
        >
          Editar post
        </RouterLink>
        <RouterLink
          :to="{ name: 'post', params: { id: activePostId } }"
          class="app-modal__open-window"
        >
          Abrir na janela
        </RouterLink>
      </template>

      <PostView
        :post-id="activePostId"
        :initial-post="selectedPostFromList"
        embedded
        @close="closePostModal"
      />
    </AppModal>

    <LoadingIndicator v-if="loading" label="Carregando posts..." />
  </main>
</template>
