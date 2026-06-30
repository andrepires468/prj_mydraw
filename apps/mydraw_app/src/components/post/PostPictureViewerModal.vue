<script setup lang="ts">
import {
  computed,
  onBeforeUnmount,
  onMounted,
  ref,
  watch,
} from 'vue'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import type { GalleryRow } from '@/api/posts'
import { registerBackHandler } from '@/utils/backHandlerStack'
import type { PostMediaImage } from './post-media'

const ZOOM_MIN = 1
const ZOOM_MAX = 4
const SWIPE_THRESHOLD = 48
const CLICK_MAX_MOVE = 10
const CLICK_MAX_MS = 460
const DOUBLE_TAP_MS = 120

interface Props {
  images: PostMediaImage[]
  altText: string
  initialIndex?: number
  currentIndex?: number
  galleryRows?: (GalleryRow | null)[] | null
}

const props = withDefaults(defineProps<Props>(), {
  initialIndex: 0,
  currentIndex: undefined,
  galleryRows: undefined,
})

const emit = defineEmits<{
  close: []
  'update:currentIndex': [value: number]
}>()

const internalIndex = ref(props.initialIndex)
const scale = ref(1)
const panX = ref(0)
const panY = ref(0)
const dragX = ref(0)
const isAnimating = ref(false)
const failedIds = ref<Set<number | string>>(new Set())

const viewportRef = ref<HTMLElement | null>(null)
const imageRef = ref<HTMLImageElement | null>(null)

const current = computed<number>({
  get: () =>
    props.currentIndex !== undefined ? props.currentIndex : internalIndex.value,
  set: (value: number) => {
    internalIndex.value = value
    emit('update:currentIndex', value)
  },
})

const activeImage = computed(() => props.images[current.value] ?? null)
const hasMultiple = computed(() => props.images.length > 1)
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

const isZoomed = computed(() => scale.value > 1.02)

const imageTransform = computed(() => {
  const parts = [
    `translate3d(${panX.value + dragX.value}px, ${panY.value}px, 0)`,
    `scale(${scale.value})`,
  ]
  return parts.join(' ')
})

const imageClass = computed(() => ({
  'post-picture-viewer__image--zoomable': !isZoomed.value,
  'post-picture-viewer__image--zoomed': isZoomed.value,
}))

let previousOverflow = ''
let unregisterBack: (() => void) | undefined

type PointerSnapshot = {
  id: number
  x: number
  y: number
}

const activePointers = new Map<number, PointerSnapshot>()
let gestureMode: 'none' | 'pan' | 'swipe' | 'pinch' = 'none'
let pointerStartX = 0
let pointerStartY = 0
let pointerStartTime = 0
let panStartX = 0
let panStartY = 0
let pinchStartDistance = 0
let pinchStartScale = 1
let lastTapTime = 0

function resetTransform(animate = false) {
  isAnimating.value = animate
  scale.value = ZOOM_MIN
  panX.value = 0
  panY.value = 0
  dragX.value = 0
  if (animate) {
    window.setTimeout(() => {
      isAnimating.value = false
    }, 220)
  }
}

function clampPan() {
  const viewport = viewportRef.value
  if (!viewport || scale.value <= 1) {
    panX.value = 0
    panY.value = 0
    return
  }
  const maxX = ((scale.value - 1) * viewport.clientWidth) / 2
  const maxY = ((scale.value - 1) * viewport.clientHeight) / 2
  panX.value = Math.max(-maxX, Math.min(maxX, panX.value))
  panY.value = Math.max(-maxY, Math.min(maxY, panY.value))
}

function distance(a: PointerSnapshot, b: PointerSnapshot): number {
  const dx = a.x - b.x
  const dy = a.y - b.y
  return Math.hypot(dx, dy)
}

function goTo(index: number) {
  if (index < 0 || index >= props.images.length) return
  current.value = index
  resetTransform(true)
}

function prev() {
  if (!hasMultiple.value) return
  goTo((current.value - 1 + props.images.length) % props.images.length)
}

function next() {
  if (!hasMultiple.value) return
  goTo((current.value + 1) % props.images.length)
}

