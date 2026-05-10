<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { getApiErrorMessage } from '@/utils/apiErrorMessage'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import AuthBrand from '@/components/auth/AuthBrand.vue'
import AuthField from '@/components/auth/AuthField.vue'
import AuthSubmitButton from '@/components/auth/AuthSubmitButton.vue'
import AuthSecurityFooter from '@/components/auth/AuthSecurityFooter.vue'
import {
  isGoogleSignInConfigured,
  renderGoogleSignInButton,
  setGoogleCredentialHandler,
  whenGoogleLibraryReady,
} from '@/utils/googleIdentity'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const email = ref('')
const password = ref('')
const loading = ref(false)
const errorMsg = ref<string | null>(null)
const showPassword = ref(false)
const googleMountEl = ref<HTMLElement | null>(null)
const showGoogleSignIn = ref(isGoogleSignInConfigured())

function togglePassword() {
  showPassword.value = !showPassword.value
}

async function mountGoogleButton() {
  if (!showGoogleSignIn.value || !googleMountEl.value) return
  const ready = await whenGoogleLibraryReady()
  if (!ready || !googleMountEl.value) return
  setGoogleCredentialHandler(async (credential: string) => {
    if (loading.value) return
    errorMsg.value = null
    loading.value = true
    try {
      await auth.loginWithGoogle(credential)
      const redirect =
        typeof route.query.redirect === 'string' ? route.query.redirect : '/'
      await router.replace(redirect)
    } catch (err: unknown) {
      errorMsg.value = getApiErrorMessage(err)
    } finally {
      loading.value = false
    }
  })
  renderGoogleSignInButton(googleMountEl.value)
}

onMounted(async () => {
  await nextTick()
  await mountGoogleButton()
})

onBeforeUnmount(() => {
  setGoogleCredentialHandler(undefined)
})

async function submit() {
  if (loading.value) return
  errorMsg.value = null
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    const redirect =
      typeof route.query.redirect === 'string' ? route.query.redirect : '/'
    await router.replace(redirect)
  } catch (err: unknown) {
    errorMsg.value = getApiErrorMessage(err)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-page">
    <div class="login-page__backdrop" aria-hidden="true">
      <div class="login-page__blob login-page__blob--top" />
      <div class="login-page__blob login-page__blob--bottom" />
    </div>

    <main class="login-page__content">
      <AuthBrand
        title="mydrow.app"
        subtitle="Administrative Gateway"
        icon="admin_panel_settings"
      />

      <div class="auth-card">
        <form class="auth-card__form" @submit.prevent="submit">
          <AuthField
            id="email"
            v-model="email"
            label="Email Address"
            icon="mail"
            type="email"
            placeholder="admin@mydrow.app"
            autocomplete="email"
            required
            :disabled="loading"
          />

          <AuthField
            id="password"
            v-model="password"
            label="Password"
            icon="lock"
            :type="showPassword ? 'text' : 'password'"
            placeholder="••••••••"
            autocomplete="current-password"
            required
            :minlength="4"
            :disabled="loading"
          >
            <template #aside>
              <a href="#" class="auth-field__aside">Forgot?</a>
            </template>
            <template #suffix>
              <button
                type="button"
                class="auth-field__suffix"
                :aria-label="showPassword ? 'Hide password' : 'Show password'"
                @click="togglePassword"
              >
                <MaterialIcon
                  :name="showPassword ? 'visibility_off' : 'visibility'"
                  size="1.25rem"
                />
              </button>
            </template>
          </AuthField>

          <p v-if="errorMsg" class="auth-error" role="alert">
            <MaterialIcon name="error" filled />
            <span>{{ errorMsg }}</span>
          </p>

          <div class="pt-2">
            <AuthSubmitButton
              label="Authorize Session"
              loading-label="Signing in…"
              :loading="loading"
            />
          </div>

          <template v-if="showGoogleSignIn">
            <div class="auth-divider" aria-hidden="true">or</div>
            <div
              ref="googleMountEl"
              class="google-sign-in-mount"
              aria-label="Sign in with Google"
            />
          </template>

          <AuthSecurityFooter />
        </form>
      </div>

      <div class="login-page__system-id">
        <p class="login-page__system-id-text">
          SYSTEM ID: DROW-ADMIN-001 ·
          <span class="login-page__system-id-highlight">NODE STATUS: ONLINE</span>
        </p>
      </div>
    </main>
  </div>
</template>
