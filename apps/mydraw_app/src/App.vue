<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import TopNavBar from '@/components/layout/TopNavBar.vue'
import BottomNavBar from '@/components/layout/BottomNavBar.vue'
import FloatingActionButton from '@/components/layout/FloatingActionButton.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()

const isAuthRoute = computed(() => route.meta?.guestOnly === true)

/** Evita remontar o editor ao trocar só a URI (`/posts/new/edit` → `/posts/:post_id/edit`). */
const routerViewKey = computed(() => {
  const n = route.name
  if (n === 'edit-post-new' || n === 'edit-post') return 'edit-post-view'
  return route.path
})

onMounted(() => {
  if (auth.isAuthenticated) {
    void auth.fetchMe()
  }
})
</script>

<template>
  <div class="min-h-screen flex flex-col bg-background">
    <TopNavBar v-if="!isAuthRoute" />

    <RouterView :key="routerViewKey" />

    <template v-if="!isAuthRoute">
      <BottomNavBar />
      <FloatingActionButton aria-label="Create new drawing" />
    </template>
  </div>
</template>