function isAtDefaultView(): boolean {
  return (
    scale.value <= 1.02
    && Math.abs(panX.value) < 1
    && Math.abs(panY.value) < 1
  )
}

function computeHeightFitScale(): number {
  const viewport = viewportRef.value
  const img = imageRef.value
  if (!viewport || !img) return ZOOM_MIN

  const displayedHeight = img.getBoundingClientRect().height
  if (displayedHeight <= 0) return ZOOM_MIN

  const targetScale = viewport.clientHeight / displayedHeight
  return Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, targetScale))
}

function zoomToMaxHeight(animate = false) {
  const targetScale = computeHeightFitScale()
  if (targetScale <= 1.02) return

  isAnimating.value = animate
  scale.value = targetScale
  panX.value = 0
  panY.value = 0
  clampPan()

  if (animate) {
    window.setTimeout(() => {
      isAnimating.value = false
    }, 220)
  }
}

function toggleDoubleClickZoom() {
  if (!isAtDefaultView()) {
    resetTransform(true)
    return
  }
  zoomToMaxHeight(true)
}

function registerTap() {
  const now = Date.now()
  if (now - lastTapTime <= DOUBLE_TAP_MS) {
    lastTapTime = 0
    toggleDoubleClickZoom()
    return
  }
  lastTapTime = now
}

function onImageDoubleClick(event: MouseEvent) {
  event.preventDefault()
  lastTapTime = 0
  toggleDoubleClickZoom()
}

function finishSwipe() {
  const offset = dragX.value
  dragX.value = 0
  isAnimating.value = true

  if (Math.abs(offset) >= SWIPE_THRESHOLD) {
    if (offset < 0) next()
    else prev()
  }

  window.setTimeout(() => {
    isAnimating.value = false
  }, 220)
}

function onPointerDown(event: PointerEvent) {
  if (event.pointerType === 'mouse' && event.button !== 0) return

  viewportRef.value?.setPointerCapture(event.pointerId)
  activePointers.set(event.pointerId, {
    id: event.pointerId,
    x: event.clientX,
    y: event.clientY,
  })

  if (activePointers.size === 1) {
    pointerStartX = event.clientX
    pointerStartY = event.clientY
    pointerStartTime = Date.now()
    panStartX = panX.value
    panStartY = panY.value
    gestureMode = isZoomed.value ? 'pan' : 'swipe'
    isAnimating.value = false
    return
  }

  if (activePointers.size === 2) {
    const pts = [...activePointers.values()]
    pinchStartDistance = distance(pts[0], pts[1])
    pinchStartScale = scale.value
    gestureMode = 'pinch'
    dragX.value = 0
  }
}

function onPointerMove(event: PointerEvent) {
  const existing = activePointers.get(event.pointerId)
  if (!existing) return

  activePointers.set(event.pointerId, {
    ...existing,
    x: event.clientX,
    y: event.clientY,
  })

  if (gestureMode === 'pinch' && activePointers.size >= 2) {
    const pts = [...activePointers.values()].slice(0, 2)
    const dist = distance(pts[0], pts[1])
    if (pinchStartDistance <= 0) return
    const ratio = dist / pinchStartDistance
    scale.value = Math.max(
      ZOOM_MIN,
      Math.min(ZOOM_MAX, pinchStartScale * ratio),
    )
    if (scale.value <= 1.02) {
      panX.value = 0
      panY.value = 0
    } else {
      clampPan()
    }
    return
  }

  if (activePointers.size !== 1) return

  const dx = event.clientX - pointerStartX
  const dy = event.clientY - pointerStartY

  if (gestureMode === 'pan' || isZoomed.value) {
    panX.value = panStartX + dx
    panY.value = panStartY + dy
    clampPan()
    return
  }

  if (gestureMode === 'swipe') {
    let nextDrag = dx
    if (!hasMultiple.value) {
      nextDrag = dx * 0.25
    } else if (current.value === 0 && dx > 0) {
      nextDrag = dx * 0.35
    } else if (current.value === props.images.length - 1 && dx < 0) {
      nextDrag = dx * 0.35
    }
    dragX.value = nextDrag
  }
}

