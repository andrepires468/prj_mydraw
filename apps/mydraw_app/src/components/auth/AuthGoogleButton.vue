<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, nextTick, computed } from 'vue'
import {
  isGoogleSignInConfigured,
  mountGoogleSignInButton,
  setGoogleCredentialHandler,
  signInWithGoogleNative,
  formatGoogleSignInError,
  usesNativeGoogleSignIn,
} from '@/utils/googleIdentity'

interface Props {
  disabled?: boolean
  loading?: boolean
  /** Texto do botão nativo (Android/iOS). */
  nativeLabel?: string
}

const props = withDefaults(defineProps<Props>(), {
  disabled: false,
  loading: false,
  nativeLabel: 'Continue with Google',
})

const emit = defineEmits<{
  credential: [credential: string]
  error: [message: string]
}>()

const googleMountEl = ref<HTMLElement | null>(null)
const showGoogle = ref(isGoogleSignInConfigured())
const useNativeButton = computed(() => usesNativeGoogleSignIn())
const nativeBusy = ref(false)

async function onNativeClick() {
  if (props.disabled || props.loading || nativeBusy.value) return
  nativeBusy.value = true
  try {
    const credential = await signInWithGoogleNative()
    if (credential) {
      emit('credential', credential)
      return
    }
  } catch (err: unknown) {
    const message = formatGoogleSignInError(err)
    if (message) {
      emit('error', message)
    }
  } finally {
    nativeBusy.value = false
  }
}

onMounted(async () => {
  if (!showGoogle.value || useNativeButton.value) return

  await nextTick()
  if (!googleMountEl.value) return

  setGoogleCredentialHandler((credential) => emit('credential', credential))
  await mountGoogleSignInButton(googleMountEl.value)
})

onBeforeUnmount(() => {
  setGoogleCredentialHandler(undefined)
})
</script>

<template>
  <template v-if="showGoogle">
    <div class="auth-divider" aria-hidden="true">or</div>

    <button
      v-if="useNativeButton"
      type="button"
      class="google-sign-in-native"
      :disabled="disabled || loading || nativeBusy"
      :aria-busy="nativeBusy"
      @click="onNativeClick"
    >
      <svg
        class="google-sign-in-native__icon"
        viewBox="0 0 24 24"
        aria-hidden="true"
        focusable="false"
      >
        <path
          fill="#4285F4"
          d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"
        />
        <path
          fill="#34A853"
          d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
        />
        <path
          fill="#FBBC05"
          d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z"
        />
        <path
          fill="#EA4335"
          d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
        />
      </svg>
      <span>{{ nativeBusy ? 'Signing in…' : nativeLabel }}</span>
    </button>

    <div
      v-else
      ref="googleMountEl"
      class="google-sign-in-mount"
      aria-label="Sign in with Google"
    />
  </template>
</template>
