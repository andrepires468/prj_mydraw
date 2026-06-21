<script setup lang="ts">
interface Props {
  reorderMode: boolean
  asideSummarized: boolean
  applyingReorder: boolean
  canApplyReorder: boolean
  aiCategorizing: boolean
  actionsLocked: boolean
  aiDisabled: boolean
}

defineProps<Props>()

defineEmits<{
  enterReorder: []
  cancelReorder: []
  applyReorder: []
  categorize: []
}>()
</script>

<template>
  <p v-if="!asideSummarized" class="post-metadata__reorder-title">
    Image order
  </p>
  <p v-if="!asideSummarized" class="post-metadata__reorder-hint">
    {{
      reorderMode
        ? 'Drag thumbnails in the queue on the left, then confirm.'
        : 'Change the display order in the gallery.'
    }}
  </p>

  <div class="post-metadata__reorder-actions">
    <template v-if="!reorderMode">
      <button
        type="button"
        class="post-metadata__secondary"
        :disabled="actionsLocked"
        @click="$emit('enterReorder')"
      >
        Reorder images
      </button>
      <button
        type="button"
        class="post-metadata__secondary"
        :disabled="actionsLocked || aiDisabled"
        @click="$emit('categorize')"
      >
        {{ aiCategorizing ? 'Categorizing…' : 'Categorize with AI' }}
      </button>
    </template>
    <template v-else>
      <button
        type="button"
        class="post-metadata__secondary"
        :disabled="applyingReorder"
        @click="$emit('cancelReorder')"
      >
        Cancel
      </button>
      <button
        type="button"
        class="post-metadata__reorder-apply"
        :disabled="!canApplyReorder || applyingReorder"
        @click="$emit('applyReorder')"
      >
        {{ applyingReorder ? 'Applying…' : 'Apply order' }}
      </button>
    </template>
  </div>
</template>
