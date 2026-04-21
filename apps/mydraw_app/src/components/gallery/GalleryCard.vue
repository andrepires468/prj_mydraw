<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import MaterialIcon from '@/components/common/MaterialIcon.vue'

export interface GalleryCardImage {
  id: number | string
  url: string
  alt?: string
}

interface Props {
  title: string
  category?: string | null
  images: GalleryCardImage[]
}

const props = withDefaults(defineProps<Props>(), {
  category: null,
})

const current = ref(0)
const failedIds = ref<Set<number | string>>(new Set())

watch(
  () => props.images.length,
  (len) => {
    if (current.value >= len) current.value = 0
  },
)

const activeImage = computed<GalleryCardImage | null>(
  () => props.images[current.value] ?? null,
)

const activeFailed = computed(
  () => !!activeImage.value && failedIds.value.has(activeImage.value.id),
)

const hasMultiple = computed(() => props.images.length > 1)

function onError(id: number | string) {
  failedIds.value = new Set(failedIds.value).add(id)
}

function prev() {
  if (!hasMultiple.value) return
  current.value =
    (current.value - 1 + props.images.length) % props.images.length
}

function next() {
  if (!hasMultiple.value) return
  current.value = (current.value + 1) % props.images.length
}

function goTo(index: number) {
  if (index < 0 || index >= props.images.length) return
  current.value = index
}
</script>

<template>
  <article class="gallery-card masonry-grid__item group">
    <div class="gallery-card__media">
      <template v-if="activeImage && !activeFailed">
        <img
          :key="activeImage.id"
          class="gallery-card__image"
          :src="activeImage.url"
          :alt="activeImage.alt || title"
          loading="lazy"
          @error="onError(activeImage.id)"
        />
      </template>
      <div v-else class="gallery-card__placeholder">
        <MaterialIcon name="broken_image" />
        <span>Imagem indisponível</span>
      </div>

      <template v-if="hasMultiple">
        <!--
          Os botões ficam dentro de um <RouterLink> (renderizado como <a>)
          no GalleryView. `.stop` isola a propagação do click, mas o browser
          ainda dispara o default action do anchor (navegação) quando o
          click ocorre em qualquer descendente do <a>. Por isso precisamos
          também de `.prevent` para cancelar a navegação.
        -->
        <button
          type="button"
          class="gallery-card__nav gallery-card__nav--prev"
          aria-label="Imagem anterior"
          @click.stop.prevent="prev"
        >
          <MaterialIcon name="chevron_left" />
        </button>
        <button
          type="button"
          class="gallery-card__nav gallery-card__nav--next"
          aria-label="Próxima imagem"
          @click.stop.prevent="next"
        >
          <MaterialIcon name="chevron_right" />
        </button>

        <span class="gallery-card__counter">
          {{ current + 1 }} / {{ images.length }}
        </span>

        <div class="gallery-card__dots" role="tablist">
          <button
            v-for="(img, i) in images"
            :key="img.id"
            type="button"
            class="gallery-card__dot"
            :class="{ 'gallery-card__dot--active': i === current }"
            :aria-label="`Ir para imagem ${i + 1}`"
            :aria-selected="i === current"
            role="tab"
            @click.stop.prevent="goTo(i)"
          />
        </div>
      </template>

      <div class="gallery-card__overlay">
        <span v-if="category" class="gallery-card__category">
          {{ category }}
        </span>
        <h3 class="gallery-card__title">{{ title }}</h3>
      </div>
    </div>
  </article>
</template>
