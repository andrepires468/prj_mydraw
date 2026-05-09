<script setup lang="ts">
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import type { PostMediaViewMode } from './post-media'

interface Props {
  modelValue: PostMediaViewMode
}

defineProps<Props>()

defineEmits<{
  'update:modelValue': [value: PostMediaViewMode]
}>()
</script>

<template>
  <div class="post-view__media-mode" role="tablist" aria-label="Image view mode">
    <button
      type="button"
      class="post-view__media-mode-btn"
      :class="{ 'post-view__media-mode-btn--active': modelValue === 'carousel' }"
      role="tab"
      :aria-selected="modelValue === 'carousel'"
      @click="$emit('update:modelValue', 'carousel')"
    >
      <MaterialIcon name="view_carousel" size="1rem" />
      <span>Carousel</span>
    </button>
    <button
      type="button"
      class="post-view__media-mode-btn"
      :class="{ 'post-view__media-mode-btn--active': modelValue === 'list' }"
      role="tab"
      :aria-selected="modelValue === 'list'"
      @click="$emit('update:modelValue', 'list')"
    >
      <MaterialIcon name="view_agenda" size="1rem" />
      <span>List</span>
    </button>
  </div>
</template>
