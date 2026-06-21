<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import {
  categorizePostWithAi,
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
import { getApiErrorMessage } from '@/utils/apiErrorMessage'
import AppConfirmAlert from '@/components/common/AppConfirmAlert.vue'
import AppModal from '@/components/common/AppModal.vue'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import PostEditorUploadStack from '@/components/post/PostEditorUploadStack.vue'
import PostEditorReorderActions from '@/components/post/PostEditorReorderActions.vue'
import ImageQueueGrid from '@/components/post/ImageQueueGrid.vue'
import PostMetadataForm from '@/components/post/PostMetadataForm.vue'
import { useEditPostAsideSummarized } from '@/composables/useEditPostAsideSummarized'
import type { QueuedImage } from '@/components/post/types'

interface Props {
  post_id?: number | string
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
const { asideSummarized } = useEditPostAsideSummarized()
const aiCategorizing = ref(false)
const fileDragActive = ref(false)
let fileDragResetTimer: number | null = null

const pageTitle = computed(() => {
  if (isNewPostRoute.value) return 'New post'
  return status.value === 'published' ? 'Edit Post' : 'Edit Draft'
})

const pageSubtitle = computed(() => {
  if (isNewPostRoute.value) {
    return 'Upload the first image to create your draft; additional images will attach afterward. Save or publish when you are ready.'
  }
  if (status.value === 'draft') {
    return 'Pick up where you left off. Save as draft or publish when ready.'
  }
  return 'Refine the title, description, and images. Changes go live immediately.'
})

const showPanelToggle = computed(
  () => isNewPostRoute.value || (!loadError.value && !loading.value),
)

const reorderActionsLocked = computed(
  () =>
    submitting.value ||
    applyingReorder.value ||
    imagePipelineBusy.value ||
    aiCategorizing.value,
)

const aiCategorizeDisabled = computed(() => effectivePostId.value === null)

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

/** Quando preenchido e todas as imagens estiverem `done`, navega para `/admin/posts/:post_id/edit`. */
const pendingUrlReplacePostId = ref<number | null>(null)
const drainingQueuedUploads = ref(false)
const queuedDrainRequested = ref(false)
const syncingPersistedQueueOrder = ref(false)
const queueOrderSyncRequested = ref(false)

/** Revogar object URLs criadas para previews locais (não as retornadas pela API). */
const locallyCreatedPreviewUrls = new Set<string>()

/** ID numérico do post na rota (`null` em `/admin/posts/new/edit`). */
const postId = computed(() => {
  if (isNewPostRoute.value) return null
  const raw = props.post_id ?? route.params.post_id
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

function isFileDrag(event: DragEvent) {
  return Array.from(event.dataTransfer?.types ?? []).includes('Files')
}

function clearFileDragResetTimer() {
  if (fileDragResetTimer === null) return
  window.clearTimeout(fileDragResetTimer)
  fileDragResetTimer = null
}

function keepFileDragActive(event: DragEvent) {
  if (!isFileDrag(event)) return
  fileDragActive.value = true
  clearFileDragResetTimer()
  fileDragResetTimer = window.setTimeout(() => {
    fileDragActive.value = false
    fileDragResetTimer = null
  }, 160)
}

function onLayoutFileDragOver(event: DragEvent) {
  if (!isFileDrag(event)) return
  event.preventDefault()
  if (event.dataTransfer) {
    event.dataTransfer.dropEffect = uploadAndEditorLocked.value ? 'none' : 'copy'
  }
  keepFileDragActive(event)
}

function resetFileDragActive(event?: DragEvent) {
  if (event && !isFileDrag(event)) return
  event?.preventDefault()
  clearFileDragResetTimer()
  fileDragActive.value = false
}

function extractErrorMessage(err: unknown): string {
  if (axios.isAxiosError(err)) {
    if (err.code === 'ECONNABORTED') {
      return 'Request timed out. Please try again.'
    }
    if (axios.isCancel(err)) {
      return 'Request canceled.'
    }
  }
  return getApiErrorMessage(err)
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
    metadataType: row.metadata_type ?? null,
  }
}

function initNewPostSession() {
  draftUuid.value = crypto.randomUUID()
  createdPostId.value = null
  pendingUrlReplacePostId.value = null
  drainingQueuedUploads.value = false
  queuedDrainRequested.value = false
  syncingPersistedQueueOrder.value = false
  queueOrderSyncRequested.value = false
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
    loadError.value = 'Invalid post ID.'
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
      status: 'queued',
      progress: 0,
    }
    images.value.push(item)
  }
  void flushQueuedWithPostId(pid)
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
      img.metadataType = gallery.metadata_type ?? null
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
  queuedDrainRequested.value = true
  if (drainingQueuedUploads.value) return

  drainingQueuedUploads.value = true
  try {
    while (queuedDrainRequested.value) {
      queuedDrainRequested.value = false
      while (true) {
        const next = images.value.find((i) => i.status === 'queued')
        if (!next) break
        next.status = 'uploading'
        next.progress = 0
        await uploadPostImageForItem(next.id, next.file, postNum)
      }
    }
    await syncPersistedOrderFromQueue(postNum)
  } finally {
    drainingQueuedUploads.value = false
  }
}

async function syncPersistedOrderFromQueue(postNum: number) {
  queueOrderSyncRequested.value = true
  if (syncingPersistedQueueOrder.value) return

  syncingPersistedQueueOrder.value = true
  try {
    while (queueOrderSyncRequested.value) {
      queueOrderSyncRequested.value = false

      if (postNum <= 0) return
      if (reorderMode.value || applyingReorder.value) return
      if (
        images.value.some(
          (i) => i.status === 'uploading' || i.status === 'queued',
        )
      )
        return
      if (images.value.some((i) => i.status === 'error')) return

      const order = images.value
        .map((i) => i.galleryId)
        .filter((id): id is number => id !== undefined)

      if (order.length < 2 || order.length !== images.value.length) return

      try {
        await reorderPostImages(postNum, order)
      } catch (err) {
        const reason = extractErrorMessage(err)
        errorMsg.value = `Images were uploaded, but order could not be synced automatically (${reason}). You can fix this under "Reorder images".`
      }
    }
  } finally {
    syncingPersistedQueueOrder.value = false
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
      img.metadataType = gallery.metadata_type ?? null
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
        'Server response missing post_id. Try uploading the first image again.'
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
      'This post must keep at least one image. Add another before removing this one.'
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
  previewModalAlt.value = img.file.name || 'Preview'
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

async function categorizeWithAi() {
  if (
    aiCategorizing.value ||
    submitting.value ||
    reorderMode.value ||
    applyingReorder.value ||
    imagePipelineBusy.value ||
    effectivePostId.value === null
  )
    return
  clearMessages()
  aiCategorizing.value = true
  try {
    const res = await categorizePostWithAi(effectivePostId.value)
    mergeUpdatedPost(res.post)
    const meta = res.summary.metadata_extraction
    const cat = res.summary.categorization
    const lines: string[] = []
    if (meta) {
      lines.push(
        `Embedded metadata: updated ${meta.updated}, no embedded data ${meta.no_metadata}, failed ${meta.failed}.`,
      )
    }
    if (cat.skipped && cat.reason === 'ai_categorization_disabled') {
      lines.push('AI tags/rating: skipped (AI categorization disabled on server).')
    } else if (cat.skipped && cat.reason === 'gemini_not_configured') {
      lines.push('AI tags/rating: skipped (Gemini not configured).')
    } else {
      lines.push(
        `AI tags/rating: updated ${cat.updated} of ${cat.total_with_prompt} with prompt; skipped (no prompt) ${cat.skipped_missing_prompt}; failed ${cat.failed}.`,
      )
    }
    if (res.errors.length > 0) {
      lines.push(`${res.errors.length} image warning(s) — see API errors if needed.`)
    }
    successMsg.value = lines.join(' ')
  } catch (err) {
    errorMsg.value = extractErrorMessage(err)
  } finally {
    aiCategorizing.value = false
  }
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
    successMsg.value = 'Image order updated.'
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

function mergeUpdatedPost(updated: PostRow) {
  post.value =
    post.value === null ? updated : { ...post.value, ...updated }
  if (updated.images?.length) {
    images.value = updated.images.map(galleryToQueued)
  }
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
    const res = await updatePost(effectivePostId.value, {
      title: title.value.trim(),
      description: description.value.trim() || null,
    })
    mergeUpdatedPost(res.post)
    successMsg.value = 'Draft saved.'
    if (res.metadata_extraction_error) {
      successMsg.value += ` Note: metadata extraction — ${res.metadata_extraction_error}`
    }
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
    const res = await updatePost(pid, payload)
    mergeUpdatedPost(res.post)
    successMsg.value =
      status.value === 'published'
        ? 'Post updated.'
        : 'Post published successfully.'
    if (res.metadata_extraction_error) {
      successMsg.value += ` Note: metadata extraction — ${res.metadata_extraction_error}`
    }
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
    const res = await updatePost(effectivePostId.value, {
      status: 'draft',
    })
    mergeUpdatedPost(res.post)
    successMsg.value = 'Post moved to draft.'
    if (res.metadata_extraction_error) {
      successMsg.value += ` Note: metadata extraction — ${res.metadata_extraction_error}`
    }
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
    successMsg.value = 'Post deleted.'
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
      loadError.value = 'Invalid post ID.'
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
    void router.replace({ name: 'edit-post', params: { post_id: String(pid) } })
  },
  { deep: true },
)

onBeforeUnmount(() => {
  clearFileDragResetTimer()
  for (const url of locallyCreatedPreviewUrls) {
    URL.revokeObjectURL(url)
  }
  locallyCreatedPreviewUrls.clear()
})
</script>

<template>
  <main
    class="app-page-inner create-post-page"
    :class="{ 'create-post-page--aside-summarized': asideSummarized }"
  >
    <header class="create-post-page__header">
      <div class="create-post-page__header-row">
        <div class="create-post-page__header-copy">
          <h1 class="create-post-page__title">{{ pageTitle }}</h1>
          <p class="create-post-page__subtitle">{{ pageSubtitle }}</p>
        </div>
        <button
          v-if="showPanelToggle"
          type="button"
          class="create-post-page__view-toggle"
          :aria-pressed="asideSummarized"
          @click="asideSummarized = !asideSummarized"
        >
          {{
            asideSummarized
              ? 'Show full side panel'
              : 'Collapse side panel'
          }}
        </button>
      </div>
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
      <p class="create-post-page__subtitle">Loading post…</p>
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

      <div
        class="create-post-page__layout"
        :class="{
          'create-post-page__layout--aside-summarized': asideSummarized,
          'create-post-page__layout--file-drag-active': fileDragActive,
        }"
        @dragenter.capture="keepFileDragActive"
        @dragover.capture="onLayoutFileDragOver"
        @drop.capture="resetFileDragActive"
      >
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
                  Drag the thumbnails below to reorder; use the panel on the
                  right to apply or cancel.
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
            :summary-mode="asideSummarized"
            :submitting="submitting"
            :can-publish="canPublish"
            :pending-action="pendingAction"
            :disable-secondary-actions="effectivePostId === null"
            :show-destroy="!isNewPostRoute && effectivePostId !== null"
            :editor-locked="
              reorderMode ||
              applyingReorder ||
              imagePipelineBusy ||
              aiCategorizing
            "
            @update:title="title = $event"
            @update:description="description = $event"
            @publish="publishOrSave"
            @save="saveDraft"
            @unpublish="unpublish"
            @destroy="requestDeletePost"
          >
            <template v-if="images.length > 0" #reorder>
              <PostEditorReorderActions
                :reorder-mode="reorderMode"
                :aside-summarized="asideSummarized"
                :applying-reorder="applyingReorder"
                :can-apply-reorder="canApplyReorder"
                :ai-categorizing="aiCategorizing"
                :actions-locked="reorderActionsLocked"
                :ai-disabled="aiCategorizeDisabled"
                @enter-reorder="enterReorderMode"
                @cancel-reorder="cancelReorderMode"
                @apply-reorder="applyReorder"
                @categorize="categorizeWithAi"
              />
            </template>
          </PostMetadataForm>
        </div>
      </div>
    </template>

    <AppConfirmAlert
      v-if="removeConfirmImageId"
      title="Remove image"
      message="This image will be removed from the post and from the queue. Continue?"
      cancel-label="Cancel"
      confirm-label="Remove"
      confirm-variant="danger"
      @cancel="cancelRemoveImage"
      @confirm="confirmRemoveImage"
    />

    <AppConfirmAlert
      v-if="deletePostConfirmOpen"
      title="Delete post"
      message="This post and all images will be permanently deleted. This cannot be undone."
      confirm-phrase="delete"
      confirm-phrase-label="Type"
      cancel-label="Cancel"
      confirm-label="Delete"
      confirm-variant="danger"
      @cancel="cancelDeletePost"
      @confirm="confirmDeletePost"
    />

    <AppModal
      v-if="previewModalUrl"
      title="Image preview"
      @close="closePreviewModal"
    >
      <template #actions>
        <button
          type="button"
          class="app-modal__edit-post"
          :aria-pressed="previewModalNaturalSize"
          :title="
            previewModalNaturalSize
              ? 'Fit image to visible area'
              : 'View image at actual size (1:1)'
          "
          @click="previewModalNaturalSize = !previewModalNaturalSize"
        >
          {{
            previewModalNaturalSize ? 'Fit to screen' : 'Actual size'
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
