<script setup lang="ts">
import MaterialIcon from '@/components/common/MaterialIcon.vue'

interface Props {
  title?: string
  subtitle?: string
  icon?: string
}

withDefaults(defineProps<Props>(), {
  title: 'mydrow.app',
  subtitle: 'Administrative Gateway',
  icon: 'admin_panel_settings',
})
</script>

<template>
  <div class="auth-brand">
    <div class="auth-brand__icon">
      <MaterialIcon :name="icon" />
    </div>
    <h1 class="auth-brand__title">{{ title }}</h1>
    <p class="auth-brand__subtitle">{{ subtitle }}</p>
  </div>
</template>
