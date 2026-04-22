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
  /** Se false, arrastar não reordena (drag desligado nos itens). */
  reorderEnabled?: boolean
  /** Desativa remover/pré-visualizar nos cards (ex.: modo de reordenação). */
  cardActionsDisabled?: boolean
  /** Se false, não importa arquivos soltos sobre os cards (mantém só o placeholder). */
  allowFileImportOnCards?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  showPlaceholder: true,
  requireAtLeastOneImage: false,
  reorderEnabled: false,
  cardActionsDisabled: false,
  allowFileImportOnCards: true,
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
    if (
      props.allowFileImportOnCards &&
      event.dataTransfer?.types?.includes('Files')
    ) {
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
      :draggable="props.reorderEnabled"
      @dragstart="props.reorderEnabled ? onDragStart(index) : undefined"
      @dragover="onDragOver"
      @drop="onDrop($event, index)"
      @dragend="onDragEnd"
    >
      <ImageQueueCard
        :image="image"
        :dragging="props.reorderEnabled && draggingIndex === index"
        :remove-disabled="
          props.requireAtLeastOneImage && images.length <= 1
        "
        :actions-disabled="props.cardActionsDisabled"
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
