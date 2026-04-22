<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import {
  deletePost,
  deletePostImage,
  getPost,
  reorderPostImages,
  updatePost,
  uploadPostImage,
  type GalleryRow,
  type PostRow,
} from '@/api/posts'
import AppConfirmAlert from '@/components/common/AppConfirmAlert.vue'
import AppModal from '@/components/common/AppModal.vue'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import PostEditorUploadStack from '@/components/post/PostEditorUploadStack.vue'
import ImageQueueGrid from '@/components/post/ImageQueueGrid.vue'
import PostMetadataForm from '@/components/post/PostMetadataForm.vue'
import type { QueuedImage } from '@/components/post/types'

interface Props {
  id: number | string
}

const props = defineProps<Props>()

const router = useRouter()
const route = useRoute()

const MAX_FILES = 28

const post = ref<PostRow | null>(null)
const title = ref('')
const description = ref('')
const images = ref<QueuedImage[]>([])

const loading = ref(true)
const loadError = ref<string | null>(null)

const errorMsg = ref<string | null>(null)
const successMsg = ref<string | null>(null)

const previewModalUrl = ref<string | null>(null)
const previewModalAlt = ref('')
/** No modal de pré-visualização: mostra pixels naturais da imagem (com rolagem). */
const previewModalNaturalSize = ref(false)

const removeConfirmImageId = ref<string | null>(null)
const deletePostConfirmOpen = ref(false)

const pendingAction = ref<
  'save' | 'publish' | 'unpublish' | 'delete' | null
>(null)
const submitting = computed(() => pendingAction.value !== null)

const reorderMode = ref(false)
const orderSnapshotIds = ref<string[] | null>(null)
const applyingReorder = ref(false)

const uploadAndEditorLocked = computed(
  () =>
    submitting.value ||
    reorderMode.value ||
    applyingReorder.value,
)

/** Revogar object URLs criadas para previews locais (não as retornadas pela API). */
const locallyCreatedPreviewUrls = new Set<string>()

/** ID numérico do post na rota (props + params para compatibilidade com o router). */
const postId = computed(() => {
  const raw = props.id ?? route.params.id
  if (raw === undefined || raw === null || raw === '') return null
  const n = typeof raw === 'string' ? parseInt(raw, 10) : Number(raw)
  return Number.isFinite(n) ? n : null
})

const remainingImageSlots = computed(() =>
  Math.max(0, MAX_FILES - images.value.length),
)

const status = computed<'draft' | 'published'>(
  () => post.value?.status ?? 'draft',
)

/** Regras para habilitar o botão principal. */
const canPublish = computed(() => {
  if (submitting.value) return false
  if (reorderMode.value) return false
  const anyUploading = images.value.some((i) => i.status === 'uploading')
  if (anyUploading) return false
  if (images.value.length === 0) return false
  return true
})

const canApplyReorder = computed(() => {
  if (!postId.value) return false
  if (images.value.length === 0) return false
  return images.value.every(
    (i) => i.status === 'done' && i.galleryId !== undefined,
  )
})

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
        : err.response?.status === 404
          ? 'Post não encontrado.'
          : 'Falha ao processar a solicitação.')
    )
  }
  return err instanceof Error ? err.message : 'Erro desconhecido'
}

function galleryToQueued(row: GalleryRow): QueuedImage {
  return {
    id: `g-${row.id}`,
    // Sem acesso ao File original — usamos um placeholder mínimo para a tipagem.
    file: new File([], `gallery-${row.id}`),
    previewUrl: row.url ?? '',
    thumbnailUrl: row.thumbs?.['320x320'] ?? null,
    status: 'done',
    progress: 100,
    galleryId: row.id,
  }
}

