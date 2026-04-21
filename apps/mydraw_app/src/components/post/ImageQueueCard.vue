<script setup lang="ts">
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import type { QueuedImage } from './types'

interface Props {
  image: QueuedImage
  dragging?: boolean
}

withDefaults(defineProps<Props>(), { dragging: false })

defineEmits<{
  remove: [id: string]
  preview: [id: string]
}>()
</script>

<template>
  <div
    class="image-queue-card"
    :class="{ 'image-queue-card--dragging': dragging }"
  >
    <img
      class="image-queue-card__image"
      :src="image.previewUrl"
      :alt="image.file.name"
    />

    <span
      v-if="image.status === 'uploading'"
      class="image-queue-card__badge"
    >
      {{ image.progress ?? 0 }}%
    </span>
    <span
      v-else-if="image.status === 'done'"
      class="image-queue-card__badge"
    >
      ✓
    </span>
    <span
      v-else-if="image.status === 'error'"
      class="image-queue-card__badge"
      style="background: rgba(168, 54, 75, 0.9)"
    >
      !
    </span>

    <div
      v-if="image.status === 'uploading'"
      class="image-queue-card__progress"
    >
      <div
        class="image-queue-card__progress-bar"
        :style="{ width: `${image.progress ?? 0}%` }"
      />
    </div>

    <div class="image-queue-card__overlay">
      <button
        v-if="image.status !== 'uploading'"
        type="button"
        class="image-queue-card__action image-queue-card__action--danger"
        aria-label="Remover imagem"
        @click="$emit('remove', image.id)"
      >
        <MaterialIcon name="delete" />
      </button>
      <button
        type="button"
        class="image-queue-card__action image-queue-card__action--primary"
        aria-label="Ver em tela cheia"
        @click="$emit('preview', image.id)"
      >
        <MaterialIcon name="open_in_full" />
      </button>
    </div>
  </div>
</template>
