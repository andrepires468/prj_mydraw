<script setup lang="ts">
import { ref } from 'vue'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import PostGalleryMetadataPanel from '@/components/post/PostGalleryMetadataPanel.vue'
import type { GalleryRow } from '@/api/posts'
import type { PostMediaImage } from './post-media'

interface Props {
  images: PostMediaImage[]
  altText: string
  /**
   * Same length and order as `images`. When provided (list mode), metadata is
   * shown beside each image.
   */
  galleryRows?: (GalleryRow | null)[] | null
}

const props = defineProps<Props>()

const failedIds = ref<Set<number | string>>(new Set())

function onError(id: number | string) {
  failedIds.value = new Set(failedIds.value).add(id)
}

function rowForIndex(i: number): GalleryRow | null {
  const rows = props.galleryRows
  if (!rows || i >= rows.length) return null
  return rows[i] ?? null
}
</script>

<template>
  <div
    class="post-view__list"
    :class="{ 'post-view__list--with-meta': !!galleryRows }"
  >
    <article
      v-for="(img, i) in images"
      :key="img.id"
      class="post-view__list-card"
      :class="{ 'post-view__list-card--with-meta': !!galleryRows }"
    >
      <div class="post-view__list-pair">
        <div class="post-view__list-visual">
          <img
            v-if="!failedIds.has(img.id)"
            class="post-view__list-image"
            :src="img.url"
            :alt="`${altText} (${i + 1})`"
            loading="lazy"
            @error="onError(img.id)"
          />
          <div v-else class="post-view__placeholder">
            <MaterialIcon name="broken_image" />
            <span>Image unavailable</span>
          </div>
        </div>

        <aside
          v-if="galleryRows"
          class="post-view__list-metadata"
          :aria-label="`Metadata for image ${i + 1}`"
        >
          <PostGalleryMetadataPanel
            v-if="rowForIndex(i)"
            :gallery-row="rowForIndex(i)!"
            :image-index="i + 1"
          />
          <div
            v-else
            class="post-view__metadata-card post-view__metadata-card--inline"
          >
            <h3 class="post-view__metadata-index">Image {{ i + 1 }}</h3>
            <p class="post-view__metadata-empty">
              No embedded generation metadata.
            </p>
          </div>
        </aside>
      </div>
    </article>
  </div>
</template>
