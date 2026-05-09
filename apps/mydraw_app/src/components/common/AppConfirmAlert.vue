<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, useId } from 'vue'

interface Props {
  title: string
  message: string
  cancelLabel?: string
  confirmLabel?: string
  /** Estilo do botão de confirmação (ação destrutiva → danger). */
  confirmVariant?: 'danger' | 'primary'
}

const props = withDefaults(defineProps<Props>(), {
  cancelLabel: 'Cancel',
  confirmLabel: 'Confirm',
  confirmVariant: 'primary',
})

const emit = defineEmits<{
  confirm: []
  cancel: []
}>()

const titleId = useId()
const descId = useId()

const confirmBtnClass = computed(() =>
  props.confirmVariant === 'danger'
    ? 'app-confirm-alert__btn--danger'
    : 'app-confirm-alert__btn--primary',
)

let previousOverflow = ''

function handleKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    emit('cancel')
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
      class="app-confirm-alert"
      role="presentation"
      @click.self="$emit('cancel')"
    >
      <div
        class="app-confirm-alert__panel"
        role="alertdialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        :aria-describedby="descId"
      >
        <h2 :id="titleId" class="app-confirm-alert__title">
          {{ title }}
        </h2>
        <p :id="descId" class="app-confirm-alert__message">
          {{ message }}
        </p>
        <div class="app-confirm-alert__actions">
          <button
            type="button"
            class="app-confirm-alert__btn app-confirm-alert__btn--secondary"
            @click="$emit('cancel')"
          >
            {{ cancelLabel }}
          </button>
          <button
            type="button"
            class="app-confirm-alert__btn"
            :class="confirmBtnClass"
            @click="$emit('confirm')"
          >
            {{ confirmLabel }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
