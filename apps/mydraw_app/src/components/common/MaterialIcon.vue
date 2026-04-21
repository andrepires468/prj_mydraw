<script setup lang="ts">
interface Props {
  name: string
  filled?: boolean
  size?: string
}

withDefaults(defineProps<Props>(), {
  filled: false,
  size: undefined,
})
</script>

<template>
  <span
    class="material-symbols-outlined"
    :class="{ 'material-symbols-outlined--filled': filled }"
    :style="size ? { fontSize: size } : undefined"
    aria-hidden="true"
  >
    {{ name }}
  </span>
</template>
