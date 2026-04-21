<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const searchQuery = ref('')

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
const isDark = ref(false)

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
  try {
    const stored = localStorage.getItem(THEME_KEY)
    if (stored === 'dark' || stored === 'light') {
      isDark.value = stored === 'dark'
    } else if (window.matchMedia) {
      isDark.value = window.matchMedia('(prefers-color-scheme: dark)').matches
    }
  } catch {
    // fallback para tema claro
  }
  applyTheme(isDark.value)

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

const userInitial = computed(() => {
  const name = auth.user?.name?.trim() ?? ''
  return name ? name.charAt(0).toUpperCase() : '?'
})
</script>

<template>
  <header class="top-nav-bar">
    <div class="top-nav-bar__inner">
      <div class="top-nav-bar__left">
        <RouterLink to="/" class="top-nav-bar__brand">mydrow.app</RouterLink>

        <nav class="top-nav-bar__nav">
          <RouterLink
            to="/gallery"
            class="top-nav-bar__link"
            active-class="top-nav-bar__link--active"
          >
            Gallery
          </RouterLink>
          <RouterLink
            to="/create"
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
          <button
            type="button"
            class="top-nav-bar__icon-button"
            aria-label="Admin panel"
          >
            <MaterialIcon name="admin_panel_settings" />
          </button>

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
                  {{ userInitial }}
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
                    <span class="top-nav-bar__avatar top-nav-bar__avatar--lg" aria-hidden="true">
                      {{ userInitial }}
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

                  <button
                    type="button"
                    class="top-nav-bar__dropdown-item"
                    role="menuitem"
                    @click="closeMenu"
                  >
                    <MaterialIcon name="person" size="1.25rem" />
                    <span class="top-nav-bar__dropdown-label">Perfil</span>
                  </button>

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
                      Tema {{ isDark ? 'escuro' : 'claro' }}
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
                    <span class="top-nav-bar__dropdown-label">Sair</span>
                  </button>
                </div>
              </transition>
            </div>
          </template>

          <RouterLink v-else to="/login" class="top-nav-bar__login">
            Entrar
          </RouterLink>
        </div>
      </div>
    </div>
  </header>
</template>
