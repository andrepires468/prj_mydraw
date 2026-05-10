<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getPost, type GalleryRow, type PostRow } from '@/api/posts'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import LoadingIndicator from '@/components/gallery/LoadingIndicator.vue'
import PostViewModeToggle from '@/components/post/PostViewModeToggle.vue'
import PostImageCarousel from '@/components/post/PostImageCarousel.vue'
import PostImageList from '@/components/post/PostImageList.vue'
import type { PostMediaImage, PostMediaViewMode } from '@/components/post/post-media'

const MEDIA_VIEW_MODE_STORAGE_KEY = 'mydraw:post-view:media-mode'

function postIdMatches(
  resolved: number | string | null,
  candidate: number,
): boolean {
  if (resolved === null) return false
  return Number(resolved) === Number(candidate)
}

function readSavedMediaViewMode(): PostMediaViewMode {
  if (typeof window === 'undefined') return 'carousel'
  const saved = window.localStorage.getItem(MEDIA_VIEW_MODE_STORAGE_KEY)
  return saved === 'list' ? 'list' : 'carousel'
}

const route = useRoute()
const router = useRouter()

interface Props {
  postId?: number | string
  embedded?: boolean
  /** Dados já carregados (ex.: lista da galeria); evita GET /posts/:id. */
  initialPost?: PostRow | null
}

const props = withDefaults(defineProps<Props>(), {
  postId: undefined,
  embedded: false,
  initialPost: undefined,
})

const emit = defineEmits<{
  close: []
}>()

const post = ref<PostRow | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const mediaViewMode = ref<PostMediaViewMode>(readSavedMediaViewMode())

const sortedGalleryRows = computed<GalleryRow[]>(() =>
  (post.value?.images ?? [])
    .slice()
    .sort(
      (a, b) =>
        (a.position ?? 0) - (b.position ?? 0) || a.id - b.id,
    ),
)

const images = computed<PostMediaImage[]>(() =>
  sortedGalleryRows.value
    .map((img): PostMediaImage | null => {
      if (!img.url) return null
      return {
        id: img.id,
        url: img.url,
        thumbUrl: img.thumbs?.['180x180'] ?? img.url,
      }
    })
    .filter((img): img is PostMediaImage => img !== null),
)

/** Gallery rows aligned with `images` (visible URLs only), for list-mode metadata. */
const galleryRowsAlignedWithImages = computed<(GalleryRow | null)[]>(() =>
  images.value.map((img) => {
    const row = sortedGalleryRows.value.find((r) => r.id === img.id)
    return row ?? null
  }),
)

const authorName = computed(
  () =>
    post.value?.user?.name?.trim()
    || post.value?.author_name?.trim()
    || 'Unknown author',
)

const authorInitial = computed(
  () => authorName.value.charAt(0).toUpperCase() || '?',
)

const authorAvatarFailed = ref(false)

const authorAvatarUrl = computed(
  () => post.value?.user?.avatar_url ?? post.value?.author_avatar_url ?? null,
)

watch(
  () => authorAvatarUrl.value,
  () => {
    authorAvatarFailed.value = false
  },
)

const showAuthorPhoto = computed(
  () => Boolean(authorAvatarUrl.value) && !authorAvatarFailed.value,
)

const formattedDate = computed(() => {
  if (!post.value?.created_at) return ''
  const d = new Date(post.value.created_at)
  if (Number.isNaN(d.getTime())) return ''
  return d.toLocaleDateString('en-US', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  })
})

const resolvedPostId = computed<number | string | null>(() => {
  if (props.postId !== undefined && props.postId !== null) {
    return props.postId
  }
  const id = route.params.id
  return id ? (id as string) : null
})

async function load(id: number | string) {
  loading.value = true
  error.value = null
  post.value = null
  try {
    post.value = await getPost(id)
  } catch (err: unknown) {
    error.value = err instanceof Error ? err.message : 'Unknown error'
  } finally {
    loading.value = false
  }
}

function goBack() {
  if (props.embedded) {
    emit('close')
    return
  }
  if (window.history.length > 1) router.back()
  else router.push({ name: 'gallery-published' })
}

watch(mediaViewMode, (mode) => {
  if (typeof window === 'undefined') return
  window.localStorage.setItem(MEDIA_VIEW_MODE_STORAGE_KEY, mode)
})

watch(
  [resolvedPostId, () => props.initialPost],
  ([id, initial]) => {
    if (!id) {
      post.value = null
      error.value = null
      loading.value = false
      return
    }
    if (initial && postIdMatches(id, initial.id)) {
      post.value = initial
      error.value = null
      loading.value = false
      return
    }
    void load(id as string)
  },
  { immediate: true },
)
</script>

<template>
  <component
    :is="props.embedded ? 'div' : 'main'"
    class="post-view"
    :class="{
      'post-view--embedded': props.embedded,
      'app-page-inner': !props.embedded,
    }"
  >
    <button
      v-if="!props.embedded"
      type="button"
      class="post-view__back"
      @click="goBack"
    >
      <MaterialIcon name="arrow_back" />
      <span>Back</span>
    </button>

    <LoadingIndicator v-if="loading" label="Loading post..." />

    <p
      v-else-if="error"
      class="post-view__alert post-view__alert--error"
      role="alert"
    >
      <MaterialIcon name="error" filled />
      <span>Failed to load post: {{ error }}.</span>
    </p>

    <p
      v-else-if="post && images.length === 0"
      class="post-view__alert"
      role="status"
    >
      This post has no visible images yet.
    </p>

    <article
      v-else-if="post"
      class="post-view__layout"
      :class="{ 'post-view__layout--list-only': mediaViewMode === 'list' }"
    >
      <section class="post-view__media-wrapper">
        <PostViewModeToggle
          v-if="images.length > 1"
          v-model="mediaViewMode"
        />

        <PostImageCarousel
          v-if="mediaViewMode === 'carousel'"
          :images="images"
          :alt-text="post.description ?? post.title"
        />
        <PostImageList
          v-else
          :images="images"
          :gallery-rows="galleryRowsAlignedWithImages"
          :alt-text="post.description ?? post.title"
        />
      </section>

      <aside
        v-if="mediaViewMode === 'carousel'"
        class="post-view__info"
      >
        <header class="post-view__header">
          <h1 class="post-view__title">{{ post.title }}</h1>
          <time v-if="formattedDate" class="post-view__date">
            Published on {{ formattedDate }}
          </time>
        </header>

        <div class="post-view__author">
          <div class="post-view__avatar-slot" aria-hidden="true">
            <img
              v-if="showAuthorPhoto"
              :src="authorAvatarUrl!"
              alt=""
              class="post-view__avatar-img"
              width="44"
              height="44"
              @error="authorAvatarFailed = true"
            />
            <div
              v-else
              class="post-view__avatar post-view__avatar--fallback"
            >
              {{ authorInitial }}
            </div>
          </div>
          <div class="post-view__author-meta">
            <span class="post-view__author-label">Created by</span>
            <span class="post-view__author-name">{{ authorName }}</span>
          </div>
        </div>

        <section v-if="post.description" class="post-view__description">
          <h2 class="post-view__section-title">Description</h2>
          <p>{{ post.description }}</p>
        </section>

        <dl class="post-view__stats">
          <div class="post-view__stat">
            <dt>Images</dt>
            <dd>{{ images.length }}</dd>
          </div>
          <div class="post-view__stat">
            <dt>ID</dt>
            <dd>#{{ post.id }}</dd>
          </div>
        </dl>
      </aside>
    </article>
  </component>
</template>
