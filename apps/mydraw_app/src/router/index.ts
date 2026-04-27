import { createRouter, createWebHistory } from 'vue-router'
import GalleryView from '@/views/GalleryView.vue'
import LoginView from '@/views/LoginView.vue'
import EditPostView from '@/views/EditPostView.vue'
import PostView from '@/views/PostView.vue'
import { isJwtExpired } from '@/utils/jwt'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      redirect: { name: 'gallery-published' },
    },
    {
      path: '/gallery',
      redirect: { name: 'gallery-published' },
    },
    {
      path: '/gallery/published',
      name: 'gallery-published',
      component: GalleryView,
    },
    {
      path: '/gallery/drafts',
      name: 'gallery-drafts',
      component: GalleryView,
      meta: { requiresAuth: true },
    },
    {
      path: '/gallery/published/post/:id(\\d+)',
      name: 'gallery-published-post',
      component: GalleryView,
      props: true,
    },
    {
      path: '/gallery/drafts/post/:id(\\d+)',
      name: 'gallery-drafts-post',
      component: GalleryView,
      props: true,
      meta: { requiresAuth: true },
    },
    {
      path: '/posts/:id(\\d+)',
      name: 'post',
      component: PostView,
      props: true,
    },
    {
      path: '/posts/new/edit',
      name: 'edit-post-new',
      component: EditPostView,
      meta: { requiresAuth: true },
    },
    {
      path: '/posts/:post_id(\\d+)/edit',
      name: 'edit-post',
      component: EditPostView,
      props: true,
      meta: { requiresAuth: true },
    },
    {
      path: '/create',
      redirect: { name: 'edit-post-new' },
    },
    {
      path: '/login',
      name: 'login',
      component: LoginView,
      meta: { guestOnly: true },
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
  if (to.meta.requiresAuth && !hasValidSession) {
    return { path: '/login', query: { redirect: to.fullPath } }
  }
  return true
})
