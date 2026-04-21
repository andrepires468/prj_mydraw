<script setup lang="ts">
defineProps<{
  tag?: string
}>()
</script>

<template>
  <component :is="tag ?? 'div'" class="masonry-grid">
    <slot />
  </component>
</template>
