<script setup lang="ts">
import { ref, useId } from 'vue'
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

const inputRef = ref<HTMLInputElement | null>(null)
const inputId = useId()
const isDragging = ref(false)

/** Evita abrir o seletor logo após soltar arquivos (alguns browsers disparam click de fantasma). */
let ignoreOpenPickerUntil = 0

function openPicker() {
  if (props.disabled) return
  if (typeof performance !== 'undefined' && performance.now() < ignoreOpenPickerUntil) {
    return
  }
  inputRef.value?.click()
}

function handleChange(event: Event) {
  const target = event.target as HTMLInputElement
  emitFiles(target.files)
  target.value = ''
}

function handleDrop(event: DragEvent) {
  isDragging.value = false
  event.preventDefault()
  event.stopPropagation()
  if (props.disabled) return
  ignoreOpenPickerUntil =
    typeof performance !== 'undefined' ? performance.now() + 400 : 0
  emitFiles(event.dataTransfer?.files ?? null)
}

function onDragEnter(event: DragEvent) {
  event.preventDefault()
  event.stopPropagation()
  if (!props.disabled) isDragging.value = true
}

function onDragOver(event: DragEvent) {
  event.preventDefault()
  event.stopPropagation()
  if (!props.disabled) isDragging.value = true
}

function onDragLeave(event: DragEvent) {
  event.stopPropagation()
  const current = event.currentTarget as HTMLElement | null
  const related = event.relatedTarget as Node | null
  if (current && related && current.contains(related)) {
    return
  }
  isDragging.value = false
}

function emitFiles(fileList: FileList | null) {
  if (!fileList || fileList.length === 0) return
  if (props.maxFiles <= 0) return
  const files = Array.from(fileList).slice(0, props.maxFiles)
  if (files.length === 0) return
  emit('files', files)
}
</script>

<template>
  <div
    class="upload-dropzone"
    :class="{
      'upload-dropzone--dragover': isDragging,
      'upload-dropzone--disabled': props.disabled,
    }"
    @click="openPicker"
    @dragenter="onDragEnter"
    @dragover="onDragOver"
    @dragleave="onDragLeave"
    @drop="handleDrop"
  >
    <MaterialIcon name="cloud_upload" class="upload-dropzone__icon" />

    <h3 class="upload-dropzone__title">Drop your imagery here</h3>
    <p class="upload-dropzone__hint">
      Supports high-res JPG, PNG, and WebP. Max {{ props.maxFiles }} files per post.
    </p>

    <button
      type="button"
      class="upload-dropzone__button"
      :disabled="props.disabled"
      @click.stop="openPicker"
    >
      Browse Files
    </button>

    <input
      :id="inputId"
      ref="inputRef"
      type="file"
      class="sr-only"
      :accept="accept"
      :multiple="multiple"
      :disabled="props.disabled"
      :aria-hidden="true"
      tabindex="-1"
      @change="handleChange"
    />
  </div>
</template>
