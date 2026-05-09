<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue'
import MaterialIcon from '@/components/common/MaterialIcon.vue'

interface Props {
  title?: string
}

const props = withDefaults(defineProps<Props>(), {
  title: '',
})

const emit = defineEmits<{
  close: []
}>()

let previousOverflow = ''

function handleKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    emit('close')
  }
}

onMounted(() => {
  previousOverflow = document.body.style.overflow
  document.body.style.overflow = 'hidden'
  document.addEventListener('keydown', handleKeydown)
})

onBeforeUnmount(() => {
  document.body.style.overflow = previousOverflow
  document.removeEventListener('keydown', handleKeydown)
})
</script>

<template>
  <Teleport to="body">
    <div
      class="app-modal"
      role="presentation"
      @click.self="$emit('close')"
    >
      <section
        class="app-modal__panel"
        role="dialog"
        aria-modal="true"
        :aria-label="props.title || 'Post preview'"
      >
        <header class="app-modal__header">
          <h2 v-if="props.title" class="app-modal__title">
            {{ props.title }}
          </h2>
          <div class="app-modal__actions">
            <slot name="actions" />
            <button
              type="button"
              class="app-modal__icon-button"
              aria-label="Close"
              @click="$emit('close')"
            >
              <MaterialIcon name="close" />
            </button>
          </div>
        </header>

        <div class="app-modal__body">
          <slot />
        </div>
      </section>
    </div>
  </Teleport>
</template>
