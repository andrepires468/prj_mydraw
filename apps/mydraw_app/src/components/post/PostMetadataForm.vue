<script setup lang="ts">
interface Props {
  title: string
  description: string
  submitting?: boolean
  canPublish?: boolean
}

withDefaults(defineProps<Props>(), {
  submitting: false,
  canPublish: false,
})

defineEmits<{
  'update:title': [value: string]
  'update:description': [value: string]
  publish: []
}>()
</script>

<template>
  <aside class="post-metadata">
    <div class="post-form-field">
      <label for="post-title" class="post-form-field__label">
        Post Title
      </label>
      <input
        id="post-title"
        type="text"
        class="post-form-field__input"
        placeholder="E.g. Summer Architectural Studies"
        :value="title"
        :disabled="submitting"
        @input="$emit('update:title', ($event.target as HTMLInputElement).value)"
      />
    </div>

    <div class="post-form-field">
      <label for="post-description" class="post-form-field__label">
        Description
      </label>
      <textarea
        id="post-description"
        class="post-form-field__textarea"
        rows="6"
        placeholder="Describe the atmosphere and technique..."
        :value="description"
        :disabled="submitting"
        @input="$emit('update:description', ($event.target as HTMLTextAreaElement).value)"
      />
    </div>

    <div class="post-metadata__options">
      <div class="post-metadata__option-row">
        <span class="post-metadata__option-label">Visibility</span>
        <span class="post-metadata__option-value">Public</span>
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
        :disabled="submitting || !canPublish"
        @click="$emit('publish')"
      >
        <span v-if="submitting" class="post-metadata__publish-spinner" />
        <span>{{ submitting ? 'Publishing…' : 'Publish' }}</span>
      </button>
    </div>

    <p class="post-metadata__terms">
      By publishing, you agree to our curator terms of service.
    </p>
  </aside>
</template>
