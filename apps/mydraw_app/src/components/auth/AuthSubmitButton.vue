<script setup lang="ts">
import MaterialIcon from '@/components/common/MaterialIcon.vue'

interface Props {
  label: string
  loadingLabel?: string
  loading?: boolean
  disabled?: boolean
  icon?: string
}

withDefaults(defineProps<Props>(), {
  loadingLabel: 'Processando…',
  loading: false,
  disabled: false,
  icon: 'arrow_forward',
})
</script>

<template>
  <button
    type="submit"
    class="auth-button"
    :disabled="loading || disabled"
  >
    <template v-if="loading">
      <span class="auth-button__spinner" aria-hidden="true" />
      <span>{{ loadingLabel }}</span>
    </template>
    <template v-else>
      <span>{{ label }}</span>
      <MaterialIcon :name="icon" class="auth-button__icon" />
    </template>
  </button>
</template>
