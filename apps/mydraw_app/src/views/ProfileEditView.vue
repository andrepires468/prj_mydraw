<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref, watch, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { getApiErrorMessage } from '@/utils/apiErrorMessage'
import * as authApi from '@/api/auth'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import AuthBrand from '@/components/auth/AuthBrand.vue'
import AuthField from '@/components/auth/AuthField.vue'
import AuthSubmitButton from '@/components/auth/AuthSubmitButton.vue'
import AppConfirmAlert from '@/components/common/AppConfirmAlert.vue'
import {
  NICKNAME_MAX_LEN,
  NICKNAME_MIN_LEN,
  isValidNickname,
  sanitizeNicknameKeystrokes,
} from '@/utils/nickname'
import {
  isGoogleSignInConfigured,
  renderGoogleSignInButton,
  setGoogleCredentialHandler,
  whenGoogleLibraryReady,
} from '@/utils/googleIdentity'

const auth = useAuthStore()
const router = useRouter()

const nickname = ref('')
const loading = ref(false)
const errorMsg = ref<string | null>(null)
const avatarFailed = ref(false)
const googleMountEl = ref<HTMLElement | null>(null)
const googleLinkLoading = ref(false)
const unlinkConfirmOpen = ref(false)
const unlinkLoading = ref(false)

const sectionMode = ref<'profile' | 'password'>('profile')
const currentPassword = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
const passwordErrorMsg = ref<string | null>(null)
const passwordLoading = ref(false)
const showCurrPw = ref(false)
const showNewPw = ref(false)
const showConfPw = ref(false)

/** Mesmo valor que `APP_VITE_GOOGLE_CLIENT_ID` no `.env` da raiz (reinicie o dev server / container `app`). */
const googleConfigured = computed(() => isGoogleSignInConfigured())

function openPasswordSection() {
  errorMsg.value = null
  passwordErrorMsg.value = null
  sectionMode.value = 'password'
}

function goBackToProfile() {
  passwordErrorMsg.value = null
  currentPassword.value = ''
  newPassword.value = ''
  confirmPassword.value = ''
  sectionMode.value = 'profile'
}

async function submitPasswordChange() {
  if (passwordLoading.value) return
  passwordErrorMsg.value = null
  if (newPassword.value !== confirmPassword.value) {
    passwordErrorMsg.value = 'New password and confirmation do not match.'
    return
  }
  passwordLoading.value = true
  try {
    await authApi.changePassword({
      current_password: currentPassword.value,
      password: newPassword.value,
      password_confirmation: confirmPassword.value,
    })
    goBackToProfile()
  } catch (err: unknown) {
    passwordErrorMsg.value = getApiErrorMessage(err)
    await nextTick()
    document
      .querySelector('.profile-edit-page__password-panel .auth-error')
      ?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
  } finally {
    passwordLoading.value = false
  }
}

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
  await nextTick()
  await mountGoogleLinkButton()
})

watch(
  () => [
    googleConfigured.value,
    auth.user?.google_linked,
    auth.user?.email,
  ],
  async () => {
    await nextTick()
    await mountGoogleLinkButton()
  },
)

async function mountGoogleLinkButton() {
  if (
    !googleConfigured.value ||
    !auth.user?.email ||
    auth.user.google_linked ||
    !googleMountEl.value
  ) {
    return
  }
  const ready = await whenGoogleLibraryReady()
  if (!ready || !googleMountEl.value) return
  setGoogleCredentialHandler(onGoogleLinkCredential)
  renderGoogleSignInButton(googleMountEl.value)
}

function openUnlinkConfirm() {
  if (unlinkLoading.value) return
  unlinkConfirmOpen.value = true
}

function cancelUnlinkConfirm() {
  unlinkConfirmOpen.value = false
}

async function confirmUnlinkGoogle() {
  if (unlinkLoading.value) return
  unlinkLoading.value = true
  errorMsg.value = null
  try {
    const meUser = await authApi.unlinkGoogleAccount()
    auth.setUser(meUser)
    unlinkConfirmOpen.value = false
    await nextTick()
    await mountGoogleLinkButton()
  } catch (err: unknown) {
    unlinkConfirmOpen.value = false
    errorMsg.value = getApiErrorMessage(err)
    await nextTick()
    document
      .querySelector('.profile-edit-page .auth-error')
      ?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
  } finally {
    unlinkLoading.value = false
  }
}

async function onGoogleLinkCredential(credential: string) {
  if (googleLinkLoading.value || loading.value) return
  googleLinkLoading.value = true
  errorMsg.value = null
  try {
    await auth.linkGoogleAccount(credential)
  } catch (err: unknown) {
    errorMsg.value = getApiErrorMessage(err)
  } finally {
    googleLinkLoading.value = false
  }
}

onBeforeUnmount(() => {
  setGoogleCredentialHandler(undefined)
})

const userInitial = computed(() => {
  const name =
    auth.user?.name?.trim() ||
    nickname.value.trim()
  return name ? name.charAt(0).toUpperCase() : '?'
})

const showProfileAvatar = computed(
  () =>
    Boolean(auth.user?.avatar_url) &&
    !avatarFailed.value,
)

