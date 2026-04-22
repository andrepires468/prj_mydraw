<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getPost, type PostRow } from '@/api/posts'
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

const images = computed<PostMediaImage[]>(() =>
  (post.value?.images ?? [])
    .slice()
    .sort(
      (a, b) =>
        (a.position ?? 0) - (b.position ?? 0) || a.id - b.id,
    )
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

const authorName = computed(
  () => post.value?.user?.name?.trim() || 'Autor desconhecido',
)

const authorInitial = computed(
  () => authorName.value.charAt(0).toUpperCase() || '?',
)

const formattedDate = computed(() => {
  if (!post.value?.created_at) return ''
  const d = new Date(post.value.created_at)
  if (Number.isNaN(d.getTime())) return ''
  return d.toLocaleDateString('pt-BR', {
    day: '2-digit',
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
    error.value = err instanceof Error ? err.message : 'Erro desconhecido'
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
      <span>Voltar</span>
    </button>

    <LoadingIndicator v-if="loading" label="Carregando post..." />

    <p
      v-else-if="error"
      class="post-view__alert post-view__alert--error"
      role="alert"
    >
      <MaterialIcon name="error" filled />
      <span>Erro ao carregar post: {{ error }}.</span>
    </p>

    <p
      v-else-if="post && images.length === 0"
      class="post-view__alert"
      role="status"
    >
      Este post ainda não possui imagens visíveis.
    </p>

    <article v-else-if="post" class="post-view__layout">
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
          :alt-text="post.description ?? post.title"
        />
      </section>

      <aside class="post-view__info">
        <header class="post-view__header">
          <h1 class="post-view__title">{{ post.title }}</h1>
          <time v-if="formattedDate" class="post-view__date">
            Publicado em {{ formattedDate }}
          </time>
        </header>

        <div class="post-view__author">
          <div class="post-view__avatar" aria-hidden="true">
            {{ authorInitial }}
          </div>
          <div class="post-view__author-meta">
            <span class="post-view__author-label">Criado por</span>
            <span class="post-view__author-name">{{ authorName }}</span>
          </div>
        </div>

        <section v-if="post.description" class="post-view__description">
          <h2 class="post-view__section-title">Descrição</h2>
          <p>{{ post.description }}</p>
        </section>

        <dl class="post-view__stats">
          <div class="post-view__stat">
            <dt>Imagens</dt>
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
