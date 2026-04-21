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

onMounted(() => {
  if (auth.isAuthenticated) {
    void auth.fetchMe()
  }
})
</script>

<template>
  <div class="min-h-screen flex flex-col bg-background">
    <TopNavBar v-if="!isAuthRoute" />

    <RouterView />

    <template v-if="!isAuthRoute">
      <BottomNavBar />
      <FloatingActionButton aria-label="Criar novo desenho" />
    </template>
  </div>
</template>
