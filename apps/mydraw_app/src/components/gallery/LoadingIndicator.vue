<script setup lang="ts">
interface Props {
  label?: string
}

withDefaults(defineProps<Props>(), {
  label: 'Gathering more inspirations...',
})
</script>

<template>
  <div class="loading-indicator" role="status" aria-live="polite">
    <div class="loading-indicator__spinner" />
    <p class="loading-indicator__label">{{ label }}</p>
  </div>
</template>
