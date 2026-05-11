<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()

/** Feed ativo em /gallery/published, /gallery/drafts e modais de post na galeria. */
const galleryFeedActive = computed(() =>
  [
    'gallery-published',
    'gallery-drafts',
    'gallery-published-post',
    'gallery-drafts-post',
  ].includes(route.name as string),
)
</script>

<template>
  <nav class="bottom-nav-bar">
    <RouterLink
      to="/gallery/published"
      class="bottom-nav-bar__link"
      :class="{ 'bottom-nav-bar__link--active': galleryFeedActive }"
    >
      <MaterialIcon name="grid_view" />
      <span class="bottom-nav-bar__label">Feed</span>
    </RouterLink>
    <RouterLink
      v-if="auth.isAdmin"
      to="/admin/posts/new/edit"
      class="bottom-nav-bar__link"
      active-class="bottom-nav-bar__link--active"
    >
      <MaterialIcon name="add_circle" />
      <span class="bottom-nav-bar__label">Create</span>
    </RouterLink>
  </nav>
</template>
