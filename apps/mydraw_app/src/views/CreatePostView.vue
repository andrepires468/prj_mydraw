<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'
import { createPost, reorderPostImages, uploadGalleryImage } from '@/api/posts'
import AppConfirmAlert from '@/components/common/AppConfirmAlert.vue'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import PostEditorUploadStack from '@/components/post/PostEditorUploadStack.vue'
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

/** Rascunho no servidor: primeiro upload cria o post; necessário para PUT reorder. */
const draftPostId = ref<number | null>(null)
const reorderMode = ref(false)
/** Snapshot dos ids na ordem original ao entrar em modo reordenar (para cancelar). */
const orderSnapshotIds = ref<string[] | null>(null)
const applyingReorder = ref(false)

const removeConfirmImageId = ref<string | null>(null)

const MAX_FILES = 28

const remainingImageSlots = computed(() =>
  Math.max(0, MAX_FILES - images.value.length),
)

const canPublish = computed(
  () =>
    !submitting.value &&
    !reorderMode.value &&
    images.value.length > 0 &&
    images.value.every((i) => i.status === 'done'),
)

const canApplyReorder = computed(() => {
  if (!draftPostId.value) return false
  if (images.value.length === 0) return false
  return images.value.every(
    (i) => i.status === 'done' && i.galleryId !== undefined,
  )
})

const editorBusy = computed(
  () => submitting.value || reorderMode.value || applyingReorder.value,
)

function extractErrorMessage(err: unknown): string {
  if (axios.isAxiosError(err)) {
    if (err.code === 'ECONNABORTED') {
      return 'Tempo da requisição esgotado. Tente novamente.'
    }
    if (axios.isCancel(err)) {
      return 'Requisição cancelada.'
    }
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
  if (reorderMode.value) return
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
      img.thumbnailUrl = gallery.thumbs?.['320x320'] ?? null
      if (gallery.post_id != null) {
        draftPostId.value = gallery.post_id
      }
      if (gallery.url) {
        if (img.previewUrl.startsWith('blob:')) {
          URL.revokeObjectURL(img.previewUrl)
        }
        img.previewUrl = gallery.url
      }
    }
  } catch (err) {
    const img = images.value.find((i) => i.id === id)
    if (img) {
      img.status = 'error'
      img.error = extractErrorMessage(err)
    }
  }
}

function requestRemoveImage(id: string) {
  if (reorderMode.value) return
  if (images.value.length <= 1) {
    errorMsg.value =
      'O post precisa manter ao menos uma imagem. Adicione outra antes de remover esta.'
    return
  }
  const idx = images.value.findIndex((item) => item.id === id)
  if (idx < 0) return
  if (images.value[idx].status === 'uploading') return
  removeConfirmImageId.value = id
}

function cancelRemoveImage() {
  removeConfirmImageId.value = null
}

function confirmRemoveImage() {
  const id = removeConfirmImageId.value
  removeConfirmImageId.value = null
  if (!id) return
  removeImageNow(id)
}

function removeImageNow(id: string) {
  if (images.value.length <= 1) return
  const idx = images.value.findIndex((item) => item.id === id)
  if (idx < 0) return
  if (images.value[idx].status === 'uploading') return
  const url = images.value[idx].previewUrl
  if (url.startsWith('blob:')) URL.revokeObjectURL(url)
  images.value.splice(idx, 1)
}

function previewImage(id: string) {
  if (reorderMode.value) return
  const img = images.value.find((item) => item.id === id)
  if (img) window.open(img.previewUrl, '_blank')
}

function reorder(from: number, to: number) {
  if (!reorderMode.value) return
  const [moved] = images.value.splice(from, 1)
  images.value.splice(to, 0, moved)
}

function enterReorderMode() {
  orderSnapshotIds.value = images.value.map((i) => i.id)
  reorderMode.value = true
  errorMsg.value = null
}

function cancelReorderMode() {
  if (orderSnapshotIds.value) {
    const order = orderSnapshotIds.value
    images.value.sort(
      (a, b) => order.indexOf(a.id) - order.indexOf(b.id),
    )
  }
  orderSnapshotIds.value = null
  reorderMode.value = false
}

