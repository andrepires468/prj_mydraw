<script setup lang="ts">
import { RouterLink } from 'vue-router'
import MaterialIcon from '@/components/common/MaterialIcon.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const currentYear = new Date().getFullYear()
</script>

<template>
  <footer class="app-footer">
    <div class="app-page-inner app-footer__inner">
      <div class="app-footer__top">
        <div class="app-footer__brand-section">
          <RouterLink to="/" class="app-footer__brand">
            <span class="app-footer__brand-name">mydrow.app</span>
          </RouterLink>
          <p class="app-footer__tagline">AI-powered art gallery</p>
        </div>

        <nav class="app-footer__nav" aria-label="Footer navigation">
          <div class="app-footer__nav-group">
            <h3 class="app-footer__nav-title">Explore</h3>
            <RouterLink to="/gallery/published" class="app-footer__nav-link">
              <MaterialIcon name="grid_view" size="1rem" />
              Gallery
            </RouterLink>
            <RouterLink v-if="auth.isAdmin" to="/admin/posts/new/edit" class="app-footer__nav-link">
              <MaterialIcon name="add_circle" size="1rem" />
              Create
            </RouterLink>
          </div>

          <div class="app-footer__nav-group">
            <h3 class="app-footer__nav-title">Account</h3>
            <template v-if="auth.user">
              <RouterLink to="/profile" class="app-footer__nav-link">
                <MaterialIcon name="person" size="1rem" />
                Profile
              </RouterLink>
              <RouterLink v-if="auth.isAdmin" to="/admin/management" class="app-footer__nav-link">
                <MaterialIcon name="admin_panel_settings" size="1rem" />
                Management
              </RouterLink>
            </template>
            <RouterLink v-else to="/login" class="app-footer__nav-link">
              <MaterialIcon name="login" size="1rem" />
              Sign in
            </RouterLink>
          </div>
        </nav>
      </div>

      <div class="app-footer__divider" />

      <div class="app-footer__bottom">
        <p class="app-footer__copyright">
          &copy; {{ currentYear }} mydrow.app &mdash; All rights reserved.
        </p>
        <div class="app-footer__badges">
          <span class="app-footer__badge">
            <MaterialIcon name="auto_awesome" size="0.875rem" filled />
            AI Generated Art
          </span>
          <span class="app-footer__badge">
            <MaterialIcon name="palette" size="0.875rem" filled />
            Creative Gallery
          </span>
        </div>
      </div>
    </div>
  </footer>
</template>
