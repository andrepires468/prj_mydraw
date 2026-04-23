<script setup lang="ts">
import { computed } from 'vue'

type Mode = 'create' | 'edit'

interface Props {
  title: string
  description: string
  summaryMode?: boolean
  submitting?: boolean
  canPublish?: boolean
  /**
   * `create` exibe apenas "Publish" (padrão do fluxo de criação).
   * `edit` exibe "Save Draft" + "Publish" (ou "Unpublish" quando já publicado)
   * além de permitir excluir o post.
   */
  mode?: Mode
  /**
   * Status atual do post quando em modo `edit`.
   */
  status?: 'draft' | 'published'
  /**
   * Indica se uma ação específica está em andamento (opcional).
   */
  pendingAction?: 'save' | 'publish' | 'unpublish' | 'delete' | null
  /** Bloqueia título, descrição e botões (ex.: modo de reordenação da fila). */
  editorLocked?: boolean
  /**
   * Desativa salvar, despublicar e excluir (ex.: ainda não existe `post_id` no servidor).
   */
  disableSecondaryActions?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  summaryMode: false,
  submitting: false,
  canPublish: false,
  mode: 'create',
  status: 'draft',
  pendingAction: null,
  editorLocked: false,
  disableSecondaryActions: false,
})

defineEmits<{
  'update:title': [value: string]
  'update:description': [value: string]
  publish: []
  save: []
  unpublish: []
  destroy: []
}>()

const publishLabel = computed(() => {
  if (props.mode === 'create') {
    return props.submitting ? 'Publishing…' : 'Publish'
  }
  if (props.status === 'published') {
    return props.pendingAction === 'publish' ? 'Saving…' : 'Save'
  }
  return props.pendingAction === 'publish' ? 'Publishing…' : 'Publish'
})

const formDisabled = computed(
  () => props.submitting || props.editorLocked,
)

const isPublishBusy = computed(
  () =>
    props.submitting ||
    props.pendingAction === 'publish',
)

const isSaveBusy = computed(() => props.pendingAction === 'save')
const isDeleteBusy = computed(() => props.pendingAction === 'delete')
</script>

<template>
  <aside class="post-metadata">
    <div v-if="!summaryMode" class="post-form-field">
      <label for="post-title" class="post-form-field__label">
        Post Title
      </label>
      <input
        id="post-title"
        type="text"
        class="post-form-field__input"
        placeholder="E.g. Summer Architectural Studies"
        :value="title"
        :disabled="formDisabled"
        @input="$emit('update:title', ($event.target as HTMLInputElement).value)"
      />
    </div>

    <div v-if="!summaryMode" class="post-form-field">
      <label for="post-description" class="post-form-field__label">
        Description
      </label>
      <textarea
        id="post-description"
        class="post-form-field__textarea"
        rows="6"
        placeholder="Describe the atmosphere and technique..."
        :value="description"
        :disabled="formDisabled"
        @input="$emit('update:description', ($event.target as HTMLTextAreaElement).value)"
      />
    </div>

    <div v-if="$slots.reorder" class="post-metadata__reorder">
      <slot name="reorder" />
    </div>

    <div v-if="!summaryMode" class="post-metadata__options">
      <div class="post-metadata__option-row">
        <span class="post-metadata__option-label">Visibility</span>
        <span class="post-metadata__option-value">
          {{ mode === 'edit' && status === 'draft' ? 'Draft (private)' : 'Public' }}
        </span>
      </div>
      <div class="post-metadata__option-row">
        <span class="post-metadata__option-label">Auto-Archive</span>
        <span class="post-metadata__option-value post-metadata__option-value--muted">
          Disabled
        </span>
      </div>
    </div>

    <div class="post-metadata__actions">
      <button
        type="button"
        class="post-metadata__publish"
        :disabled="formDisabled || !canPublish"
        @click="$emit('publish')"
      >
        <span v-if="isPublishBusy" class="post-metadata__publish-spinner" />
        <span>{{ publishLabel }}</span>
      </button>

      <div
        v-if="mode === 'edit' && !summaryMode"
        class="post-metadata__secondary-actions"
      >
        <button
          type="button"
          class="post-metadata__secondary"
          :disabled="formDisabled || disableSecondaryActions"
          @click="$emit('save')"
        >
          <span v-if="isSaveBusy" class="post-metadata__publish-spinner" />
          <span>{{ isSaveBusy ? 'Saving…' : 'Save draft' }}</span>
        </button>

        <button
          v-if="status === 'published'"
          type="button"
          class="post-metadata__secondary"
          :disabled="formDisabled || disableSecondaryActions"
          @click="$emit('unpublish')"
        >
          <span>{{ pendingAction === 'unpublish' ? 'Unpublishing…' : 'Unpublish' }}</span>
        </button>

        <button
          type="button"
          class="post-metadata__danger"
          :disabled="formDisabled || disableSecondaryActions"
          @click="$emit('destroy')"
        >
          <span v-if="isDeleteBusy" class="post-metadata__publish-spinner" />
          <span>{{ isDeleteBusy ? 'Deleting…' : 'Delete post' }}</span>
        </button>
      </div>
    </div>

    <p v-if="mode === 'create' && !summaryMode" class="post-metadata__terms">
      By publishing, you agree to our curator terms of service.
    </p>
    <p v-else-if="!summaryMode" class="post-metadata__terms">
      Drafts are only visible to you until you publish.
    </p>
  </aside>
</template>
