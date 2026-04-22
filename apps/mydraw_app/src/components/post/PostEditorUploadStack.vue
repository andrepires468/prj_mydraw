<script setup lang="ts">
import { ref } from 'vue'
import { useDropzoneScrollCompact } from '@/composables/useDropzoneScrollCompact'
import UploadDropzone from '@/components/post/UploadDropzone.vue'

defineProps<{
  maxFiles: number
  disabled: boolean
}>()

const emit = defineEmits<{
  files: [files: File[]]
}>()

const stickyWrapperRef = ref<HTMLElement | null>(null)
const { compact: dropzoneCompact } = useDropzoneScrollCompact(stickyWrapperRef)
</script>

<template>
  <div class="create-post-page__editor-stack">
    <div
      ref="stickyWrapperRef"
      class="create-post-page__dropzone-sticky"
      :class="{
        'create-post-page__dropzone-sticky--compact': dropzoneCompact,
      }"
    >
      <UploadDropzone
        :compact="dropzoneCompact"
        :max-files="maxFiles"
        :disabled="disabled"
        @files="emit('files', $event)"
      />
    </div>
    <slot />
  </div>
</template>