async function load() {
  if (postId.value === null) {
    loadError.value = 'ID de post inválido.'
    loading.value = false
    return
  }

  loading.value = true
  loadError.value = null
  try {
    const data = await getPost(postId.value)
    post.value = data
    title.value = data.title ?? ''
    description.value = data.description ?? ''
    images.value = (data.images ?? [])
      .filter((img) => !!img.url)
      .slice()
      .sort(
        (a, b) =>
          (a.position ?? 0) - (b.position ?? 0) || a.id - b.id,
      )
      .map(galleryToQueued)
  } catch (err) {
    loadError.value = extractErrorMessage(err)
  } finally {
    loading.value = false
  }
}

function addFiles(files: File[]) {
  if (reorderMode.value) return
  if (!postId.value) return
  const remaining = MAX_FILES - images.value.length
  if (remaining <= 0) return

  const toAdd = files.slice(0, remaining)
  for (const file of toAdd) {
    const id = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
    const previewUrl = URL.createObjectURL(file)
    locallyCreatedPreviewUrls.add(previewUrl)
    const item: QueuedImage = {
      id,
      file,
      previewUrl,
      status: 'uploading',
      progress: 0,
    }
    images.value.push(item)
    void uploadOne(id, file)
  }
}

async function uploadOne(localId: string, file: File) {
  if (!postId.value) return
  try {
    const gallery = await uploadPostImage(postId.value, file, (p) => {
      const img = images.value.find((i) => i.id === localId)
      if (img) img.progress = p
    })
    const img = images.value.find((i) => i.id === localId)
    if (img) {
      img.status = 'done'
      img.progress = 100
      img.galleryId = gallery.id
      img.thumbnailUrl = gallery.thumbs?.['320x320'] ?? null
      // Substitui preview local pela URL assinada do bucket, se vier.
      if (gallery.url) {
        if (locallyCreatedPreviewUrls.has(img.previewUrl)) {
          URL.revokeObjectURL(img.previewUrl)
          locallyCreatedPreviewUrls.delete(img.previewUrl)
        }
        img.previewUrl = gallery.url
      }
    }
  } catch (err) {
    const img = images.value.find((i) => i.id === localId)
    if (img) {
      img.status = 'error'
      img.error = extractErrorMessage(err)
    }
  }
}

function requestRemoveImage(localId: string) {
  if (reorderMode.value) return
  if (!postId.value) return
  if (images.value.length <= 1) {
    errorMsg.value =
      'O post precisa manter ao menos uma imagem. Adicione outra antes de remover esta.'
    return
  }
  const idx = images.value.findIndex((i) => i.id === localId)
  if (idx < 0) return
  const img = images.value[idx]
  if (img.status === 'uploading') return
  removeConfirmImageId.value = localId
}

function cancelRemoveImage() {
  removeConfirmImageId.value = null
}

async function confirmRemoveImage() {
  const localId = removeConfirmImageId.value
  removeConfirmImageId.value = null
  if (!localId) return
  await removeImageNow(localId)
}

async function removeImageNow(localId: string) {
  if (!postId.value) return
  if (images.value.length <= 1) return
  const idx = images.value.findIndex((i) => i.id === localId)
  if (idx < 0) return
  if (images.value[idx].status === 'uploading') return

  // Remoção otimista da UI; rollback em caso de falha.
  const [removed] = images.value.splice(idx, 1)

  if (locallyCreatedPreviewUrls.has(removed.previewUrl)) {
    URL.revokeObjectURL(removed.previewUrl)
    locallyCreatedPreviewUrls.delete(removed.previewUrl)
  }

  if (removed.galleryId !== undefined) {
    try {
      await deletePostImage(postId.value, removed.galleryId)
    } catch (err) {
      images.value.splice(idx, 0, removed)
      errorMsg.value = extractErrorMessage(err)
    }
  }
}

function previewImage(localId: string) {
  if (reorderMode.value) return
  const img = images.value.find((i) => i.id === localId)
  if (!img?.previewUrl) return
  previewModalNaturalSize.value = false
  previewModalUrl.value = img.previewUrl
  previewModalAlt.value = img.file.name || 'Pré-visualização'
}

function closePreviewModal() {
  previewModalUrl.value = null
  previewModalNaturalSize.value = false
}

