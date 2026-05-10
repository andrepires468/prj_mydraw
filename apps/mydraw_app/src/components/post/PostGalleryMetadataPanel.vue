<script setup lang="ts">
import { ref } from 'vue'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import type { GalleryRow } from '@/api/posts'

defineProps<{
  galleryRow: GalleryRow
  /** 1-based index label, e.g. "Image 1". */
  imageIndex?: number
}>()

const promptExpanded = ref(false)
const negativeExpanded = ref(false)

const copyHighlight = ref<'prompt' | 'negative' | null>(null)
let copyTimer: ReturnType<typeof setTimeout> | null = null

function formatCfg(v: number | null | undefined): string {
  if (v == null || Number.isNaN(v)) return ''
  return Number.isInteger(v) ? String(v) : v.toFixed(2).replace(/\.?0+$/, '')
}

function loraWeightLabel(w: number | null | undefined): string {
  if (w == null || Number.isNaN(w)) return ''
  return Number.isInteger(w) ? String(w) : String(w)
}

async function copyText(text: string, kind: 'prompt' | 'negative') {
  try {
    await navigator.clipboard.writeText(text)
    copyHighlight.value = kind
    if (copyTimer) clearTimeout(copyTimer)
    copyTimer = setTimeout(() => {
      copyHighlight.value = null
    }, 2000)
  } catch {
    /* clipboard unavailable */
  }
}

/** Toggle expand ao clicar no texto; não interfere se o usuário está selecionando texto. */
function selectionWouldConflict(): boolean {
  const sel = typeof window !== 'undefined'
    ? window.getSelection()?.toString() ?? ''
    : ''
  return sel.length > 0
}

function togglePromptFromTextClick(): void {
  if (selectionWouldConflict()) return
  promptExpanded.value = !promptExpanded.value
}

function toggleNegativeFromTextClick(): void {
  if (selectionWouldConflict()) return
  negativeExpanded.value = !negativeExpanded.value
}

function onPromptTextKeydown(event: KeyboardEvent): void {
  if (event.key !== 'Enter' && event.key !== ' ') return
  event.preventDefault()
  promptExpanded.value = !promptExpanded.value
}

function onNegativeTextKeydown(event: KeyboardEvent): void {
  if (event.key !== 'Enter' && event.key !== ' ') return
  event.preventDefault()
  negativeExpanded.value = !negativeExpanded.value
}
</script>

