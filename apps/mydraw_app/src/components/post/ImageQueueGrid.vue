<script setup lang="ts">
import { ref } from 'vue'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import ImageQueueCard from './ImageQueueCard.vue'
import type { QueuedImage } from './types'

interface Props {
  images: QueuedImage[]
  showPlaceholder?: boolean
}

withDefaults(defineProps<Props>(), { showPlaceholder: true })

const emit = defineEmits<{
  remove: [id: string]
  preview: [id: string]
  reorder: [from: number, to: number]
}>()

const draggingIndex = ref<number | null>(null)

function onDragStart(index: number) {
  draggingIndex.value = index
}

function onDragOver(event: DragEvent) {
  event.preventDefault()
}

function onDrop(targetIndex: number) {
  const from = draggingIndex.value
  draggingIndex.value = null
  if (from === null || from === targetIndex) return
  emit('reorder', from, targetIndex)
}

function onDragEnd() {
  draggingIndex.value = null
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
      @drop="onDrop(index)"
      @dragend="onDragEnd"
    >
      <ImageQueueCard
        :image="image"
        :dragging="draggingIndex === index"
        @remove="emit('remove', $event)"
        @preview="emit('preview', $event)"
      />
    </div>

    <div
      v-if="showPlaceholder"
      class="image-queue-placeholder"
      aria-hidden="true"
    >
      <MaterialIcon name="add" />
    </div>
  </div>
</template>
