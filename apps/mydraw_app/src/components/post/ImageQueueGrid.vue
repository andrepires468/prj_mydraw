<script setup lang="ts">
import { ref } from 'vue'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import ImageQueueCard from './ImageQueueCard.vue'
import type { QueuedImage } from './types'

interface Props {
  images: QueuedImage[]
  showPlaceholder?: boolean
  /** Se true, impede remover quando só restar uma imagem na fila. */
  requireAtLeastOneImage?: boolean
}

withDefaults(defineProps<Props>(), {
  showPlaceholder: true,
  requireAtLeastOneImage: false,
})

const emit = defineEmits<{
  remove: [id: string]
  preview: [id: string]
  reorder: [from: number, to: number]
  /** Arquivos soltos de fora da página (ex.: Explorer) sobre a fila */
  importFiles: [files: File[]]
}>()

const draggingIndex = ref<number | null>(null)

function onDragStart(index: number) {
  draggingIndex.value = index
}

function onDragOver(event: DragEvent) {
  event.preventDefault()
}

function onDrop(event: DragEvent, targetIndex: number) {
  const from = draggingIndex.value
  draggingIndex.value = null
  if (from === null) {
    if (event.dataTransfer?.types?.includes('Files')) {
      event.preventDefault()
      const list = event.dataTransfer?.files
      if (list && list.length > 0) {
        emit('importFiles', Array.from(list))
      }
    }
    return
  }
  if (from === targetIndex) {
    event.preventDefault()
    return
  }
  event.preventDefault()
  emit('reorder', from, targetIndex)
}

function onDragEnd() {
  draggingIndex.value = null
}

function onPlaceholderDrop(event: DragEvent) {
  event.preventDefault()
  const list = event.dataTransfer?.files
  if (list && list.length > 0) {
    emit('importFiles', Array.from(list))
  }
}
</script>

<template>
  <div class="image-queue">
    <div
      v-for="(image, index) in images"
      :key="image.id"
      draggable="true"
      @dragstart="onDragStart(index)"
      @dragover="onDragOver"
      @drop="onDrop($event, index)"
      @dragend="onDragEnd"
    >
      <ImageQueueCard
        :image="image"
        :dragging="draggingIndex === index"
        :remove-disabled="
          requireAtLeastOneImage && images.length <= 1
        "
        @remove="emit('remove', $event)"
        @preview="emit('preview', $event)"
      />
    </div>

    <div
      v-if="showPlaceholder"
      class="image-queue-placeholder"
      aria-hidden="true"
      @dragover="onDragOver"
      @drop="onPlaceholderDrop"
    >
      <MaterialIcon name="add" />
    </div>
  </div>
</template>