async function applyReorder() {
  if (!reorderMode.value || !canApplyReorder.value || !draftPostId.value) return
  errorMsg.value = null
  successMsg.value = null
  applyingReorder.value = true
  const order = images.value
    .map((i) => i.galleryId)
    .filter((id): id is number => id !== undefined)
  try {
    await reorderPostImages(draftPostId.value, order)
    orderSnapshotIds.value = null
    reorderMode.value = false
    successMsg.value = 'Ordem das imagens atualizada.'
  } catch (err) {
    errorMsg.value = extractErrorMessage(err)
  } finally {
    applyingReorder.value = false
  }
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
  for (const img of images.value) {
    if (img.previewUrl.startsWith('blob:')) {
      URL.revokeObjectURL(img.previewUrl)
    }
  }
})
</script>

<template>
  <main class="app-page-inner create-post-page">
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
        <PostEditorUploadStack
          :max-files="remainingImageSlots"
          :disabled="editorBusy || images.length >= MAX_FILES"
          @files="addFiles"
        >
          <section v-if="images.length > 0" class="flex flex-col gap-6">
            <div class="create-post-page__section-header">
              <h2 class="create-post-page__section-title">
                Manage Queue ({{ images.length }}
                {{ images.length === 1 ? 'Image' : 'Images' }})
              </h2>
              <p
                v-if="reorderMode"
                class="create-post-page__section-hint mt-2 max-w-xl"
              >
                Arraste as miniaturas abaixo para reordenar; use o painel à
                direita para aplicar ou cancelar.
              </p>
            </div>

            <ImageQueueGrid
              :images="images"
              :show-placeholder="
                !reorderMode && images.length < MAX_FILES
              "
              :reorder-enabled="reorderMode"
              :card-actions-disabled="reorderMode"
              :allow-file-import-on-cards="!reorderMode"
              @remove="requestRemoveImage"
              @preview="previewImage"
              @reorder="reorder"
              @import-files="addFiles"
            />
          </section>
        </PostEditorUploadStack>
      </div>

      <div class="create-post-page__aside">
        <PostMetadataForm
          :title="title"
          :description="description"
          :submitting="submitting"
          :can-publish="canPublish"
          :editor-locked="reorderMode || applyingReorder"
          @update:title="title = $event"
          @update:description="description = $event"
          @publish="publish"
        >
          <template v-if="images.length > 0" #reorder>
            <p class="post-metadata__reorder-title">Ordem das imagens</p>
            <template v-if="!reorderMode">
              <p class="post-metadata__reorder-hint">
                Altere a ordem de exibição na galeria.
              </p>
              <div class="post-metadata__reorder-actions">
                <button
                  type="button"
                  class="post-metadata__secondary"
                  :disabled="submitting || applyingReorder"
                  @click="enterReorderMode"
                >
                  Ordenar imagens
                </button>
              </div>
            </template>
            <template v-else>
              <p class="post-metadata__reorder-hint">
                Arraste as miniaturas na fila à esquerda, depois confirme.
              </p>
              <div class="post-metadata__reorder-actions">
                <button
                  type="button"
                  class="post-metadata__secondary"
                  :disabled="applyingReorder"
                  @click="cancelReorderMode"
                >
                  Cancelar
                </button>
                <button
                  type="button"
                  class="post-metadata__reorder-apply"
                  :disabled="!canApplyReorder || applyingReorder"
                  @click="applyReorder"
                >
                  {{
                    applyingReorder ? 'Aplicando…' : 'Aplicar ordem'
                  }}
                </button>
              </div>
            </template>
          </template>
        </PostMetadataForm>
      </div>
    </div>

    <AppConfirmAlert
      v-if="removeConfirmImageId"
      title="Remover imagem"
      message="A imagem será removida da fila de envio. Deseja continuar?"
      cancel-label="Cancelar"
      confirm-label="Remover"
      confirm-variant="danger"
      @cancel="cancelRemoveImage"
      @confirm="confirmRemoveImage"
    />
  </main>
</template>