function onPointerUp(event: PointerEvent) {
  activePointers.delete(event.pointerId)
  viewportRef.value?.releasePointerCapture(event.pointerId)

  if (activePointers.size === 1 && gestureMode === 'pinch') {
    const remaining = [...activePointers.values()][0]
    pointerStartX = remaining.x
    pointerStartY = remaining.y
    panStartX = panX.value
    panStartY = panY.value
    gestureMode = isZoomed.value ? 'pan' : 'swipe'
    return
  }

  if (activePointers.size > 0) return

  const elapsed = Date.now() - pointerStartTime
  const dx = event.clientX - pointerStartX
  const dy = event.clientY - pointerStartY
  const moved = Math.hypot(dx, dy)

  if (gestureMode === 'swipe' && !isZoomed.value) {
    if (Math.abs(dx) >= SWIPE_THRESHOLD) {
      finishSwipe()
    } else {
      dragX.value = 0
      if (
        moved <= CLICK_MAX_MOVE
        && elapsed <= CLICK_MAX_MS
        && event.pointerType !== 'mouse'
      ) {
        registerTap()
      }
    }
  } else if (
    gestureMode !== 'pinch'
    && moved <= CLICK_MAX_MOVE
    && elapsed <= CLICK_MAX_MS
    && event.pointerType !== 'mouse'
  ) {
    registerTap()
  }

  gestureMode = 'none'
}

function onPointerCancel(event: PointerEvent) {
  activePointers.delete(event.pointerId)
  if (activePointers.size === 0) {
    gestureMode = 'none'
    if (!isZoomed.value) dragX.value = 0
  }
}

function onError(id: number | string) {
  failedIds.value = new Set(failedIds.value).add(id)
}

function close() {
  emit('close')
}

function handleKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    event.preventDefault()
    event.stopPropagation()
    event.stopImmediatePropagation()
    close()
    return
  }
  if (event.key === 'ArrowRight') {
    event.preventDefault()
    event.stopPropagation()
    event.stopImmediatePropagation()
    next()
    return
  }
  if (event.key === 'ArrowLeft') {
    event.preventDefault()
    event.stopPropagation()
    event.stopImmediatePropagation()
    prev()
  }
}

watch(
  () => props.images,
  () => {
    failedIds.value = new Set()
    resetTransform()
  },
)

watch(current, () => {
  resetTransform()
  lastTapTime = 0
})

onMounted(() => {
  previousOverflow = document.body.style.overflow
  document.body.style.overflow = 'hidden'
  document.addEventListener('keydown', handleKeydown, true)
  unregisterBack = registerBackHandler(() => close())
  internalIndex.value = props.initialIndex
})

onBeforeUnmount(() => {
  unregisterBack?.()
  document.body.style.overflow = previousOverflow
  document.removeEventListener('keydown', handleKeydown, true)
})
</script>

<template>
  <Teleport to="body">
    <Transition name="post-picture-viewer__fade" appear>
      <div
        class="post-picture-viewer"
        role="dialog"
        aria-modal="true"
        aria-label="Image viewer"
      >
        <button
          type="button"
          class="post-picture-viewer__close"
          aria-label="Close image viewer"
          @click="close"
        >
          <MaterialIcon name="close" size="1.25rem" />
        </button>

        <div class="post-picture-viewer__stage">
          <div
            ref="viewportRef"
            class="post-picture-viewer__viewport"
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
            @pointercancel="onPointerCancel"
          >
            <img
              v-if="activeImage && !activeFailed"
              ref="imageRef"
              :key="activeImage.id"
              class="post-picture-viewer__image"
              :class="imageClass"
              :src="activeImage.url"
              :alt="`${altText} (${current + 1} of ${images.length})`"
              :style="{
                transform: imageTransform,
                transition: isAnimating
                  ? 'transform 220ms ease'
                  : undefined,
              }"
              draggable="false"
              @error="onError(activeImage.id)"
              @dblclick="onImageDoubleClick"
            />
            <div v-else class="post-picture-viewer__placeholder">
              <MaterialIcon name="broken_image" />
              <span>Image unavailable</span>
            </div>

            <div
              v-if="activeRating || hasMultiple"
              class="post-picture-viewer__overlay-tr"
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
                aria-live="polite"
              >
                {{ current + 1 }} / {{ images.length }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
