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
  uploadGalleryImage,
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
  id?: number | string
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

/** Enquanto há upload/fila pendente no backend — bloqueia ações laterais como o publish. */
const imagePipelineBusy = computed(() =>
  images.value.some(
    (i) => i.status === 'uploading' || i.status === 'queued',
  ),
)

const uploadAndEditorLocked = computed(
  () =>
    submitting.value ||
    reorderMode.value ||
    applyingReorder.value,
)

const isNewPostRoute = computed(() => route.name === 'edit-post-new')

/** UUID de rascunho: primeiro upload em `/create-post/upload` cria o post na API. */
const draftUuid = ref(crypto.randomUUID())

/** Após o primeiro upload no modo novo, até o `router.replace` com id numérico. */
const createdPostId = ref<number | null>(null)

/** Quando preenchido e todas as imagens estiverem `done`, navega para `/posts/:id/edit`. */
const pendingUrlReplacePostId = ref<number | null>(null)

/** Revogar object URLs criadas para previews locais (não as retornadas pela API). */
const locallyCreatedPreviewUrls = new Set<string>()

/** ID numérico do post na rota (`null` em `/posts/new/edit`). */
const postId = computed(() => {
  if (isNewPostRoute.value) return null
  const raw = props.id ?? route.params.id
  if (raw === undefined || raw === null || raw === '') return null
  const n = typeof raw === 'string' ? parseInt(raw, 10) : Number(raw)
  return Number.isFinite(n) ? n : null
})

/** ID usado nas chamadas à API (rota numérica ou post criado pelo primeiro upload). */
const effectivePostId = computed(() => {
  if (isNewPostRoute.value) return createdPostId.value
  return postId.value
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
  if (images.value.length === 0) return false
  if (!images.value.every((i) => i.status === 'done')) return false
  return true
})

