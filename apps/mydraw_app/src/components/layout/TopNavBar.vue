<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()
const searchQuery = ref('')

const galleryNavActive = computed(() =>
  [
    'gallery-published',
    'gallery-drafts',
    'gallery-published-post',
    'gallery-drafts-post',
  ].includes(route.name as string),
)

const menuOpen = ref(false)
const menuRef = ref<HTMLElement | null>(null)

function toggleMenu() {
  menuOpen.value = !menuOpen.value
}

function closeMenu() {
  menuOpen.value = false
}

function onDocumentClick(event: MouseEvent) {
  if (!menuOpen.value) return
  const target = event.target as Node | null
  if (menuRef.value && target && !menuRef.value.contains(target)) {
    closeMenu()
  }
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape' && menuOpen.value) {
    closeMenu()
  }
}

const THEME_KEY = 'mydraw:theme'
// O tema inicial é aplicado por um script inline em `index.html` (evita FOUC);
// aqui apenas espelhamos a classe atual do documento no estado reativo.
const isDark = ref(document.documentElement.classList.contains('dark'))

function applyTheme(dark: boolean) {
  const root = document.documentElement
  root.classList.toggle('dark', dark)
  root.style.colorScheme = dark ? 'dark' : 'light'
}

function toggleTheme() {
  isDark.value = !isDark.value
}

watch(isDark, (value) => {
  applyTheme(value)
  try {
    localStorage.setItem(THEME_KEY, value ? 'dark' : 'light')
  } catch {
    // ignora erros de acesso a storage
  }
})

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
  document.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
  document.removeEventListener('keydown', onKeydown)
})

function handleLogout() {
  closeMenu()
  auth.logout()
}

const avatarFailed = ref(false)

watch(
  () => auth.user?.avatar_url,
  () => {
    avatarFailed.value = false
  },
)

const showAvatarImage = computed(
  () => Boolean(auth.user?.avatar_url) && !avatarFailed.value,
)

const userInitial = computed(() => {
  const name = auth.user?.name?.trim() ?? ''
  return name ? name.charAt(0).toUpperCase() : '?'
})
</script>

<template>
  <header class="top-nav-bar">
    <div class="app-page-inner top-nav-bar__inner">
      <div class="top-nav-bar__left">
        <RouterLink to="/" class="top-nav-bar__brand">mydrow.app</RouterLink>

        <nav class="top-nav-bar__nav">
          <RouterLink
            to="/gallery/published"
            class="top-nav-bar__link"
            :class="{ 'top-nav-bar__link--active': galleryNavActive }"
          >
            Gallery
          </RouterLink>
          <RouterLink
            v-if="auth.isAdmin"
            to="/admin/management"
            class="top-nav-bar__link"
            active-class="top-nav-bar__link--active"
          >
            Management
          </RouterLink>
          <RouterLink
            v-if="auth.isAdmin"
            to="/admin/posts/new/edit"
            class="top-nav-bar__link"
            active-class="top-nav-bar__link--active"
          >
            Create
          </RouterLink>
        </nav>
      </div>

      <div class="top-nav-bar__right">
        <div class="top-nav-bar__search">
          <MaterialIcon name="search" size="1.125rem" />
          <input
            v-model="searchQuery"
            type="text"
            class="top-nav-bar__search-input"
            placeholder="Search gallery..."
          />
        </div>

        <div class="top-nav-bar__actions">
          <RouterLink
            v-if="auth.isAdmin"
            to="/admin/management"
            class="top-nav-bar__icon-button"
            aria-label="Management"
          >
            <MaterialIcon name="admin_panel_settings" />
          </RouterLink>

          <template v-if="auth.user">
            <div ref="menuRef" class="top-nav-bar__menu">
              <button
                type="button"
                class="top-nav-bar__user-button"
                :class="{ 'top-nav-bar__user-button--open': menuOpen }"
                :aria-expanded="menuOpen"
                aria-haspopup="menu"
                @click="toggleMenu"
              >
                <span class="top-nav-bar__avatar" aria-hidden="true">
                  <img
                    v-if="showAvatarImage"
                    :src="auth.user.avatar_url!"
                    alt=""
                    class="top-nav-bar__avatar-img"
                    @error="avatarFailed = true"
                  />
                  <span v-else class="top-nav-bar__avatar-fallback">{{
                    userInitial
                  }}</span>
                </span>
                <span class="top-nav-bar__user-name">
                  {{ auth.user.name }}
                </span>
                <MaterialIcon
                  name="expand_more"
                  size="1.125rem"
                  class="top-nav-bar__chevron"
                  :class="{ 'top-nav-bar__chevron--open': menuOpen }"
                />
              </button>

              <transition name="top-nav-bar__menu-anim">
                <div
                  v-if="menuOpen"
                  class="top-nav-bar__dropdown"
                  role="menu"
                >
                  <div class="top-nav-bar__dropdown-header">
                    <span
                      class="top-nav-bar__avatar top-nav-bar__avatar--lg"
                      aria-hidden="true"
                    >
                      <img
                        v-if="showAvatarImage"
                        :src="auth.user.avatar_url!"
                        alt=""
                        class="top-nav-bar__avatar-img"
                        @error="avatarFailed = true"
                      />
                      <span v-else class="top-nav-bar__avatar-fallback">{{
                        userInitial
                      }}</span>
                    </span>
                    <div class="top-nav-bar__dropdown-user">
                      <span class="top-nav-bar__dropdown-name">
                        {{ auth.user.name }}
                      </span>
                      <span
                        v-if="auth.user.email"
                        class="top-nav-bar__dropdown-email"
                      >
                        {{ auth.user.email }}
                      </span>
                    </div>
                  </div>

                  <RouterLink
                    to="/profile"
                    class="top-nav-bar__dropdown-item"
                    role="menuitem"
                    @click="closeMenu"
                  >
                    <MaterialIcon name="person" size="1.25rem" />
                    <span class="top-nav-bar__dropdown-label">Profile</span>
                  </RouterLink>

                  <button
                    type="button"
                    class="top-nav-bar__dropdown-item top-nav-bar__dropdown-item--toggle"
                    role="menuitemcheckbox"
                    :aria-checked="isDark"
                    @click="toggleTheme"
                  >
                    <MaterialIcon
                      :name="isDark ? 'dark_mode' : 'light_mode'"
                      size="1.25rem"
                    />
                    <span class="top-nav-bar__dropdown-label">
                      {{ isDark ? 'Dark' : 'Light' }} theme
                    </span>
                    <span
                      class="top-nav-bar__switch"
                      :class="{ 'top-nav-bar__switch--on': isDark }"
                      aria-hidden="true"
                    >
                      <span class="top-nav-bar__switch-thumb" />
                    </span>
                  </button>

                  <div class="top-nav-bar__dropdown-divider" role="separator" />

                  <button
                    type="button"
                    class="top-nav-bar__dropdown-item top-nav-bar__dropdown-item--danger"
                    role="menuitem"
                    @click="handleLogout"
                  >
                    <MaterialIcon name="logout" size="1.25rem" />
                    <span class="top-nav-bar__dropdown-label">Sign out</span>
                  </button>
                </div>
              </transition>
            </div>
          </template>

          <RouterLink v-else to="/login" class="top-nav-bar__login">
            Sign in
          </RouterLink>
        </div>
      </div>
    </div>
  </header>
</template>
