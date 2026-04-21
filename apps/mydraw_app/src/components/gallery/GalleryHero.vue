<script setup lang="ts">
interface Props {
  title?: string
  subtitle?: string
}

withDefaults(defineProps<Props>(), {
  title: 'Atmospheric\nCurations',
  subtitle:
    'A sensory journey through high-fidelity digital art and curated photography. Exploring the fluid boundaries of light and form.',
})
</script>

<template>
  <header class="gallery-hero__text">
    <h1 class="gallery-hero__title">
      <template v-for="(line, i) in title.split('\n')" :key="i">
        {{ line }}<br v-if="i < title.split('\n').length - 1" />
      </template>
    </h1>
    <p class="gallery-hero__subtitle">{{ subtitle }}</p>
  </header>
</template>