function reorder(from: number, to: number) {
  if (!reorderMode.value) return
  const [moved] = images.value.splice(from, 1)
  images.value.splice(to, 0, moved)
}

function enterReorderMode() {
  orderSnapshotIds.value = images.value.map((i) => i.id)
  reorderMode.value = true
  clearMessages()
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
  if (!reorderMode.value || !postId.value || !canApplyReorder.value) return
  clearMessages()
  applyingReorder.value = true
  const order = images.value
    .map((i) => i.galleryId)
    .filter((id): id is number => id !== undefined)
  try {
    await reorderPostImages(postId.value, order)
    orderSnapshotIds.value = null
    reorderMode.value = false
    successMsg.value = 'Ordem das imagens atualizada.'
  } catch (err) {
    errorMsg.value = extractErrorMessage(err)
    await load()
  } finally {
    applyingReorder.value = false
  }
}

function clearMessages() {
  errorMsg.value = null
  successMsg.value = null
}

async function saveDraft() {
  if (submitting.value || reorderMode.value || !postId.value) return
  clearMessages()
  pendingAction.value = 'save'
  try {
    const updated = await updatePost(postId.value, {
      title: title.value.trim(),
      description: description.value.trim() || null,
    })
    post.value = { ...post.value, ...updated }
    successMsg.value = 'Rascunho salvo.'
  } catch (err) {
    errorMsg.value = extractErrorMessage(err)
  } finally {
    pendingAction.value = null
  }
}

async function publishOrSave() {
  if (!canPublish.value || reorderMode.value || !postId.value) return
  clearMessages()
  pendingAction.value = 'publish'
  try {
    const payload =
      status.value === 'published'
        ? {
            title: title.value.trim(),
            description: description.value.trim() || null,
          }
        : {
            title: title.value.trim(),
            description: description.value.trim() || null,
            status: 'published' as const,
          }
    const updated = await updatePost(postId.value, payload)
    post.value = { ...post.value, ...updated }
    successMsg.value =
      status.value === 'published'
        ? 'Post atualizado.'
        : 'Post publicado com sucesso.'
    if (status.value === 'published') {
      window.setTimeout(() => router.push({ name: 'post', params: { id: postId.value } }), 700)
    }
  } catch (err) {
    errorMsg.value = extractErrorMessage(err)
  } finally {
    pendingAction.value = null
  }
}

async function unpublish() {
  if (submitting.value || reorderMode.value || !postId.value) return
  clearMessages()
  pendingAction.value = 'unpublish'
  try {
    const updated = await updatePost(postId.value, { status: 'draft' })
    post.value = { ...post.value, ...updated }
    successMsg.value = 'Post movido para rascunho.'
  } catch (err) {
    errorMsg.value = extractErrorMessage(err)
  } finally {
    pendingAction.value = null
  }
}

function requestDeletePost() {
  if (submitting.value || reorderMode.value || !postId.value) return
  deletePostConfirmOpen.value = true
}

function cancelDeletePost() {
  deletePostConfirmOpen.value = false
}

async function confirmDeletePost() {
  if (submitting.value || !postId.value) return
  deletePostConfirmOpen.value = false
  clearMessages()
  pendingAction.value = 'delete'
  try {
    await deletePost(postId.value)
    successMsg.value = 'Post excluído.'
    window.setTimeout(() => router.push('/gallery'), 500)
  } catch (err) {
    errorMsg.value = extractErrorMessage(err)
  } finally {
    pendingAction.value = null
  }
}

watch(
  postId,
  (id) => {
    if (id === null) {
      loading.value = false
      loadError.value = 'ID de post inválido.'
      return
    }
    void load()
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  for (const url of locallyCreatedPreviewUrls) {
    URL.revokeObjectURL(url)
  }
  locallyCreatedPreviewUrls.clear()
})
</script>

