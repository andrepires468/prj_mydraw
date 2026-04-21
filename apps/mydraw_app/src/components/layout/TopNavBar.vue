<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const searchQuery = ref('')
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
            <button
              type="button"
              class="top-nav-bar__icon-button"
              aria-label="Account"
            >
              <MaterialIcon name="account_circle" />
            </button>
            <span class="top-nav-bar__user">{{ auth.user.name }}</span>
            <button
              type="button"
              class="top-nav-bar__logout"
              @click="auth.logout()"
            >
              sair
            </button>
          </template>

          <RouterLink v-else to="/login" class="top-nav-bar__login">
            Entrar
          </RouterLink>
        </div>
      </div>
    </div>
  </header>
</template>
