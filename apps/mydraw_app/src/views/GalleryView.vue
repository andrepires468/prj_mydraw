<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { listPosts, type Paginated, type PostRow } from '@/api/posts'
import GalleryHero from '@/components/gallery/GalleryHero.vue'
import GalleryFilters from '@/components/gallery/GalleryFilters.vue'
import MasonryGrid from '@/components/gallery/MasonryGrid.vue'
import GalleryCard, {
  type GalleryCardImage,
} from '@/components/gallery/GalleryCard.vue'
import LoadingIndicator from '@/components/gallery/LoadingIndicator.vue'
import type { GallerySort } from '@/components/gallery/types'

interface PostCard {
  id: number
  title: string
  category: string
  images: GalleryCardImage[]
}

const loading = ref(false)
const error = ref<string | null>(null)
const page = ref<Paginated<PostRow> | null>(null)
const sort = ref<GallerySort>('newest')

/** Cada post vira um card; imagens do post alimentam o carrossel interno. */
const cards = computed<PostCard[]>(() => {
  if (!page.value) return []

  const result: PostCard[] = []
  for (const post of page.value.data) {
    const images: GalleryCardImage[] = (post.images ?? [])
      .filter((img) => !!img.url)
      .map((img) => ({
        id: img.id,
        url: img.url as string,
        alt: post.description ?? post.title,
      }))

    if (images.length === 0) continue

    result.push({
      id: post.id,
      title: post.title,
      category: post.user?.name ?? 'Post',
      images,
    })
  }
  return result
})

const isEmptyState = computed(
  () => !loading.value && !error.value && cards.value.length === 0,
)

async function load(p = 1) {
  loading.value = true
  error.value = null
  try {
    page.value = await listPosts(p)
  } catch (err: unknown) {
    error.value = err instanceof Error ? err.message : 'Erro desconhecido'
  } finally {
    loading.value = false
  }
}

onMounted(() => load())
</script>

<template>
  <main class="max-w-screen-2xl mx-auto px-6 pt-12 pb-32 md:pb-24 w-full">
    <div class="gallery-hero">
      <GalleryHero />
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
      Ainda não há posts publicados. Seja o primeiro a criar um!
    </p>

    <MasonryGrid v-if="cards.length > 0">
      <RouterLink
        v-for="card in cards"
        :key="card.id"
        :to="{ name: 'post', params: { id: card.id } }"
        class="gallery-card-link"
        :aria-label="`Abrir post ${card.title}`"
      >
        <GalleryCard
          :title="card.title"
          :category="card.category"
          :images="card.images"
        />
      </RouterLink>
    </MasonryGrid>

    <LoadingIndicator v-if="loading" label="Carregando posts..." />
  </main>
</template>
