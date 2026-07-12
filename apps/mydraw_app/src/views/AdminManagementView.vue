<script setup lang="ts">
import { computed, ref } from 'vue'
import {
  extractGalleryMetadata,
  type ExtractGalleryMetadataResponse,
} from '@/api/posts'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import { getApiErrorMessage } from '@/utils/apiErrorMessage'
import type { ContentRating } from '@/services/PostService'

/** Civitai-style ratings used by AI categorization (`ContentRating` / Gemini). */
const CONTENT_RATING_LEGEND: ReadonlyArray<{
  rating: ContentRating
  description: string
}> = [
  {
    rating: 'PG',
    description: 'Generally safe; non-sexual; mild fantasy or action.',
  },
  {
    rating: 'PG-13',
    description: 'Suggestive themes or moderate violence; no explicit sexual acts.',
  },
  {
    rating: 'R',
    description: 'Strong violence or clearly sexual themes without graphic portrayal.',
  },
  {
    rating: 'X',
    description: 'Explicit sexual content without extreme or taboo framing.',
  },
  {
    rating: 'XXX',
    description: 'Extreme or taboo sexual content when explicitly described.',
  },
]

const busy = ref(false)
const errorMsg = ref<string | null>(null)
const successMsg = ref<string | null>(null)
const lastSummary = ref<string | null>(null)
/** Post ID obrigatório para extrair metadados + categorizar com IA. */
const postIdInput = ref<number | null>(null)

const parsedPostId = computed(() => {
  const raw = Number(postIdInput.value)
  if (!Number.isFinite(raw) || raw < 1) return null
  return Math.floor(raw)
})

const canRun = computed(() => parsedPostId.value !== null && !busy.value)

async function runForPost() {
  if (busy.value) return
  const postId = parsedPostId.value
  if (postId === null) {
    errorMsg.value = 'Post ID is required.'
    successMsg.value = null
    lastSummary.value = null
    return
  }

  errorMsg.value = null
  successMsg.value = null
  lastSummary.value = null
  busy.value = true
  try {
    const res: ExtractGalleryMetadataResponse = await extractGalleryMetadata({
      postId,
      includeAiCategorize: true,
    })
    const { summary } = res
    successMsg.value = `Processed post ${postId}: ${summary.total} image(s).`
    let detail = `Post ${postId}. Metadata: updated ${summary.updated}, no embedded data ${summary.no_metadata}, failed ${summary.failed}.`

    const ai = res.ai_categorization_batch
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
        Enter a post ID, then run extraction for that post only: pull SD parameters from images
        still missing embedded metadata, then one Gemini call (first eligible image prompt) to
        set tags and content rating on the post and its pending images.
      </p>
      <aside
        class="admin-management-page__ratings"
        aria-labelledby="ratings-legend-heading"
      >
        <h3 id="ratings-legend-heading" class="admin-management-page__ratings-title">
          Content ratings
        </h3>
        <ul class="admin-management-page__ratings-list">
          <li
            v-for="item in CONTENT_RATING_LEGEND"
            :key="item.rating"
            class="admin-management-page__ratings-item"
          >
            <span class="admin-management-page__ratings-badge">{{ item.rating }}</span>
            <span class="admin-management-page__ratings-desc">{{ item.description }}</span>
          </li>
        </ul>
      </aside>
      <div class="admin-management-page__field-row">
        <label class="admin-management-page__field" for="post-id">
          <span class="admin-management-page__label">Post ID (required)</span>
          <input
            id="post-id"
            v-model.number="postIdInput"
            type="number"
            min="1"
            step="1"
            required
            class="admin-management-page__input"
            :disabled="busy"
            autocomplete="off"
            placeholder="e.g. 42"
          />
        </label>
      </div>
      <button
        type="button"
        class="admin-management-page__primary"
        :disabled="!canRun"
        @click="runForPost"
      >
        <span v-if="busy" class="admin-management-page__spinner" aria-hidden="true" />
        {{ busy ? 'Processing…' : 'Run metadata & AI for post' }}
      </button>
      <p v-if="lastSummary" class="admin-management-page__summary">
        {{ lastSummary }}
      </p>
    </section>
  </main>
</template>

<style src="@/assets/styles/route-admin-management.css"></style>
