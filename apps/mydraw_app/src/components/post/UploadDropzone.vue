<script setup lang="ts">
import { ref } from 'vue'
import MaterialIcon from '@/components/common/MaterialIcon.vue'

interface Props {
  accept?: string
  multiple?: boolean
  disabled?: boolean
  maxFiles?: number
}

const props = withDefaults(defineProps<Props>(), {
  accept: 'image/jpeg,image/png,image/webp,image/gif',
  multiple: true,
  disabled: false,
  maxFiles: 10,
})

const emit = defineEmits<{
  files: [files: File[]]
}>()

const input = ref<HTMLInputElement | null>(null)
const isDragging = ref(false)

function openPicker() {
  if (props.disabled) return
  input.value?.click()
}

function handleChange(event: Event) {
  const target = event.target as HTMLInputElement
  emitFiles(target.files)
  target.value = ''
}

function handleDrop(event: DragEvent) {
  isDragging.value = false
  if (props.disabled) return
  emitFiles(event.dataTransfer?.files ?? null)
}

function emitFiles(fileList: FileList | null) {
  if (!fileList || fileList.length === 0) return
  const files = Array.from(fileList).slice(0, props.maxFiles)
  emit('files', files)
}
</script>

<template>
  <div
    class="upload-dropzone"
    :class="{ 'upload-dropzone--dragover': isDragging }"
    @dragenter.prevent="isDragging = true"
    @dragover.prevent="isDragging = true"
    @dragleave.prevent="isDragging = false"
    @drop.prevent="handleDrop"
  >
    <div class="upload-dropzone__icon-wrap">
      <MaterialIcon name="cloud_upload" />
    </div>

    <h3 class="upload-dropzone__title">Drop your imagery here</h3>
    <p class="upload-dropzone__hint">
      Supports high-res JPG, PNG, and WebP. Max {{ maxFiles }} files per post.
    </p>

    <button
      type="button"
      class="upload-dropzone__button"
      :disabled="disabled"
      @click="openPicker"
    >
      Browse Files
    </button>

    <input
      ref="input"
      type="file"
      class="upload-dropzone__input"
      :accept="accept"
      :multiple="multiple"
      :disabled="disabled"
      @change="handleChange"
    />
  </div>
</template>
