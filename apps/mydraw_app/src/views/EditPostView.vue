<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'
import {
  deletePost,
  deletePostImage,
  getPost,
  updatePost,
  uploadPostImage,
  type GalleryRow,
  type PostRow,
} from '@/api/posts'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import UploadDropzone from '@/components/post/UploadDropzone.vue'
import ImageQueueGrid from '@/components/post/ImageQueueGrid.vue'
import PostMetadataForm from '@/components/post/PostMetadataForm.vue'
import type { QueuedImage } from '@/components/post/types'

interface Props {
  id: number | string
}

const props = defineProps<Props>()

const router = useRouter()

const MAX_FILES = 10

const post = ref<PostRow | null>(null)
const title = ref('')
const description = ref('')
const images = ref<QueuedImage[]>([])

const loading = ref(true)
const loadError = ref<string | null>(null)

const errorMsg = ref<string | null>(null)
const successMsg = ref<string | null>(null)

const pendingAction = ref<
  'save' | 'publish' | 'unpublish' | 'delete' | null
>(null)
const submitting = computed(() => pendingAction.value !== null)

/** Revogar object URLs criadas para previews locais (não as retornadas pela API). */
const locallyCreatedPreviewUrls = new Set<string>()

const postId = computed(() => {
  const raw = typeof props.id === 'string' ? parseInt(props.id, 10) : props.id
  return Number.isFinite(raw) ? raw : null
})

const status = computed<'draft' | 'published'>(
  () => post.value?.status ?? 'draft',
)

/** Regras para habilitar o botão principal. */
const canPublish = computed(() => {
  if (submitting.value) return false
  const anyUploading = images.value.some((i) => i.status === 'uploading')
  if (anyUploading) return false
  if (images.value.length === 0) return false
  if (title.value.trim().length === 0) return false
  return true
})

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
      .map(galleryToQueued)
  } catch (err) {
    loadError.value = extractErrorMessage(err)
  } finally {
    loading.value = false
  }
}

function addFiles(files: File[]) {
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

async function removeImage(localId: string) {
  if (!postId.value) return
  const idx = images.value.findIndex((i) => i.id === localId)
  if (idx < 0) return
  const img = images.value[idx]
  if (img.status === 'uploading') return

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
  const img = images.value.find((i) => i.id === localId)
  if (img?.previewUrl) window.open(img.previewUrl, '_blank')
}

function reorder(from: number, to: number) {
  // Reordenação local apenas; persistência de `position` não é escopo desta
  // tela ainda, mas fica o hook pronto para endpoint futuro.
  const [moved] = images.value.splice(from, 1)
  images.value.splice(to, 0, moved)
}

function clearMessages() {
  errorMsg.value = null
  successMsg.value = null
}

async function saveDraft() {
  if (submitting.value || !postId.value) return
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
  if (!canPublish.value || !postId.value) return
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
  if (submitting.value || !postId.value) return
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

async function destroy() {
  if (submitting.value || !postId.value) return
  if (!window.confirm('Excluir este post e todas as imagens? Esta ação é irreversível.')) {
    return
  }
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

onMounted(() => {
  void load()
})

onBeforeUnmount(() => {
  for (const url of locallyCreatedPreviewUrls) {
    URL.revokeObjectURL(url)
  }
  locallyCreatedPreviewUrls.clear()
})
</script>

<template>
  <main class="create-post-page">
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
            mode="edit"
            :status="status"
            :title="title"
            :description="description"
            :submitting="submitting"
            :can-publish="canPublish"
            :pending-action="pendingAction"
            @update:title="title = $event"
            @update:description="description = $event"
            @publish="publishOrSave"
            @save="saveDraft"
            @unpublish="unpublish"
            @destroy="destroy"
          />
        </div>
      </div>
    </template>
  </main>
</template>
