<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'
import { createPost, uploadGalleryImage } from '@/api/posts'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import UploadDropzone from '@/components/post/UploadDropzone.vue'
import ImageQueueGrid from '@/components/post/ImageQueueGrid.vue'
import PostMetadataForm from '@/components/post/PostMetadataForm.vue'
import type { QueuedImage } from '@/components/post/types'

const router = useRouter()

/** Rascunho: vincula uploads ao post final (UUID v4). */
const draftUuid = crypto.randomUUID()

const title = ref('')
const description = ref('')
const images = ref<QueuedImage[]>([])
const submitting = ref(false)
const errorMsg = ref<string | null>(null)
const successMsg = ref<string | null>(null)

const MAX_FILES = 10

const canPublish = computed(
  () =>
    !submitting.value &&
    images.value.length > 0 &&
    images.value.every((i) => i.status === 'done'),
)

function extractErrorMessage(err: unknown): string {
  if (axios.isAxiosError(err)) {
    const data = err.response?.data as
      | { message?: string; errors?: Record<string, string[]>; error?: string }
      | undefined
    const firstFieldError = data?.errors
      ? Object.values(data.errors)[0]?.[0]
      : undefined
    return (
      firstFieldError ??
      data?.message ??
      data?.error ??
      (err.response?.status === 401
        ? 'Sessão expirada. Faça login novamente.'
        : 'Falha ao enviar.')
    )
  }
  return err instanceof Error ? err.message : 'Erro desconhecido'
}

function addFiles(files: File[]) {
  const remaining = MAX_FILES - images.value.length
  if (remaining <= 0) return

  const toAdd = files.slice(0, remaining)

  for (const file of toAdd) {
    const id = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
    const item: QueuedImage = {
      id,
      file,
      previewUrl: URL.createObjectURL(file),
      status: 'uploading',
      progress: 0,
    }
    images.value.push(item)
    void uploadOne(id, file)
  }
}

async function uploadOne(id: string, file: File) {
  try {
    const gallery = await uploadGalleryImage(draftUuid, file, (p) => {
      const img = images.value.find((i) => i.id === id)
      if (img) img.progress = p
    })
    const img = images.value.find((i) => i.id === id)
    if (img) {
      img.status = 'done'
      img.progress = 100
      img.galleryId = gallery.id
    }
  } catch (err) {
    const img = images.value.find((i) => i.id === id)
    if (img) {
      img.status = 'error'
      img.error = extractErrorMessage(err)
    }
  }
}

function removeImage(id: string) {
  const idx = images.value.findIndex((item) => item.id === id)
  if (idx < 0) return
  if (images.value[idx].status === 'uploading') return
  URL.revokeObjectURL(images.value[idx].previewUrl)
  images.value.splice(idx, 1)
}

function previewImage(id: string) {
  const img = images.value.find((item) => item.id === id)
  if (img) window.open(img.previewUrl, '_blank')
}

function reorder(from: number, to: number) {
  const [moved] = images.value.splice(from, 1)
  images.value.splice(to, 0, moved)
}

async function publish() {
  if (!canPublish.value) return
  errorMsg.value = null
  successMsg.value = null
  submitting.value = true

  try {
    await createPost(
      draftUuid,
      title.value.trim(),
      description.value.trim() || null,
    )
    successMsg.value = 'Post publicado com sucesso.'
    window.setTimeout(() => router.push('/gallery'), 900)
  } catch (err) {
    errorMsg.value = extractErrorMessage(err)
  } finally {
    submitting.value = false
  }
}

onBeforeUnmount(() => {
  for (const img of images.value) URL.revokeObjectURL(img.previewUrl)
})
</script>

<template>
  <main class="create-post-page">
    <header class="create-post-page__header">
      <h1 class="create-post-page__title">Create New Post</h1>
      <p class="create-post-page__subtitle">
        Curate your visual story with high-resolution imagery and elegant
        typography.
      </p>
    </header>

    <p
      v-if="errorMsg"
      class="create-post-page__alert create-post-page__alert--error"
      role="alert"
    >
      <MaterialIcon name="error" filled />
      <span>{{ errorMsg }}</span>
    </p>
    <p
      v-else-if="successMsg"
      class="create-post-page__alert create-post-page__alert--success"
      role="status"
    >
      <MaterialIcon name="check_circle" filled />
      <span>{{ successMsg }}</span>
    </p>

    <div class="create-post-page__layout">
      <div class="create-post-page__editor">
        <section>
          <UploadDropzone
            :max-files="MAX_FILES"
            :disabled="submitting || images.length >= MAX_FILES"
            @files="addFiles"
          />
        </section>

        <section v-if="images.length > 0" class="flex flex-col gap-6">
          <div class="create-post-page__section-header">
            <h2 class="create-post-page__section-title">
              Manage Queue ({{ images.length }}
              {{ images.length === 1 ? 'Image' : 'Images' }})
            </h2>
            <span class="create-post-page__section-hint">Drag to reorder</span>
          </div>

          <ImageQueueGrid
            :images="images"
            :show-placeholder="images.length < MAX_FILES"
            @remove="removeImage"
            @preview="previewImage"
            @reorder="reorder"
          />
        </section>
      </div>

      <div class="create-post-page__aside">
        <PostMetadataForm
          :title="title"
          :description="description"
          :submitting="submitting"
          :can-publish="canPublish"
          @update:title="title = $event"
          @update:description="description = $event"
          @publish="publish"
        />
      </div>
    </div>
  </main>
</template>
