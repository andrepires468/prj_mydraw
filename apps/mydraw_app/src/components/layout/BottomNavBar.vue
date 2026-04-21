<script setup lang="ts">
import { RouterLink } from 'vue-router'
import MaterialIcon from '@/components/common/MaterialIcon.vue'

interface NavItem {
  to: string
  icon: string
  label: string
}

const items: NavItem[] = [
  { to: '/gallery', icon: 'grid_view', label: 'Feed' },
  { to: '/create', icon: 'add_circle', label: 'Create' },
]
</script>

<template>
  <nav class="bottom-nav-bar">
    <RouterLink
      v-for="item in items"
      :key="item.to"
      :to="item.to"
      class="bottom-nav-bar__link"
      active-class="bottom-nav-bar__link--active"
    >
      <MaterialIcon :name="item.icon" />
      <span class="bottom-nav-bar__label">{{ item.label }}</span>
    </RouterLink>
  </nav>
</template>
