<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getPost, type PostRow } from '@/api/posts'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import LoadingIndicator from '@/components/gallery/LoadingIndicator.vue'

const route = useRoute()
const router = useRouter()

const post = ref<PostRow | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const current = ref(0)
const failedIds = ref<Set<number | string>>(new Set())

const images = computed(() =>
  (post.value?.images ?? []).filter((img) => !!img.url),
)

const activeImage = computed(() => images.value[current.value] ?? null)

const activeFailed = computed(
  () => !!activeImage.value && failedIds.value.has(activeImage.value.id),
)

const hasMultiple = computed(() => images.value.length > 1)

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

async function load(id: number | string) {
  loading.value = true
  error.value = null
  post.value = null
  current.value = 0
  failedIds.value = new Set()
  try {
    post.value = await getPost(id)
  } catch (err: unknown) {
    error.value = err instanceof Error ? err.message : 'Erro desconhecido'
  } finally {
    loading.value = false
  }
}

function onError(id: number | string) {
  failedIds.value = new Set(failedIds.value).add(id)
}

function prev() {
  if (!hasMultiple.value) return
  current.value =
    (current.value - 1 + images.value.length) % images.value.length
}

function next() {
  if (!hasMultiple.value) return
  current.value = (current.value + 1) % images.value.length
}

function goTo(index: number) {
  if (index < 0 || index >= images.value.length) return
  current.value = index
}

function goBack() {
  if (window.history.length > 1) router.back()
  else router.push({ name: 'gallery' })
}

watch(
  () => route.params.id,
  (id) => {
    if (id) void load(id as string)
  },
)

onMounted(() => {
  const id = route.params.id
  if (id) void load(id as string)
})
</script>

<template>
  <main class="post-view">
    <button type="button" class="post-view__back" @click="goBack">
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
        <div class="post-view__media">
          <template v-if="activeImage && !activeFailed">
            <img
              :key="activeImage.id"
              class="post-view__image"
              :src="activeImage.url!"
              :alt="post.description ?? post.title"
              @error="onError(activeImage.id)"
            />
          </template>
          <div v-else class="post-view__placeholder">
            <MaterialIcon name="broken_image" />
            <span>Imagem indisponível</span>
          </div>

          <template v-if="hasMultiple">
            <button
              type="button"
              class="post-view__nav post-view__nav--prev"
              aria-label="Imagem anterior"
              @click="prev"
            >
              <MaterialIcon name="chevron_left" />
            </button>
            <button
              type="button"
              class="post-view__nav post-view__nav--next"
              aria-label="Próxima imagem"
              @click="next"
            >
              <MaterialIcon name="chevron_right" />
            </button>
            <span class="post-view__counter">
              {{ current + 1 }} / {{ images.length }}
            </span>
          </template>
        </div>

        <div v-if="hasMultiple" class="post-view__thumbs" role="tablist">
          <button
            v-for="(img, i) in images"
            :key="img.id"
            type="button"
            class="post-view__thumb"
            :class="{ 'post-view__thumb--active': i === current }"
            :aria-label="`Ir para imagem ${i + 1}`"
            :aria-selected="i === current"
            role="tab"
            @click="goTo(i)"
          >
            <img
              :src="img.url!"
              :alt="`Miniatura ${i + 1}`"
              loading="lazy"
              @error="onError(img.id)"
            />
          </button>
        </div>
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
  </main>
</template>