<template>
  <div class="post-view__metadata-card post-view__metadata-card--inline">
    <h3 v-if="imageIndex != null" class="post-view__metadata-index">
      Image {{ imageIndex }}
    </h3>

    <template v-if="galleryRow.metadata_type === 'stable_diffusion'">
      <div class="post-view__metadata-chips" aria-label="Parameters">
        <span
          v-if="
            galleryRow.metadata_width != null &&
            galleryRow.metadata_height != null
          "
          class="post-view__metadata-chip"
        >
          {{ galleryRow.metadata_width }}×{{ galleryRow.metadata_height }}
        </span>
        <span
          v-if="galleryRow.metadata_steps != null"
          class="post-view__metadata-chip"
        >
          Steps {{ galleryRow.metadata_steps }}
        </span>
        <span
          v-if="galleryRow.metadata_sampler"
          class="post-view__metadata-chip"
          :title="galleryRow.metadata_sampler"
        >
          {{ galleryRow.metadata_sampler }}
        </span>
        <span
          v-if="galleryRow.metadata_cfg_scale != null"
          class="post-view__metadata-chip"
        >
          CFG {{ formatCfg(galleryRow.metadata_cfg_scale) }}
        </span>
        <span
          v-if="galleryRow.metadata_seed != null"
          class="post-view__metadata-chip"
        >
          Seed {{ galleryRow.metadata_seed }}
        </span>
        <span
          v-if="galleryRow.metadata_clip_skip != null"
          class="post-view__metadata-chip"
        >
          Clip skip {{ galleryRow.metadata_clip_skip }}
        </span>
        <span
          v-if="galleryRow.metadata_schedule_type"
          class="post-view__metadata-chip"
        >
          {{ galleryRow.metadata_schedule_type }}
        </span>
      </div>

      <p
        v-if="galleryRow.metadata_model"
        class="post-view__metadata-model"
        :title="galleryRow.metadata_model"
      >
        {{ galleryRow.metadata_model }}
      </p>
      <p
        v-if="galleryRow.metadata_model_hash"
        class="post-view__metadata-hash"
      >
        Model hash {{ galleryRow.metadata_model_hash }}
      </p>
      <p
        v-if="galleryRow.metadata_version"
        class="post-view__metadata-version"
      >
        {{ galleryRow.metadata_version }}
      </p>

      <div
        v-if="galleryRow.metadata_prompt"
        class="post-view__metadata-prompt-block"
      >
        <div class="post-view__metadata-prompt-toolbar">
          <span class="post-view__metadata-prompt-label">Prompt</span>
          <button
            type="button"
            class="post-view__metadata-copy-btn"
            :aria-label="
              copyHighlight === 'prompt'
                ? 'Copied'
                : 'Copy prompt to clipboard'
            "
            @click="copyText(galleryRow.metadata_prompt!, 'prompt')"
          >
            <MaterialIcon
              :name="copyHighlight === 'prompt' ? 'check' : 'content_copy'"
              :filled="copyHighlight === 'prompt'"
              class="post-view__metadata-copy-icon"
            />
          </button>
        </div>
        <p
          class="post-view__metadata-text"
          :class="
            promptExpanded
              ? 'post-view__metadata-text--expanded'
              : 'post-view__metadata-text--prompt-collapsed'
          "
          role="button"
          tabindex="0"
          :aria-expanded="promptExpanded"
          title="Click to expand or collapse"
          @click="togglePromptFromTextClick"
          @keydown="onPromptTextKeydown"
        >
          {{ galleryRow.metadata_prompt }}
        </p>
        <button
          type="button"
          class="post-view__metadata-expand"
          @click="promptExpanded = !promptExpanded"
        >
          {{ promptExpanded ? 'Show less' : 'Show more' }}
        </button>
      </div>

      <div
        v-if="galleryRow.metadata_negative_prompt"
        class="post-view__metadata-prompt-block"
      >
        <div class="post-view__metadata-prompt-toolbar">
          <span class="post-view__metadata-prompt-label">
            Negative prompt
          </span>
          <button
            type="button"
            class="post-view__metadata-copy-btn"
            :aria-label="
              copyHighlight === 'negative'
                ? 'Copied'
                : 'Copy negative prompt to clipboard'
            "
            @click="
              copyText(galleryRow.metadata_negative_prompt!, 'negative')
            "
          >
            <MaterialIcon
              :name="
                copyHighlight === 'negative' ? 'check' : 'content_copy'
              "
              :filled="copyHighlight === 'negative'"
              class="post-view__metadata-copy-icon"
            />
          </button>
        </div>
        <p
          class="post-view__metadata-text"
          :class="
            negativeExpanded
              ? 'post-view__metadata-text--expanded'
              : 'post-view__metadata-text--negative-collapsed'
          "
          role="button"
          tabindex="0"
          :aria-expanded="negativeExpanded"
          title="Click to expand or collapse"
          @click="toggleNegativeFromTextClick"
          @keydown="onNegativeTextKeydown"
        >
          {{ galleryRow.metadata_negative_prompt }}
        </p>
        <button
          type="button"
          class="post-view__metadata-expand"
          @click="negativeExpanded = !negativeExpanded"
        >
          {{ negativeExpanded ? 'Show less' : 'Show more' }}
        </button>
      </div>

      <ul
        v-if="galleryRow.metadata_loras?.length"
        class="post-view__metadata-loras"
        aria-label="LoRAs"
      >
        <li
          v-for="(lora, li) in galleryRow.metadata_loras"
          :key="`${galleryRow.id}-lora-${li}`"
          class="post-view__metadata-lora"
        >
          <span class="post-view__metadata-lora-name">
            {{ lora.name }}
            <template v-if="lora.weight != null">
              ({{ loraWeightLabel(lora.weight) }})
            </template>
          </span>
          <span v-if="lora.hash" class="post-view__metadata-lora-hash">
            {{ lora.hash }}
          </span>
          <span
            v-if="lora.creator"
            class="post-view__metadata-lora-creator"
          >
            <a
              v-if="lora.creator_url"
              :href="lora.creator_url"
              target="_blank"
              rel="noopener noreferrer"
            >
              {{ lora.creator }}
            </a>
            <template v-else>{{ lora.creator }}</template>
          </span>
          <a
            v-if="lora.model_url"
            class="post-view__metadata-lora-link"
            :href="lora.model_url"
            target="_blank"
            rel="noopener noreferrer"
          >
            Model page
          </a>
        </li>
      </ul>
    </template>

    <p v-else class="post-view__metadata-empty">
      No embedded generation metadata.
    </p>
  </div>
</template>
