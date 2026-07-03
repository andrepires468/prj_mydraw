<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import {
  listPostsForReorder,
  reorderPosts,
  type PostReorderRow,
} from '@/api/posts'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import LoadingIndicator from '@/components/gallery/LoadingIndicator.vue'
import { getApiErrorMessage } from '@/utils/apiErrorMessage'

const loading = ref(true)
const applying = ref(false)
const errorMsg = ref<string | null>(null)
const successMsg = ref<string | null>(null)
const items = ref<PostReorderRow[]>([])
const draggingIndex = ref<number | null>(null)

const initialOrder = ref<number[]>([])
const hasChanges = computed(() => {
  const current = items.value.map((item) => item.id)
  if (current.length !== initialOrder.value.length) return false
  return current.some((id, index) => id !== initialOrder.value[index])
})

const canApply = computed(
  () => hasChanges.value && !loading.value && !applying.value && items.value.length > 0,
)

function syncOrderNumbers(): void {
  items.value = items.value.map((item, index) => ({
    ...item,
    sort_order: index + 1,
  }))
}

async function loadItems(): Promise<void> {
  loading.value = true
  errorMsg.value = null
  successMsg.value = null
  try {
    const posts = await listPostsForReorder()
    items.value = posts
    initialOrder.value = posts.map((post) => post.id)
  } catch (err) {
    errorMsg.value = getApiErrorMessage(err)
    items.value = []
    initialOrder.value = []
  } finally {
    loading.value = false
  }
}

function onDragStart(index: number): void {
  draggingIndex.value = index
}

function onDragOver(event: DragEvent): void {
  event.preventDefault()
}

function onDrop(event: DragEvent, targetIndex: number): void {
  event.preventDefault()
  const from = draggingIndex.value
  draggingIndex.value = null
  if (from === null || from === targetIndex) return

  const next = items.value.slice()
  const [moved] = next.splice(from, 1)
  if (!moved) return
  next.splice(targetIndex, 0, moved)
  items.value = next
  syncOrderNumbers()
}

function onDragEnd(): void {
  draggingIndex.value = null
}

async function applyReorder(): Promise<void> {
  if (!canApply.value) return
  applying.value = true
  errorMsg.value = null
  successMsg.value = null
  try {
    const order = items.value.map((item) => item.id)
    await reorderPosts(order)
    initialOrder.value = order.slice()
    successMsg.value = 'Gallery order updated.'
  } catch (err) {
    errorMsg.value = getApiErrorMessage(err)
  } finally {
    applying.value = false
  }
}

onMounted(() => {
  void loadItems()
})
</script>

<template>
  <main class="app-page-inner post-reorder-page">
    <header class="post-reorder-page__header">
      <div>
        <h1 class="post-reorder-page__title">Reorder posts</h1>
        <p class="post-reorder-page__subtitle">
          Drag items up or down to change how published posts appear in the
          gallery. Lower order numbers appear first when sorting by newest.
        </p>
      </div>
      <RouterLink
        :to="{ name: 'gallery-published' }"
        class="post-reorder-page__back"
      >
        <MaterialIcon name="arrow_back" size="1.125rem" />
        Back to gallery
      </RouterLink>
    </header>

    <p
      v-if="errorMsg"
      class="post-reorder-page__alert post-reorder-page__alert--error"
      role="alert"
    >
      {{ errorMsg }}
    </p>

    <p
      v-if="successMsg"
      class="post-reorder-page__alert post-reorder-page__alert--success admin-management-page__alert admin-management-page__alert--success"
      role="status"
    >
      {{ successMsg }}
    </p>

    <div v-if="!loading && items.length > 0" class="post-reorder-page__actions">
      <button
        type="button"
        class="post-reorder-page__apply"
        :disabled="!canApply"
        @click="applyReorder"
      >
        Apply reorder
      </button>
      <p class="post-reorder-page__hint">
        {{
          hasChanges
            ? 'You have unsaved order changes.'
            : 'Order matches the saved gallery.'
        }}
      </p>
    </div>

    <LoadingIndicator v-if="loading" label="Loading posts..." />

    <p
      v-else-if="items.length === 0 && !errorMsg"
      class="text-sm text-on-surface-variant italic"
    >
      No published posts to reorder.
    </p>

    <div v-else-if="!loading" class="post-reorder-page__list">
      <div
        v-for="(item, index) in items"
        :key="item.id"
        class="post-reorder-item"
        :class="{ 'post-reorder-item--dragging': draggingIndex === index }"
        draggable="true"
        @dragstart="onDragStart(index)"
        @dragover="onDragOver"
        @drop="onDrop($event, index)"
        @dragend="onDragEnd"
      >
        <span class="post-reorder-item__order" aria-hidden="true">
          {{ item.sort_order }}
        </span>

        <div class="post-reorder-item__cover">
          <img
            v-if="item.cover_url"
            :src="item.cover_url"
            :alt="item.title || 'Post cover'"
            loading="lazy"
            decoding="async"
          />
          <div v-else class="post-reorder-item__cover-fallback">
            <MaterialIcon name="image" size="1.5rem" />
          </div>
        </div>

        <p class="post-reorder-item__title">
          {{ item.title || 'Untitled post' }}
        </p>

        <MaterialIcon
          name="drag_indicator"
          size="1.5rem"
          class="post-reorder-item__handle"
          aria-hidden="true"
        />
      </div>
    </div>

    <div
      v-if="applying"
      class="post-reorder-page__overlay"
      role="status"
      aria-live="polite"
      aria-busy="true"
    >
      <div class="post-reorder-page__overlay-spinner" aria-hidden="true" />
      <p class="post-reorder-page__overlay-label">Saving order…</p>
    </div>
  </main>
</template>

<style src="@/assets/styles/route-post-reorder.css"></style>
