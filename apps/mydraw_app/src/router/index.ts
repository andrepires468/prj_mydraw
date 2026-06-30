import { createRouter, createWebHashHistory, createWebHistory } from 'vue-router'
import { defineComponent, h, type Component } from 'vue'
import { isNativePlatform } from '@/utils/platform'
import GalleryView from '@/views/GalleryView.vue'
import LoginView from '@/views/LoginView.vue'
import ProfileEditView from '@/views/ProfileEditView.vue'
import EditPostView from '@/views/EditPostView.vue'
import PostView from '@/views/PostView.vue'
import AdminManagementView from '@/views/AdminManagementView.vue'
import PostReorderView from '@/views/PostReorderView.vue'
import { isJwtExpired } from '@/utils/jwt'

function storedUserIsAdmin(): boolean {
  try {
    const raw = localStorage.getItem('mydraw:user')
    if (!raw) return false
    const user = JSON.parse(raw) as { is_admin?: unknown }
    return user.is_admin === true
  } catch {
    return false
  }
}

/** Filho de rota só para URL do modal; a UI fica no GalleryView pai. */
const GalleryPostModalRoute: Component = defineComponent({
  name: 'GalleryPostModalRoute',
  render: () => h('div', { 'aria-hidden': 'true', class: 'hidden' }),
})

/** Hash history no WebView Capacitor evita 404 em refresh e assets locais. */
const routerHistory = isNativePlatform() ? createWebHashHistory() : createWebHistory()

export const router = createRouter({
  history: routerHistory,
  routes: [
    {
      path: '/',
      redirect: { name: 'gallery-published' },
      meta: { public: true },
    },
    {
      path: '/gallery',
      redirect: { name: 'gallery-published' },
      meta: { public: true },
    },
    {
      path: '/gallery/published',
      name: 'gallery-published',
      component: GalleryView,
      meta: { public: true },
      children: [
        {
          path: 'post/:id(\\d+)',
          name: 'gallery-published-post',
          component: GalleryPostModalRoute,
          meta: { public: true },
        },
      ],
    },
    {
      path: '/gallery/drafts',
      name: 'gallery-drafts',
      component: GalleryView,
      meta: { adminOnly: true },
      children: [
        {
          path: 'post/:id(\\d+)',
          name: 'gallery-drafts-post',
          component: GalleryPostModalRoute,
          meta: { adminOnly: true },
        },
      ],
    },
    {
      path: '/gallery/published/reorder',
      name: 'gallery-published-reorder',
      component: PostReorderView,
      meta: { adminOnly: true },
    },
    {
      path: '/posts/:id(\\d+)',
      redirect: (to) => ({
        path: `/admin/posts/${String(to.params.id)}`,
      }),
    },
    {
      path: '/posts/new/edit',
      redirect: '/admin/posts/new/edit',
    },
    {
      path: '/posts/:post_id(\\d+)/edit',
      redirect: (to) => ({
        path: `/admin/posts/${String(to.params.post_id)}/edit`,
      }),
    },
    {
      path: '/admin/posts/:id(\\d+)',
      name: 'post',
      component: PostView,
      props: true,
      meta: { adminOnly: true },
    },
    {
      path: '/admin/posts/new/edit',
      name: 'edit-post-new',
      component: EditPostView,
      meta: { adminOnly: true },
    },
    {
      path: '/admin/posts/:post_id(\\d+)/edit',
      name: 'edit-post',
      component: EditPostView,
      props: true,
      meta: { adminOnly: true },
    },
    {
      path: '/create',
      redirect: { name: 'edit-post-new' },
      meta: { adminOnly: true },
    },
    {
      path: '/login',
      name: 'login',
      component: LoginView,
      meta: { public: true, guestOnly: true },
    },
    {
      path: '/profile',
      name: 'profile-edit',
      component: ProfileEditView,
    },
    {
      path: '/admin/management',
      name: 'admin-management',
      component: AdminManagementView,
      meta: { adminOnly: true },
    },
  ],
})

router.beforeEach((to) => {
  const token = localStorage.getItem('mydraw:token')
  const hasValidSession = token !== null && !isJwtExpired(token)

  if (!hasValidSession && token) {
    // Token presente porém expirado: limpa resquícios antes de decidir.
    localStorage.removeItem('mydraw:token')
    localStorage.removeItem('mydraw:user')
  }

  if (to.meta.guestOnly && hasValidSession) {
    return { path: '/' }
  }

  /** Rotas visitáveis sem login: login, galeria publicada, post na URL da galeria (modal). Posts dedicados: `/admin/posts/:id` (apenas admin). */
  const isPublic = to.matched.some((record) => record.meta.public === true)

  if (!isPublic && !hasValidSession) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  if (to.matched.some((record) => record.meta.adminOnly === true) && !storedUserIsAdmin()) {
    return { name: 'gallery-published' }
  }
  return true
})
