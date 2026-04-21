<script setup lang="ts">
import { computed, useSlots } from 'vue'
import MaterialIcon from '@/components/common/MaterialIcon.vue'

interface Props {
  id: string
  label: string
  icon: string
  modelValue: string
  type?: string
  placeholder?: string
  autocomplete?: string
  required?: boolean
  disabled?: boolean
  minlength?: number
}

withDefaults(defineProps<Props>(), {
  type: 'text',
  placeholder: '',
  autocomplete: undefined,
  required: false,
  disabled: false,
  minlength: undefined,
})

defineEmits<{
  'update:modelValue': [value: string]
}>()

const slots = useSlots()
const hasAside = computed(() => !!slots.aside)
const hasSuffix = computed(() => !!slots.suffix)
</script>

<template>
  <div class="auth-field">
    <div v-if="hasAside" class="auth-field__label-row">
      <label class="auth-field__label" :for="id">{{ label }}</label>
      <slot name="aside" />
    </div>
    <label v-else class="auth-field__label" :for="id">{{ label }}</label>

    <div class="auth-field__input-wrapper">
      <span class="auth-field__icon">
        <MaterialIcon :name="icon" size="1.25rem" />
      </span>

      <input
        :id="id"
        :type="type"
        :value="modelValue"
        :placeholder="placeholder"
        :autocomplete="autocomplete"
        :required="required"
        :disabled="disabled"
        :minlength="minlength"
        class="auth-field__input"
        :class="{ 'auth-field__input--has-suffix': hasSuffix }"
        @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
      />

      <slot name="suffix" />
    </div>
  </div>
</template>
