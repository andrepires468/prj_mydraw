<script setup lang="ts">
import { computed } from 'vue'
import type { PostStatus, PostsCounts } from '@/api/posts'

interface Props {
  modelValue: PostStatus
  counts: PostsCounts
  /**
   * When `false`, the Draft tab is hidden (e.g. anonymous visitors with no drafts).
   */
  showDraft?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  showDraft: true,
})

defineEmits<{
  'update:modelValue': [value: PostStatus]
}>()

const publishedCount = computed(() => formatCount(props.counts.published))
const draftCount = computed(() => formatCount(props.counts.draft))

function formatCount(value: number): string {
  if (value >= 1000) {
    return `${(value / 1000).toFixed(1).replace(/\.0$/, '')}k`
  }
  return String(value)
}
</script>

<template>
  <div
    class="gallery-filters__tabs"
    role="tablist"
    aria-label="Filter posts by status"
  >
    <button
      type="button"
      role="tab"
      class="gallery-filters__tab"
      :class="{ 'gallery-filters__tab--active': modelValue === 'published' }"
      :aria-selected="modelValue === 'published'"
      @click="$emit('update:modelValue', 'published')"
    >
      <span>Published</span>
      <span class="gallery-status-toggle__count">{{ publishedCount }}</span>
    </button>

    <button
      v-if="showDraft"
      type="button"
      role="tab"
      class="gallery-filters__tab"
      :class="{ 'gallery-filters__tab--active': modelValue === 'draft' }"
      :aria-selected="modelValue === 'draft'"
      @click="$emit('update:modelValue', 'draft')"
    >
      <span>Drafts</span>
      <span class="gallery-status-toggle__count">{{ draftCount }}</span>
    </button>
  </div>
</template>
