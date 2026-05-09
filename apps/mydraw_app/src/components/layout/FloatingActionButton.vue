<script setup lang="ts">
import MaterialIcon from '@/components/common/MaterialIcon.vue'

interface Props {
  icon?: string
  ariaLabel?: string
}

withDefaults(defineProps<Props>(), {
  icon: 'add',
  ariaLabel: 'Create new',
})

defineEmits<{ click: [] }>()
</script>

<template>
  <button
    type="button"
    class="fab"
    :aria-label="ariaLabel"
    @click="$emit('click')"
  >
    <MaterialIcon :name="icon" filled />
  </button>
</template>
