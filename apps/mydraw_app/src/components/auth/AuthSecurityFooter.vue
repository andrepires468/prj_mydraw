<script setup lang="ts">
import MaterialIcon from '@/components/common/MaterialIcon.vue'

interface Item {
  icon: string
  label: string
  filled?: boolean
}

interface Props {
  items?: Item[]
}

withDefaults(defineProps<Props>(), {
  items: () =>
    [
      { icon: 'security', label: '256-bit AES', filled: true },
      { icon: 'fingerprint', label: 'Biometrics Ready' },
    ] satisfies Item[],
})
</script>

<template>
  <div class="auth-security">
    <template v-for="(item, i) in items" :key="item.label">
      <div v-if="i > 0" class="auth-security__divider" aria-hidden="true" />
      <div class="auth-security__item">
        <MaterialIcon
          :name="item.icon"
          :filled="item.filled"
          class="auth-security__icon"
          size="0.875rem"
        />
        <span class="auth-security__label">{{ item.label }}</span>
      </div>
    </template>
  </div>
</template>
