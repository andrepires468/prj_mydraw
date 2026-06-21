<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import { useAuthStore } from '@/stores/auth'
import { registerBackHandler } from '@/utils/backHandlerStack'

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
const mobileNavOpen = ref(false)

let unregisterMobileBack: (() => void) | undefined
let previousBodyOverflow = ''

function toggleMenu() {
  menuOpen.value = !menuOpen.value
}

function closeMenu() {
  menuOpen.value = false
}

function closeMobileNav() {
  mobileNavOpen.value = false
}

function toggleMobileNav() {
  mobileNavOpen.value = !mobileNavOpen.value
}

const primaryNavLinks = computed(() => {
  const links: Array<{
    to: string
    label: string
    isActive: boolean
  }> = [
    {
      to: '/gallery/published',
      label: 'Gallery',
      isActive: galleryNavActive.value,
    },
  ]

  if (auth.isAdmin) {
    links.push(
      {
        to: '/admin/management',
        label: 'Management',
        isActive: route.name === 'admin-management',
      },
      {
        to: '/admin/posts/new/edit',
        label: 'Create',
        isActive:
          route.name === 'edit-post-new' || route.name === 'edit-post',
      },
    )
  }

  return links
})

function onDocumentClick(event: MouseEvent) {
  if (!menuOpen.value) return
  const target = event.target as Node | null
  if (menuRef.value && target && !menuRef.value.contains(target)) {
    closeMenu()
  }
}

function onKeydown(event: KeyboardEvent) {
  if (event.key !== 'Escape') return
  if (menuOpen.value) closeMenu()
  if (mobileNavOpen.value) closeMobileNav()
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

watch(mobileNavOpen, (open) => {
  if (open) {
    closeMenu()
    previousBodyOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    unregisterMobileBack = registerBackHandler(closeMobileNav)
  } else {
    unregisterMobileBack?.()
    unregisterMobileBack = undefined
    document.body.style.overflow = previousBodyOverflow
  }
})

watch(
  () => route.fullPath,
  () => {
    closeMobileNav()
  },
)

onBeforeUnmount(() => {
  unregisterMobileBack?.()
  document.body.style.overflow = previousBodyOverflow
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
        <button
          type="button"
          class="top-nav-bar__menu-toggle"
          :aria-expanded="mobileNavOpen"
          aria-controls="top-nav-bar-drawer"
          :aria-label="mobileNavOpen ? 'Close menu' : 'Open menu'"
          @click="toggleMobileNav"
        >
          <MaterialIcon name="menu" />
        </button>

        <RouterLink to="/" class="top-nav-bar__brand">mydrow.app</RouterLink>

        <nav class="top-nav-bar__nav" aria-label="Main navigation">
          <RouterLink
            v-for="link in primaryNavLinks"
            :key="link.to"
            :to="link.to"
            class="top-nav-bar__link"
            :class="{ 'top-nav-bar__link--active': link.isActive }"
          >
            {{ link.label }}
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

    <Teleport to="body">
      <Transition name="top-nav-bar__drawer">
        <div
          v-if="mobileNavOpen"
          class="top-nav-bar__drawer-root"
          role="dialog"
          aria-modal="true"
          aria-label="Main navigation"
        >
          <button
            type="button"
            class="top-nav-bar__drawer-backdrop"
            aria-label="Close menu"
            @click="closeMobileNav"
          />

          <aside id="top-nav-bar-drawer" class="top-nav-bar__drawer">
            <header class="top-nav-bar__drawer-header">
              <RouterLink
                to="/"
                class="top-nav-bar__drawer-brand"
                @click="closeMobileNav"
              >
                mydrow.app
              </RouterLink>
              <button
                type="button"
                class="top-nav-bar__icon-button"
                aria-label="Close menu"
                @click="closeMobileNav"
              >
                <MaterialIcon name="close" />
              </button>
            </header>

            <nav class="top-nav-bar__drawer-nav" aria-label="Main navigation">
              <RouterLink
                v-for="link in primaryNavLinks"
                :key="`drawer-${link.to}`"
                :to="link.to"
                class="top-nav-bar__drawer-link"
                :class="{ 'top-nav-bar__drawer-link--active': link.isActive }"
                @click="closeMobileNav"
              >
                {{ link.label }}
              </RouterLink>
            </nav>
          </aside>
        </div>
      </Transition>
    </Teleport>
  </header>
</template>
