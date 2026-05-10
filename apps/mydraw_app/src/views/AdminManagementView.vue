<script setup lang="ts">
import { ref } from 'vue'
import axios from 'axios'
import {
  extractGalleryMetadata,
  type ExtractGalleryMetadataResponse,
} from '@/api/posts'
import MaterialIcon from '@/components/common/MaterialIcon.vue'

const busy = ref(false)
const errorMsg = ref<string | null>(null)
const successMsg = ref<string | null>(null)
const lastSummary = ref<string | null>(null)

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
        ? 'Session expired. Please sign in again.'
        : 'Failed to process the request.')
    )
  }
  return err instanceof Error ? err.message : 'Unknown error'
}

async function extractAllPendingMetadata() {
  if (busy.value) return
  errorMsg.value = null
  successMsg.value = null
  lastSummary.value = null
  busy.value = true
  try {
    const res: ExtractGalleryMetadataResponse =
      await extractGalleryMetadata({ allPosts: true })
    const { summary } = res
    const posts =
      'posts_touched' in res ? res.posts_touched : undefined
    successMsg.value =
      posts != null
        ? `Processed ${summary.total} image(s) across ${posts} post(s).`
        : 'Metadata extraction finished.'
    lastSummary.value = `Updated ${summary.updated}, no embedded data ${summary.no_metadata}, failed ${summary.failed}.`
  } catch (err) {
    errorMsg.value = extractErrorMessage(err)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="app-page-inner admin-management-page">
    <header class="admin-management-page__header">
      <h1 class="admin-management-page__title">Management</h1>
      <p class="admin-management-page__subtitle">
        Maintenance tools for the gallery.
      </p>
    </header>

    <p
      v-if="errorMsg"
      class="admin-management-page__alert admin-management-page__alert--error"
      role="alert"
    >
      <MaterialIcon name="error" filled />
      <span>{{ errorMsg }}</span>
    </p>
    <p
      v-else-if="successMsg"
      class="admin-management-page__alert admin-management-page__alert--success"
      role="status"
    >
      <MaterialIcon name="check_circle" filled />
      <span>{{ successMsg }}</span>
    </p>

    <section class="admin-management-page__card" aria-labelledby="metadata-heading">
      <h2 id="metadata-heading" class="admin-management-page__card-title">
        Gallery metadata
      </h2>
      <p class="admin-management-page__card-desc">
        Scan every post and extract Stable Diffusion parameters from originals that do not
        yet have metadata stored in the gallery.
      </p>
      <button
        type="button"
        class="admin-management-page__primary"
        :disabled="busy"
        @click="extractAllPendingMetadata"
      >
        <span v-if="busy" class="admin-management-page__spinner" aria-hidden="true" />
        {{ busy ? 'Extracting…' : 'Extract metadata for all pending images' }}
      </button>
      <p v-if="lastSummary" class="admin-management-page__summary">
        {{ lastSummary }}
      </p>
    </section>
  </main>
</template>

<style scoped>
.admin-management-page__header {
  margin-bottom: 1.5rem;
}

.admin-management-page__title {
  font-size: 1.5rem;
  font-weight: 700;
  letter-spacing: -0.02em;
  color: var(--text-color);
}

.admin-management-page__subtitle {
  margin-top: 0.35rem;
  font-size: 0.95rem;
  color: color-mix(in srgb, var(--text-color) 72%, transparent);
}

.admin-management-page__alert {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  padding: 0.75rem 1rem;
  border-radius: 0.75rem;
  margin-bottom: 1rem;
  font-size: 0.9rem;
}

.admin-management-page__alert--error {
  background: color-mix(in srgb, #ef4444 12%, transparent);
  border: 1px solid color-mix(in srgb, #ef4444 35%, transparent);
  color: var(--text-color);
}

.admin-management-page__alert--success {
  background: color-mix(in srgb, #22c55e 12%, transparent);
  border: 1px solid color-mix(in srgb, #22c55e 35%, transparent);
  color: var(--text-color);
}

.admin-management-page__card {
  max-width: 36rem;
  padding: 1.25rem 1.35rem;
  border-radius: 1rem;
  border: 1px solid var(--glass-border);
  background: var(--surface-soft-bg);
}

.admin-management-page__card-title {
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--text-color);
  margin-bottom: 0.35rem;
}

.admin-management-page__card-desc {
  font-size: 0.875rem;
  line-height: 1.5;
  color: color-mix(in srgb, var(--text-color) 78%, transparent);
  margin-bottom: 1rem;
}

.admin-management-page__primary {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  min-height: 2.75rem;
  padding: 0 1.15rem;
  border-radius: 9999px;
  border: 1px solid color-mix(in srgb, var(--accent-color, #8b5cf6) 45%, var(--glass-border));
  background: color-mix(in srgb, var(--accent-color, #8b5cf6) 14%, var(--surface-soft-bg));
  color: var(--text-color);
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  cursor: pointer;
  transition:
    background-color 0.2s ease,
    border-color 0.2s ease;
}

.admin-management-page__primary:hover:not(:disabled) {
  border-color: var(--accent-color, #8b5cf6);
  background: color-mix(in srgb, var(--accent-color, #8b5cf6) 22%, var(--surface-soft-bg));
}

.admin-management-page__primary:disabled {
  opacity: 0.65;
  cursor: not-allowed;
}

.admin-management-page__spinner {
  width: 1rem;
  height: 1rem;
  border: 2px solid rgb(255 255 255 / 25%);
  border-top-color: currentColor;
  border-radius: 9999px;
  animation: admin-mgmt-spin 0.75s linear infinite;
}

@keyframes admin-mgmt-spin {
  to {
    transform: rotate(360deg);
  }
}

.admin-management-page__summary {
  margin-top: 1rem;
  font-size: 0.85rem;
  color: color-mix(in srgb, var(--text-color) 75%, transparent);
}
</style>
