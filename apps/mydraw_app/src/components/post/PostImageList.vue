<script setup lang="ts">
import { ref } from 'vue'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import type { PostMediaImage } from './post-media'

interface Props {
  images: PostMediaImage[]
  altText: string
}

defineProps<Props>()

const failedIds = ref<Set<number | string>>(new Set())

function onError(id: number | string) {
  failedIds.value = new Set(failedIds.value).add(id)
}
</script>

<template>
  <div class="post-view__list">
    <article
      v-for="(img, i) in images"
      :key="img.id"
      class="post-view__list-card"
    >
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
        <span>Imagem indisponível</span>
      </div>
    </article>
  </div>
</template>
