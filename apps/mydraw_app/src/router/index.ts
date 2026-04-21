import { createRouter, createWebHistory } from 'vue-router'
import GalleryView from '@/views/GalleryView.vue'
import LoginView from '@/views/LoginView.vue'
import CreatePostView from '@/views/CreatePostView.vue'
import PostView from '@/views/PostView.vue'
import { isJwtExpired } from '@/utils/jwt'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      redirect: { name: 'gallery' },
    },
    {
      path: '/gallery',
      name: 'gallery',
      component: GalleryView,
    },
    {
      path: '/posts/:id(\\d+)',
      name: 'post',
      component: PostView,
      props: true,
    },
    {
      path: '/create',
      name: 'create-post',
      component: CreatePostView,
      meta: { requiresAuth: true },
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
