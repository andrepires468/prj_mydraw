<script setup lang="ts">
import type { GallerySort } from './types'

interface Props {
  sort: GallerySort
}

defineProps<Props>()

defineEmits<{
  'update:sort': [value: GallerySort]
}>()
</script>

<template>
  <div class="gallery-filters">
    <label class="gallery-filters__sort">
      <span class="gallery-filters__sort-label">Sort:</span>
      <select
        class="gallery-filters__sort-select"
        :value="sort"
        @change="$emit('update:sort', ($event.target as HTMLSelectElement).value as GallerySort)"
      >
        <option value="newest">Newest</option>
        <option value="popular">Popular</option>
        <option value="oldest">Oldest</option>
      </select>
    </label>
  </div>
</template>
