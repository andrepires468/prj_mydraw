<script setup lang="ts">
import { ref } from 'vue'
import {
  extractGalleryMetadata,
  type ExtractGalleryMetadataResponse,
} from '@/api/posts'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import { getApiErrorMessage } from '@/utils/apiErrorMessage'

const busy = ref(false)
const errorMsg = ref<string | null>(null)
const successMsg = ref<string | null>(null)
const lastSummary = ref<string | null>(null)
/** Quantidade de posts no escopo do lote (metadados + IA nos mesmos IDs). */
const postsPerBatch = ref(1)

function clampPostsLimit(raw: number): number {
  if (!Number.isFinite(raw) || raw < 1) return 1
  return Math.min(100, Math.floor(raw))
}

async function extractAllPendingMetadata() {
  if (busy.value) return
  errorMsg.value = null
  successMsg.value = null
  lastSummary.value = null
  busy.value = true
  try {
    const limit = clampPostsLimit(Number(postsPerBatch.value))
    postsPerBatch.value = limit

    const res: ExtractGalleryMetadataResponse = await extractGalleryMetadata({
      allPosts: true,
      includeAiCategorize: true,
      postsLimit: limit,
    })
    const { summary } = res
    const posts =
      'posts_touched' in res ? res.posts_touched : undefined
    successMsg.value =
      posts != null
        ? `Processed ${summary.total} image(s) across ${posts} post(s).`
        : 'Metadata extraction finished.'
    let detail = `Posts per run: ${limit}. Metadata: updated ${summary.updated}, no embedded data ${summary.no_metadata}, failed ${summary.failed}.`

    if ('batch' in res && res.batch?.scope_post_ids != null) {
      const ids = res.batch.scope_post_ids
      detail += ` Scope (same IDs for metadata + AI): ${ids.length ? ids.join(', ') : 'none pending'}.`
    }

    const ai =
      'ai_categorization_batch' in res ? res.ai_categorization_batch : undefined
    if (ai?.skipped && ai.reason === 'gemini_not_configured') {
      detail += ' AI tags: skipped (Gemini not configured).'
    } else if (ai?.skipped && ai.reason === 'ai_categorization_disabled') {
      detail += ' AI tags: skipped (AI categorization disabled in server config).'
    } else if (ai && !ai.skipped) {
      const s = ai.summary
      detail += ` AI: ${s.posts_with_updates} post(s) updated, ${s.posts_skipped_no_prompt} skipped, ${s.posts_failed} failed.`
      if (ai.errors?.length) {
        const e = ai.errors[0]
        detail += ` First AI error: post ${e.post_id} — ${e.message} (HTTP ${e.http_status}).`
      }
    }
    lastSummary.value = detail
  } catch (err) {
    errorMsg.value = getApiErrorMessage(err)
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
        Each run picks the next pending posts (missing embedded metadata or AI tags), up to the
        count below. For each post: extract SD parameters from pending images, then one Gemini
        call using only the first eligible image prompt to set tags and content rating on the
        post and all pending images in that post. Repeat until no pending posts remain.
      </p>
      <div class="admin-management-page__field-row">
        <label class="admin-management-page__field" for="posts-per-batch">
          <span class="admin-management-page__label">Posts per run</span>
          <input
            id="posts-per-batch"
            v-model.number="postsPerBatch"
            type="number"
            min="1"
            max="100"
            step="1"
            class="admin-management-page__input"
            :disabled="busy"
            autocomplete="off"
          />
        </label>
      </div>
      <button
        type="button"
        class="admin-management-page__primary"
        :disabled="busy"
        @click="extractAllPendingMetadata"
      >
        <span v-if="busy" class="admin-management-page__spinner" aria-hidden="true" />
        {{ busy ? 'Processing…' : 'Run metadata & AI (1 call per post)' }}
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

.admin-management-page__field-row {
  margin-bottom: 1rem;
}

.admin-management-page__field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  max-width: 12rem;
}

.admin-management-page__label {
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: color-mix(in srgb, var(--text-color) 72%, transparent);
}

.admin-management-page__input {
  width: 100%;
  min-height: 2.5rem;
  padding: 0 0.75rem;
  border-radius: 0.65rem;
  border: 1px solid var(--glass-border);
  background: var(--surface-bg, var(--surface-soft-bg));
  color: var(--text-color);
  font-size: 0.95rem;
}

.admin-management-page__input:focus {
  outline: 2px solid color-mix(in srgb, var(--accent-color, #8b5cf6) 55%, transparent);
  outline-offset: 1px;
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
