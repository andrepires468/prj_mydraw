import { createRouter, createWebHistory } from 'vue-router'
import GalleryView from '@/views/GalleryView.vue'
import LoginView from '@/views/LoginView.vue'
import ProfileEditView from '@/views/ProfileEditView.vue'
import EditPostView from '@/views/EditPostView.vue'
import PostView from '@/views/PostView.vue'
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

export const router = createRouter({
  history: createWebHistory(),
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
    },
    {
      path: '/gallery/drafts',
      name: 'gallery-drafts',
      component: GalleryView,
      meta: { adminOnly: true },
    },
    {
      path: '/gallery/published/post/:id(\\d+)',
      name: 'gallery-published-post',
      component: GalleryView,
      props: true,
      meta: { public: true },
    },
    {
      path: '/gallery/drafts/post/:id(\\d+)',
      name: 'gallery-drafts-post',
      component: GalleryView,
      props: true,
      meta: { adminOnly: true },
    },
    {
      path: '/posts/:id(\\d+)',
      name: 'post',
      component: PostView,
      props: true,
      meta: { public: true },
    },
    {
      path: '/posts/new/edit',
      name: 'edit-post-new',
      component: EditPostView,
      meta: { adminOnly: true },
    },
    {
      path: '/posts/:post_id(\\d+)/edit',
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

  /** Rotas visitáveis sem login: login, galeria publicada, post isolado `/posts/:id`, post na URL da galeria. */
  const isPublic = to.matched.some((record) => record.meta.public === true)

  if (!isPublic && !hasValidSession) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  if (to.matched.some((record) => record.meta.adminOnly === true) && !storedUserIsAdmin()) {
    return { name: 'gallery-published' }
  }
  return true
})
