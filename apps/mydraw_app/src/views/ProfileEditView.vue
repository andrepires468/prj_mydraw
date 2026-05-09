<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'
import { useAuthStore } from '@/stores/auth'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import AuthBrand from '@/components/auth/AuthBrand.vue'
import AuthField from '@/components/auth/AuthField.vue'
import AuthSubmitButton from '@/components/auth/AuthSubmitButton.vue'
import {
  NICKNAME_MAX_LEN,
  NICKNAME_MIN_LEN,
  isValidNickname,
  sanitizeNicknameKeystrokes,
} from '@/utils/nickname'

const auth = useAuthStore()
const router = useRouter()

const nickname = ref('')
const loading = ref(false)
const errorMsg = ref<string | null>(null)

function syncFromUser() {
  const raw = auth.user?.name ?? ''
  nickname.value = sanitizeNicknameKeystrokes(raw).slice(0, NICKNAME_MAX_LEN)
}

onMounted(async () => {
  if (!auth.isAuthenticated) {
    void router.replace({ path: '/login', query: { redirect: '/profile' } })
    return
  }
  if (!auth.user) {
    await auth.fetchMe()
  }
  if (!auth.isAuthenticated || !auth.user) {
    void router.replace({ path: '/login', query: { redirect: '/profile' } })
    return
  }
  syncFromUser()
})

function onNicknameInput(value: string) {
  nickname.value = sanitizeNicknameKeystrokes(value).slice(0, NICKNAME_MAX_LEN)
}

const hintInvalid = computed(() => {
  const v = nickname.value
  if (!v.length) return null
  if (v.length < NICKNAME_MIN_LEN) {
    return `Use at least ${NICKNAME_MIN_LEN} characters.`
  }
  if (!isValidNickname(v)) {
    return 'Only letters (a–z, A–Z), numbers, _ and -; no spaces or accented characters.'
  }
  return null
})

async function submit() {
  if (loading.value) return
  errorMsg.value = null
  if (!isValidNickname(nickname.value)) {
    errorMsg.value =
      hintInvalid.value ??
      'Invalid nickname. Use letters (no accents), numbers, _, or -.'
    return
  }
  loading.value = true
  try {
    await auth.updateProfile(nickname.value)
    await router.push('/')
  } catch (err: unknown) {
    if (axios.isAxiosError(err)) {
      const data = err.response?.data as
        | { message?: string; errors?: Record<string, string[]> }
        | undefined
      const firstFieldError = data?.errors
        ? Object.values(data.errors)[0]?.[0]
        : undefined
      errorMsg.value =
        firstFieldError ??
        data?.message ??
        'Could not save your profile.'
    } else {
      errorMsg.value = err instanceof Error ? err.message : 'Unknown error.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="profile-edit-page">
    <div class="profile-edit-page__backdrop" aria-hidden="true">
      <div class="profile-edit-page__blob profile-edit-page__blob--top" />
      <div class="profile-edit-page__blob profile-edit-page__blob--bottom" />
    </div>

    <main class="profile-edit-page__content">
      <AuthBrand
        title="Edit profile"
        subtitle="Display name (nickname)"
        icon="badge"
      />

      <div class="auth-card">
        <form class="auth-card__form" @submit.prevent="submit">
          <AuthField
            id="profile-nickname"
            :model-value="nickname"
            label="Nickname"
            icon="alternate_email"
            type="text"
            placeholder="e.g. maria_silva"
            autocomplete="username"
            required
            :disabled="loading"
            :maxlength="NICKNAME_MAX_LEN"
            @update:model-value="onNicknameInput"
          />

          <p class="profile-edit-page__hint">
            {{ NICKNAME_MIN_LEN }}–{{ NICKNAME_MAX_LEN }} characters. No spaces or
            accents; letters, numbers, <code class="text-xs">_</code>, and
            <code class="text-xs">-</code> only.
          </p>

          <p v-if="hintInvalid" class="profile-edit-page__hint text-error">
            {{ hintInvalid }}
          </p>

          <div v-if="auth.user?.email" class="profile-edit-page__email">
            <span class="profile-edit-page__email-label">Email</span>
            {{ auth.user.email }}
          </div>

          <p v-if="errorMsg" class="auth-error mt-4" role="alert">
            <MaterialIcon name="error" size="1.125rem" />
            <span>{{ errorMsg }}</span>
          </p>

          <div class="pt-2">
            <AuthSubmitButton
              label="Save"
              loading-label="Saving…"
              :loading="loading"
            />
          </div>
        </form>
      </div>
    </main>
  </div>
</template>