const canApplyReorder = computed(() => {
  if (effectivePostId.value === null) return false
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

function initNewPostSession() {
  draftUuid.value = crypto.randomUUID()
  createdPostId.value = null
  pendingUrlReplacePostId.value = null
  post.value = null
  title.value = ''
  description.value = ''
  for (const img of images.value) {
    if (img.previewUrl.startsWith('blob:')) {
      URL.revokeObjectURL(img.previewUrl)
    }
  }
  images.value = []
  locallyCreatedPreviewUrls.clear()
  loadError.value = null
  loading.value = false
  errorMsg.value = null
  successMsg.value = null
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
  const remaining = MAX_FILES - images.value.length
  if (remaining <= 0) return

  const toAdd = files.slice(0, remaining)
  const pid = effectivePostId.value

  if (isNewPostRoute.value && pid === null) {
    const draftPipelineBusy = images.value.some(
      (i) =>
        i.status === 'uploading' ||
        i.status === 'queued' ||
        (createdPostId.value === null && i.status === 'error'),
    )
    let startedDraftHead = false
    for (const file of toAdd) {
      const id = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
      const previewUrl = URL.createObjectURL(file)
      locallyCreatedPreviewUrls.add(previewUrl)
      const startDraftNow = !draftPipelineBusy && !startedDraftHead
      if (startDraftNow) startedDraftHead = true
      const item: QueuedImage = {
        id,
        file,
        previewUrl,
        status: startDraftNow ? 'uploading' : 'queued',
        progress: 0,
      }
      images.value.push(item)
      if (startDraftNow) void uploadGalleryForFirst(id, file)
    }
    return
  }

  if (pid === null) return

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
    void uploadPostImageForItem(id, file, pid)
  }
}

async function uploadPostImageForItem(
  localId: string,
  file: File,
  postNum: number,
) {
  try {
    const gallery = await uploadPostImage(postNum, file, (p) => {
      const img = images.value.find((i) => i.id === localId)
      if (img) img.progress = p
    })
    const img = images.value.find((i) => i.id === localId)
    if (img) {
      img.status = 'done'
      img.progress = 100
      img.galleryId = gallery.id
      img.thumbnailUrl = gallery.thumbs?.['320x320'] ?? null
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

async function flushQueuedWithPostId(postNum: number) {
  while (true) {
    const queued = images.value.filter((i) => i.status === 'queued')
    if (queued.length === 0) break
    await Promise.all(
      queued.map((q) => {
        q.status = 'uploading'
        q.progress = 0
        return uploadPostImageForItem(q.id, q.file, postNum)
      }),
    )
  }
}

async function uploadGalleryForFirst(localId: string, file: File) {
  try {
    const gallery = await uploadGalleryImage(draftUuid.value, file, (p) => {
      const img = images.value.find((i) => i.id === localId)
      if (img) img.progress = p
    })
    const img = images.value.find((i) => i.id === localId)
    if (img) {
      img.status = 'done'
      img.progress = 100
      img.galleryId = gallery.id
      img.thumbnailUrl = gallery.thumbs?.['320x320'] ?? null
      if (gallery.url) {
        if (locallyCreatedPreviewUrls.has(img.previewUrl)) {
          URL.revokeObjectURL(img.previewUrl)
          locallyCreatedPreviewUrls.delete(img.previewUrl)
        }
        img.previewUrl = gallery.url
      }
    }
    const postNum = gallery.post_id ?? createdPostId.value
    if (postNum == null) {
      errorMsg.value =
        'Resposta do servidor sem post_id. Tente enviar a primeira imagem novamente.'
      return
    }
    createdPostId.value = postNum
    pendingUrlReplacePostId.value = postNum
    await flushQueuedWithPostId(postNum)
  } catch (err) {
    const img = images.value.find((i) => i.id === localId)
    if (img) {
      img.status = 'error'
      img.error = extractErrorMessage(err)
    }
  }
}

function tryPromoteNextDraftHead() {
  if (!isNewPostRoute.value || createdPostId.value !== null) return
  if (images.value.some((i) => i.status === 'uploading')) return
  if (images.value.some((i) => i.status === 'error')) return
  const next = images.value.find((i) => i.status === 'queued')
  if (!next) return
  next.status = 'uploading'
  next.progress = 0
  void uploadGalleryForFirst(next.id, next.file)
}

function requestRemoveImage(localId: string) {
  if (reorderMode.value) return
  const idxCheck = images.value.findIndex((i) => i.id === localId)
  const allowLastRemoveError =
    isNewPostRoute.value &&
    images.value.length === 1 &&
    idxCheck >= 0 &&
    images.value[idxCheck].status === 'error'
  if (images.value.length <= 1 && !allowLastRemoveError) {
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
  const idx = images.value.findIndex((i) => i.id === localId)
  if (idx < 0) return
  if (images.value[idx].status === 'uploading') return
  const allowLastRemoveError =
    isNewPostRoute.value &&
    images.value.length === 1 &&
    images.value[idx].status === 'error'
  if (images.value.length <= 1 && !allowLastRemoveError) return

  // Remoção otimista da UI; rollback em caso de falha.
  const [removed] = images.value.splice(idx, 1)

  if (locallyCreatedPreviewUrls.has(removed.previewUrl)) {
    URL.revokeObjectURL(removed.previewUrl)
    locallyCreatedPreviewUrls.delete(removed.previewUrl)
  }

  if (removed.galleryId !== undefined && effectivePostId.value !== null) {
    try {
      await deletePostImage(effectivePostId.value, removed.galleryId)
    } catch (err) {
      images.value.splice(idx, 0, removed)
      errorMsg.value = extractErrorMessage(err)
      return
    }
  }
  tryPromoteNextDraftHead()
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
  if (images.value.some((i) => i.status !== 'done')) return
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
  if (
    !reorderMode.value ||
    effectivePostId.value === null ||
    !canApplyReorder.value
  )
    return
  clearMessages()
  applyingReorder.value = true
  const order = images.value
    .map((i) => i.galleryId)
    .filter((id): id is number => id !== undefined)
  try {
    await reorderPostImages(effectivePostId.value, order)
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
  if (
    submitting.value ||
    reorderMode.value ||
    imagePipelineBusy.value ||
    effectivePostId.value === null
  )
    return
  clearMessages()
  pendingAction.value = 'save'
  try {
    const updated = await updatePost(effectivePostId.value, {
      title: title.value.trim(),
      description: description.value.trim() || null,
    })
    post.value =
      post.value === null
        ? updated
        : { ...post.value, ...updated }
    successMsg.value = 'Rascunho salvo.'
  } catch (err) {
    errorMsg.value = extractErrorMessage(err)
  } finally {
    pendingAction.value = null
  }
}

async function publishOrSave() {
  if (
    !canPublish.value ||
    reorderMode.value ||
    imagePipelineBusy.value ||
    effectivePostId.value === null
  )
    return
  const pid = effectivePostId.value
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
    const updated = await updatePost(pid, payload)
    post.value =
      post.value === null ? updated : { ...post.value, ...updated }
    successMsg.value =
      status.value === 'published'
        ? 'Post atualizado.'
        : 'Post publicado com sucesso.'
    if (status.value === 'published') {
      window.setTimeout(
        () => router.push({ name: 'post', params: { id: pid } }),
        700,
      )
    }
  } catch (err) {
    errorMsg.value = extractErrorMessage(err)
  } finally {
    pendingAction.value = null
  }
}

async function unpublish() {
  if (
    submitting.value ||
    reorderMode.value ||
    imagePipelineBusy.value ||
    effectivePostId.value === null
  )
    return
  clearMessages()
  pendingAction.value = 'unpublish'
  try {
    const updated = await updatePost(effectivePostId.value, {
      status: 'draft',
    })
    post.value =
      post.value === null
        ? updated
        : { ...post.value, ...updated }
    successMsg.value = 'Post movido para rascunho.'
  } catch (err) {
    errorMsg.value = extractErrorMessage(err)
  } finally {
    pendingAction.value = null
  }
}

function requestDeletePost() {
  if (
    submitting.value ||
    reorderMode.value ||
    imagePipelineBusy.value ||
    effectivePostId.value === null
  )
    return
  deletePostConfirmOpen.value = true
}

function cancelDeletePost() {
  deletePostConfirmOpen.value = false
}

async function confirmDeletePost() {
  if (submitting.value || effectivePostId.value === null) return
  deletePostConfirmOpen.value = false
  clearMessages()
  pendingAction.value = 'delete'
  try {
    await deletePost(effectivePostId.value)
    successMsg.value = 'Post excluído.'
    window.setTimeout(() => router.push('/gallery'), 500)
  } catch (err) {
    errorMsg.value = extractErrorMessage(err)
  } finally {
    pendingAction.value = null
  }
}

watch(
  () => [route.name, postId.value] as const,
  ([name, id]) => {
    if (name === 'edit-post-new') {
      initNewPostSession()
      return
    }
    if (id === null) {
      loading.value = false
      loadError.value = 'ID de post inválido.'
      return
    }
    loadError.value = null
    void load()
  },
  { immediate: true },
)

watch(
  [images, pendingUrlReplacePostId, isNewPostRoute],
  () => {
    const pid = pendingUrlReplacePostId.value
    if (pid === null || !isNewPostRoute.value) return
    if (images.value.length === 0) return
    if (!images.value.every((i) => i.status === 'done')) return
    pendingUrlReplacePostId.value = null
    void router.replace({ name: 'edit-post', params: { id: String(pid) } })
  },
  { deep: true },
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
        <template v-if="isNewPostRoute">
          Novo post
        </template>
        <template v-else>
          {{ status === 'published' ? 'Edit Post' : 'Edit Draft' }}
        </template>
      </h1>
      <p class="create-post-page__subtitle">
        <template v-if="isNewPostRoute">
          Envie a primeira imagem para criar o rascunho; as demais serão anexadas
          em seguida. Salve ou publique quando estiver pronto.
        </template>
        <template v-else-if="status === 'draft'">
          Continue de onde parou. Salve como rascunho ou publique quando estiver pronto.
        </template>
        <template v-else>
          Refine título, descrição e imagens. Alterações ficam públicas imediatamente.
        </template>
      </p>
    </header>

    <p
      v-if="loadError && !isNewPostRoute"
      class="create-post-page__alert create-post-page__alert--error"
      role="alert"
    >
      <MaterialIcon name="error" filled />
      <span>{{ loadError }}</span>
    </p>

    <template v-else-if="loading && !isNewPostRoute">
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
                :card-actions-disabled="
                  reorderMode || imagePipelineBusy
                "
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
            :disable-secondary-actions="effectivePostId === null"
            :editor-locked="
              reorderMode || applyingReorder || imagePipelineBusy
            "
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
                    :disabled="
                      submitting ||
                      applyingReorder ||
                      imagePipelineBusy
                    "
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
