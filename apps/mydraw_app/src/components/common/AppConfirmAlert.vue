<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue'

interface Props {
  title: string
  message: string
  cancelLabel?: string
  confirmLabel?: string
  /** Estilo do botão de confirmação (ação destrutiva → danger). */
  confirmVariant?: 'danger' | 'primary'
  /**
   * Quando definido, exige digitar exatamente este texto para habilitar a confirmação.
   */
  confirmPhrase?: string
  confirmPhraseLabel?: string
  confirmPhrasePlaceholder?: string
}

const props = withDefaults(defineProps<Props>(), {
  cancelLabel: 'Cancel',
  confirmLabel: 'Confirm',
  confirmVariant: 'primary',
  confirmPhraseLabel: 'Type the word below to confirm',
  confirmPhrasePlaceholder: '',
})

const emit = defineEmits<{
  confirm: []
  cancel: []
}>()

const titleId = useId()
const descId = useId()
const phraseInputId = useId()
const phraseInput = ref('')

const confirmBtnClass = computed(() =>
  props.confirmVariant === 'danger'
    ? 'app-confirm-alert__btn--danger'
    : 'app-confirm-alert__btn--primary',
)

const confirmEnabled = computed(() => {
  if (!props.confirmPhrase) return true
  return phraseInput.value.trim() === props.confirmPhrase
})

let previousOverflow = ''

function handleKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    emit('cancel')
  }
}

function handleConfirm() {
  if (!confirmEnabled.value) return
  emit('confirm')
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

        <div v-if="confirmPhrase" class="app-confirm-alert__phrase">
          <label :for="phraseInputId" class="app-confirm-alert__phrase-label">
            {{ confirmPhraseLabel }}
            <span class="app-confirm-alert__phrase-word">{{ confirmPhrase }}</span>
          </label>
          <input
            :id="phraseInputId"
            v-model="phraseInput"
            type="text"
            class="app-confirm-alert__phrase-input"
            :placeholder="confirmPhrasePlaceholder || confirmPhrase"
            autocomplete="off"
            spellcheck="false"
            @keydown.enter.prevent="handleConfirm"
          />
        </div>

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
            :disabled="!confirmEnabled"
            @click="handleConfirm"
          >
            {{ confirmLabel }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
