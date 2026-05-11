<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import type { GalleryRow } from '@/api/posts'
import type { PostMediaImage } from './post-media'

interface Props {
  images: PostMediaImage[]
  altText: string
  /** Same length/order as `images`; used for per-image `ai_rating` overlay. */
  galleryRows?: (GalleryRow | null)[] | null
  /** Optional external control of the active index (use with v-model:currentIndex). */
  currentIndex?: number
  /** Visual variant; 'fullscreen' removes the fixed aspect-ratio for presentation contexts. */
  variant?: 'default' | 'fullscreen'
}

const props = withDefaults(defineProps<Props>(), {
  galleryRows: undefined,
  currentIndex: undefined,
  variant: 'default',
})

const emit = defineEmits<{
  'update:currentIndex': [value: number]
}>()

const internalIndex = ref(0)
const failedIds = ref<Set<number | string>>(new Set())

const current = computed<number>({
  get: () =>
    props.currentIndex !== undefined ? props.currentIndex : internalIndex.value,
  set: (value: number) => {
    internalIndex.value = value
    emit('update:currentIndex', value)
  },
})

const activeImage = computed(() => props.images[current.value] ?? null)
const activeFailed = computed(
  () => !!activeImage.value && failedIds.value.has(activeImage.value.id),
)

const activeRating = computed<string | null>(() => {
  const rows = props.galleryRows
  if (!rows?.length) return null
  const row = rows[current.value]
  const r = row?.ai_rating
  return typeof r === 'string' && r.trim() !== '' ? r.trim() : null
})
const hasMultiple = computed(() => props.images.length > 1)
const isFullscreen = computed(() => props.variant === 'fullscreen')

watch(
  () => props.images,
  () => {
    internalIndex.value = 0
    if (props.currentIndex !== undefined) {
      emit('update:currentIndex', 0)
    }
    failedIds.value = new Set()
  },
)

function onError(id: number | string) {
  failedIds.value = new Set(failedIds.value).add(id)
}

function prev() {
  if (!hasMultiple.value) return
  current.value = (current.value - 1 + props.images.length) % props.images.length
}

function next() {
  if (!hasMultiple.value) return
  current.value = (current.value + 1) % props.images.length
}

function goTo(index: number) {
  if (index < 0 || index >= props.images.length) return
  current.value = index
}

function keyboardTargetsInput(event: KeyboardEvent): boolean {
  const t = event.target
  if (!(t instanceof HTMLElement)) return false
  const tag = t.tagName
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true
  if (t.isContentEditable) return true
  return false
}

function onWindowKeydown(event: KeyboardEvent) {
  if (isFullscreen.value) return
  if (!hasMultiple.value) return
  if (keyboardTargetsInput(event)) return
  if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return

  if (event.key === 'ArrowLeft') {
    event.preventDefault()
    prev()
    return
  }
  if (event.key === 'ArrowRight') {
    event.preventDefault()
    next()
  }
}

onMounted(() => {
  window.addEventListener('keydown', onWindowKeydown)
})

onUnmounted(() => {
  window.removeEventListener('keydown', onWindowKeydown)
})
</script>

<template>
  <div
    class="post-view__media"
    :class="{ 'post-view__media--fullscreen': isFullscreen }"
  >
    <Transition name="post-view__image-fade" mode="out-in">
      <img
        v-if="activeImage && !activeFailed"
        :key="activeImage.id"
        class="post-view__image"
        :src="activeImage.url"
        :alt="altText"
        @error="onError(activeImage.id)"
      />
      <div v-else class="post-view__placeholder">
        <MaterialIcon name="broken_image" />
        <span>Image unavailable</span>
      </div>
    </Transition>

    <template v-if="hasMultiple">
      <button
        type="button"
        class="post-view__nav post-view__nav--prev"
        aria-label="Previous image"
        @click="prev"
      >
        <MaterialIcon name="chevron_left" />
      </button>
      <button
        type="button"
        class="post-view__nav post-view__nav--next"
        aria-label="Next image"
        @click="next"
      >
        <MaterialIcon name="chevron_right" />
      </button>
    </template>

    <div
      v-if="activeRating || hasMultiple"
      class="post-view__media-overlay-tr"
    >
      <span
        v-if="activeRating"
        class="post-view__image-rating"
        :aria-label="`Content rating ${activeRating}`"
      >
        {{ activeRating }}
      </span>
      <span
        v-if="hasMultiple"
        class="post-view__counter"
      >
        {{ current + 1 }} / {{ images.length }}
      </span>
    </div>
  </div>

  <div v-if="hasMultiple" class="post-view__thumbs" role="tablist">
    <button
      v-for="(img, i) in images"
      :key="img.id"
      type="button"
      class="post-view__thumb"
      :class="{ 'post-view__thumb--active': i === current }"
      :aria-label="`Go to image ${i + 1}`"
      :aria-selected="i === current"
      role="tab"
      @click="goTo(i)"
    >
      <img
        :src="img.thumbUrl"
        :alt="`Thumbnail ${i + 1}`"
        loading="lazy"
        @error="onError(img.id)"
      />
    </button>
  </div>
</template>
