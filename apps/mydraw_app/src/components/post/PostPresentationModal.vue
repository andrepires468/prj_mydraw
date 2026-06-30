<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import { registerBackHandler } from '@/utils/backHandlerStack'
import PostImageCarousel from './PostImageCarousel.vue'
import type { GalleryRow } from '@/api/posts'
import type { PostMediaImage } from './post-media'

const AUTOPLAY_INTERVALS = [3, 6, 12] as const
type AutoplaySeconds = (typeof AUTOPLAY_INTERVALS)[number]

interface Props {
  images: PostMediaImage[]
  altText: string
  galleryRows?: (GalleryRow | null)[] | null
}

const props = defineProps<Props>()

const emit = defineEmits<{
  close: []
}>()

const currentIndex = ref(0)
const isPlaying = ref(true)
const intervalSec = ref<AutoplaySeconds>(6)

const hasMultiple = computed(() => props.images.length > 1)
const playLabel = computed(() => (isPlaying.value ? 'Pause slideshow' : 'Play slideshow'))

let timer: number | null = null
let previousOverflow = ''
let unregisterBack: (() => void) | undefined

function clearTimer() {
  if (timer !== null) {
    window.clearTimeout(timer)
    timer = null
  }
}

function scheduleNext() {
  clearTimer()
  if (!isPlaying.value || !hasMultiple.value) return
  timer = window.setTimeout(() => {
    currentIndex.value = (currentIndex.value + 1) % props.images.length
  }, intervalSec.value * 1000)
}

function togglePlay() {
  isPlaying.value = !isPlaying.value
}

function selectInterval(sec: AutoplaySeconds) {
  intervalSec.value = sec
}

function close() {
  emit('close')
}

// Block ESC and capture arrow / space shortcuts before they reach parent listeners.
function handleKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    event.preventDefault()
    event.stopPropagation()
    event.stopImmediatePropagation()
    return
  }
  if (event.key === 'ArrowRight') {
    event.preventDefault()
    event.stopPropagation()
    event.stopImmediatePropagation()
    if (!hasMultiple.value) return
    currentIndex.value = (currentIndex.value + 1) % props.images.length
    return
  }
  if (event.key === 'ArrowLeft') {
    event.preventDefault()
    event.stopPropagation()
    event.stopImmediatePropagation()
    if (!hasMultiple.value) return
    currentIndex.value
      = (currentIndex.value - 1 + props.images.length) % props.images.length
    return
  }
  if (event.key === ' ' || event.key === 'Spacebar') {
    event.preventDefault()
    togglePlay()
  }
}

watch(
  [isPlaying, intervalSec, currentIndex, () => props.images.length],
  () => {
    scheduleNext()
  },
  { immediate: true },
)

onMounted(() => {
  previousOverflow = document.body.style.overflow
  document.body.style.overflow = 'hidden'
  document.addEventListener('keydown', handleKeydown, true)
  unregisterBack = registerBackHandler(() => close())
})

onBeforeUnmount(() => {
  unregisterBack?.()
  document.body.style.overflow = previousOverflow
  document.removeEventListener('keydown', handleKeydown, true)
  clearTimer()
})
</script>

<template>
  <Teleport to="body">
    <Transition name="post-presentation__fade" appear>
      <div class="post-presentation" role="dialog" aria-modal="true" aria-label="Slideshow">
        <header class="post-presentation__top-bar">
          <div
            v-if="hasMultiple"
            class="post-presentation__controls"
            role="toolbar"
            aria-label="Slideshow controls"
          >
            <button
              type="button"
              class="post-presentation__play-btn"
              :class="{ 'post-presentation__play-btn--paused': !isPlaying }"
              :aria-label="playLabel"
              :aria-pressed="isPlaying"
              @click="togglePlay"
            >
              <MaterialIcon :name="isPlaying ? 'pause' : 'play_arrow'" size="1.25rem" />
            </button>

            <div
              class="post-presentation__interval"
              role="radiogroup"
              aria-label="Slideshow interval"
            >
              <span class="post-presentation__interval-label">Interval</span>
              <button
                v-for="sec in AUTOPLAY_INTERVALS"
                :key="sec"
                type="button"
                role="radio"
                :aria-checked="intervalSec === sec"
                class="post-presentation__interval-btn"
                :class="{
                  'post-presentation__interval-btn--active': intervalSec === sec,
                }"
                @click="selectInterval(sec)"
              >
                {{ sec }}s
              </button>
            </div>
          </div>
        </header>

        <button
          type="button"
          class="post-presentation__close"
          aria-label="Close slideshow"
          @click="close"
        >
          <MaterialIcon name="close" size="1.25rem" />
        </button>

        <div class="post-presentation__stage">
          <PostImageCarousel
            v-model:current-index="currentIndex"
            :images="images"
            :gallery-rows="galleryRows"
            :alt-text="altText"
            variant="fullscreen"
            :open-on-click="false"
          />
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