watch(
  () => auth.user?.avatar_url,
  () => {
    avatarFailed.value = false
  },
)

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
    errorMsg.value = getApiErrorMessage(err)
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

    <main v-if="sectionMode === 'profile'" class="profile-edit-page__content">
      <AuthBrand
        title="Edit profile"
        subtitle="Display name (nickname)"
        icon="badge"
      />

      <div class="auth-card">
        <form class="auth-card__form" @submit.prevent="submit">
          <div class="profile-edit-page__identity">
            <div class="profile-edit-page__avatar" aria-hidden="true">
              <img
                v-if="showProfileAvatar"
                :src="auth.user!.avatar_url!"
                alt=""
                class="profile-edit-page__avatar-img"
                @error="avatarFailed = true"
              />
              <span v-else class="profile-edit-page__avatar-fallback">{{
                userInitial
              }}</span>
            </div>
            <div class="profile-edit-page__identity-text">
              <span class="text-sm font-medium text-on-surface">Photo</span>
              <span class="text-xs text-on-surface-variant leading-snug">
                From Google when linked; otherwise your initial.
              </span>
            </div>
          </div>

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

          <div class="profile-edit-page__password-row">
            <button
              type="button"
              class="profile-edit-page__password-link"
              @click="openPasswordSection"
            >
              Change password
            </button>
          </div>

          <div v-if="auth.user" class="profile-edit-page__google">
            <p class="profile-edit-page__google-title">Google account</p>

            <template v-if="auth.user.google_linked">
              <p class="profile-edit-page__google-status">
                Linked with Google
              </p>
              <button
                type="button"
                class="profile-edit-page__google-unlink"
                :disabled="unlinkLoading"
                @click="openUnlinkConfirm"
              >
                {{ unlinkLoading ? 'Unlinking…' : 'Unlink Google' }}
              </button>
            </template>

            <template v-else-if="!googleConfigured">
              <p class="profile-edit-page__google-hint">
                Linking is disabled until the app has a Google Web Client ID.
                Set the same value in
                <code class="text-xs">GOOGLE_CLIENT_ID</code> (API) and
                <code class="text-xs">APP_VITE_GOOGLE_CLIENT_ID</code> (frontend)
                in the repo root <code class="text-xs">.env</code>, then restart
                the app container or dev server so
                <code class="text-xs">VITE_GOOGLE_CLIENT_ID</code> is applied.
              </p>
            </template>

            <template v-else>
              <p class="profile-edit-page__google-hint">
                Link Google using the same email as above. After linking you can
                sign in with Google.
              </p>
              <div
                ref="googleMountEl"
                class="google-sign-in-mount"
                :aria-busy="googleLinkLoading"
              />
            </template>
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

    <main
      v-else
      class="profile-edit-page__content profile-edit-page__password-panel"
    >
      <AuthBrand
        title="Change password"
        subtitle="Enter your current password and choose a new one."
        icon="lock"
      />

      <div class="auth-card">
        <button
          type="button"
          class="profile-edit-page__back"
          @click="goBackToProfile"
        >
          <MaterialIcon name="arrow_back" size="1.25rem" />
          Back to profile
        </button>

        <form class="auth-card__form" @submit.prevent="submitPasswordChange">
          <AuthField
            id="pwd-current"
            :model-value="currentPassword"
            label="Current password"
            icon="lock"
            :type="showCurrPw ? 'text' : 'password'"
            placeholder="••••••••"
            autocomplete="current-password"
            required
            :disabled="passwordLoading"
            :minlength="6"
            @update:model-value="currentPassword = $event"
          >
            <template #suffix>
              <button
                type="button"
                class="auth-field__suffix"
                :aria-label="showCurrPw ? 'Hide password' : 'Show password'"
                @click="showCurrPw = !showCurrPw"
              >
                <MaterialIcon
                  :name="showCurrPw ? 'visibility_off' : 'visibility'"
                  size="1.25rem"
                />
              </button>
            </template>
          </AuthField>

          <AuthField
            id="pwd-new"
            :model-value="newPassword"
            label="New password"
            icon="lock"
            :type="showNewPw ? 'text' : 'password'"
            placeholder="••••••••"
            autocomplete="new-password"
            required
            :disabled="passwordLoading"
            :minlength="6"
            :maxlength="100"
            @update:model-value="newPassword = $event"
          >
            <template #suffix>
              <button
                type="button"
                class="auth-field__suffix"
                :aria-label="showNewPw ? 'Hide password' : 'Show password'"
                @click="showNewPw = !showNewPw"
              >
                <MaterialIcon
                  :name="showNewPw ? 'visibility_off' : 'visibility'"
                  size="1.25rem"
                />
              </button>
            </template>
          </AuthField>

          <AuthField
            id="pwd-confirm"
            :model-value="confirmPassword"
            label="Confirm new password"
            icon="lock"
            :type="showConfPw ? 'text' : 'password'"
            placeholder="••••••••"
            autocomplete="new-password"
            required
            :disabled="passwordLoading"
            :minlength="6"
            :maxlength="100"
            @update:model-value="confirmPassword = $event"
          >
            <template #suffix>
              <button
                type="button"
                class="auth-field__suffix"
                :aria-label="showConfPw ? 'Hide password' : 'Show password'"
                @click="showConfPw = !showConfPw"
              >
                <MaterialIcon
                  :name="showConfPw ? 'visibility_off' : 'visibility'"
                  size="1.25rem"
                />
              </button>
            </template>
          </AuthField>

          <p class="profile-edit-page__hint">
            At least 6 characters (max 100).
          </p>

          <p v-if="passwordErrorMsg" class="auth-error mt-4" role="alert">
            <MaterialIcon name="error" size="1.125rem" />
            <span>{{ passwordErrorMsg }}</span>
          </p>

          <div class="pt-2">
            <AuthSubmitButton
              label="Update password"
              loading-label="Updating…"
              :loading="passwordLoading"
            />
          </div>
        </form>
      </div>
    </main>

    <AppConfirmAlert
      v-if="unlinkConfirmOpen"
      title="Unlink Google?"
      message="You will sign in with email and password until you link Google again. Your profile photo in the app is unchanged."
      cancel-label="Cancel"
      confirm-label="Unlink"
      confirm-variant="danger"
      @cancel="cancelUnlinkConfirm"
      @confirm="confirmUnlinkGoogle"
    />
  </div>
</template>
