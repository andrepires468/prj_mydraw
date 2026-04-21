<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import type { GallerySort } from './types'

interface Props {
  sort: GallerySort
}

const props = defineProps<Props>()

const emit = defineEmits<{
  'update:sort': [value: GallerySort]
}>()

const SORT_OPTIONS: { value: GallerySort; label: string }[] = [
  { value: 'newest', label: 'Newest' },
  { value: 'oldest', label: 'Oldest' },
]

const open = ref(false)
const rootRef = ref<HTMLElement | null>(null)

const currentLabel = computed(
  () => SORT_OPTIONS.find((o) => o.value === props.sort)?.label ?? 'Newest',
)

function toggleOpen() {
  open.value = !open.value
}

function close() {
  open.value = false
}

function select(value: GallerySort) {
  emit('update:sort', value)
  close()
}

function onDocumentClick(event: MouseEvent) {
  if (!open.value) return
  const target = event.target as Node | null
  if (rootRef.value && target && !rootRef.value.contains(target)) {
    close()
  }
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape' && open.value) {
    close()
  }
}

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <div class="gallery-filters">
    <div ref="rootRef" class="gallery-filters__sort">
      <span id="gallery-sort-label" class="gallery-filters__sort-label">Sort:</span>
      <div class="gallery-filters__sort-dropdown">
        <button
          id="gallery-sort-trigger"
          type="button"
          class="gallery-filters__sort-trigger"
          :aria-expanded="open"
          aria-haspopup="true"
          aria-controls="gallery-sort-panel"
          @click="toggleOpen"
        >
          <span class="gallery-filters__sort-current">{{ currentLabel }}</span>
          <MaterialIcon
            name="expand_more"
            size="1.125rem"
            class="gallery-filters__sort-chevron"
            :class="{ 'gallery-filters__sort-chevron--open': open }"
          />
        </button>

        <transition name="gallery-sort-panel-anim">
          <div
            v-if="open"
            id="gallery-sort-panel"
            class="gallery-filters__sort-panel"
            role="radiogroup"
            aria-labelledby="gallery-sort-label"
          >
            <label
              v-for="opt in SORT_OPTIONS"
              :key="opt.value"
              class="gallery-filters__sort-option"
              :class="{ 'gallery-filters__sort-option--active': sort === opt.value }"
            >
              <input
                class="gallery-filters__sort-radio"
                type="radio"
                name="gallery-sort"
                :value="opt.value"
                :checked="sort === opt.value"
                @change="select(opt.value)"
              />
              <span class="gallery-filters__sort-option-label">{{ opt.label }}</span>
            </label>
          </div>
        </transition>
      </div>
    </div>
  </div>
</template>
