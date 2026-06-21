<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { RouterView, useRoute, useRouter } from 'vue-router'
import TopNavBar from '@/components/layout/TopNavBar.vue'
import BottomNavBar from '@/components/layout/BottomNavBar.vue'
import FloatingActionButton from '@/components/layout/FloatingActionButton.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

function goToNewPost(): void {
  void router.push({ name: 'edit-post-new' })
}

const isAuthRoute = computed(() => route.meta?.guestOnly === true)

/** Evita remontar o editor ao trocar só a URI (`/admin/posts/new/edit` → `/admin/posts/:post_id/edit`). */
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
      <FloatingActionButton
        v-if="auth.isAdmin"
        aria-label="Create new drawing"
        @click="goToNewPost"
      />
    </template>
  </div>
</template>