<template>
  <main class="app-page-inner create-post-page">
    <header class="create-post-page__header">
      <h1 class="create-post-page__title">
        {{ status === 'published' ? 'Edit Post' : 'Edit Draft' }}
      </h1>
      <p class="create-post-page__subtitle">
        <template v-if="status === 'draft'">
          Continue de onde parou. Salve como rascunho ou publique quando estiver pronto.
        </template>
        <template v-else>
          Refine título, descrição e imagens. Alterações ficam públicas imediatamente.
        </template>
      </p>
    </header>

    <p
      v-if="loadError"
      class="create-post-page__alert create-post-page__alert--error"
      role="alert"
    >
      <MaterialIcon name="error" filled />
      <span>{{ loadError }}</span>
    </p>

    <template v-else-if="loading">
      <p class="create-post-page__subtitle">Carregando post…</p>
    </template>

    <template v-else>
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
            :disabled="
              uploadAndEditorLocked || images.length >= MAX_FILES
            "
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
                require-at-least-one-image
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
            mode="edit"
            :status="status"
            :title="title"
            :description="description"
            :submitting="submitting"
            :can-publish="canPublish"
            :pending-action="pendingAction"
            :editor-locked="reorderMode || applyingReorder"
            @update:title="title = $event"
            @update:description="description = $event"
            @publish="publishOrSave"
            @save="saveDraft"
            @unpublish="unpublish"
            @destroy="requestDeletePost"
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
    </template>

    <AppConfirmAlert
      v-if="removeConfirmImageId"
      title="Remover imagem"
      message="A imagem será excluída do post e removida da fila. Deseja continuar?"
      cancel-label="Cancelar"
      confirm-label="Remover"
      confirm-variant="danger"
      @cancel="cancelRemoveImage"
      @confirm="confirmRemoveImage"
    />

    <AppConfirmAlert
      v-if="deletePostConfirmOpen"
      title="Excluir post"
      message="Este post e todas as imagens serão excluídos de forma permanente. Esta ação não pode ser desfeita."
      cancel-label="Cancelar"
      confirm-label="Excluir post"
      confirm-variant="danger"
      @cancel="cancelDeletePost"
      @confirm="confirmDeletePost"
    />

    <AppModal
      v-if="previewModalUrl"
      title="Visualização da imagem"
      @close="closePreviewModal"
    >
      <template #actions>
        <button
          type="button"
          class="app-modal__edit-post"
          :aria-pressed="previewModalNaturalSize"
          :title="
            previewModalNaturalSize
              ? 'Encaixar a imagem na área visível'
              : 'Ver a imagem no tamanho real (1:1)'
          "
          @click="previewModalNaturalSize = !previewModalNaturalSize"
        >
          {{
            previewModalNaturalSize ? 'Ajustar à tela' : 'Tamanho real'
          }}
        </button>
      </template>
      <div
        class="p-2 md:p-4"
        :class="
          previewModalNaturalSize
            ? 'overflow-auto max-h-[min(85vh,calc(100vh-8rem))] w-full min-h-0'
            : 'flex justify-center'
        "
      >
        <img
          :src="previewModalUrl"
          :alt="previewModalAlt"
          :class="
            previewModalNaturalSize
              ? 'block max-w-none max-h-none w-max h-max shrink-0 rounded-lg'
              : 'max-h-[min(85vh,calc(100vh-8rem))] max-w-full rounded-lg object-contain'
          "
        />
      </div>
    </AppModal>
  </main>
</template>

<style scoped>
/* Botão "Delete post" na barra lateral — tom #b770c2 */
.create-post-page :deep(.post-metadata__danger) {
  color: #b770c2;
  border-color: rgb(183 112 194 / 40%);
  background: transparent;
}

.create-post-page :deep(.post-metadata__danger:hover:not(:disabled)) {
  background: rgb(183 112 194 / 8%);
}

.create-post-page :deep(.post-metadata__danger .post-metadata__publish-spinner) {
  border-color: rgb(183 112 194 / 25%);
  border-top-color: #b770c2;
}
</style>
