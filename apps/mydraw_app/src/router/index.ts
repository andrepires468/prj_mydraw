import { createRouter, createWebHistory } from 'vue-router'
import HomeView from '@/views/HomeView.vue'
import GalleryView from '@/views/GalleryView.vue'
import LoginView from '@/views/LoginView.vue'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      name: 'home',
      component: HomeView,
    },
    {
      path: '/gallery',
      name: 'gallery',
      component: GalleryView,
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
  if (to.meta.guestOnly && token) {
    return { path: '/' }
  }
  return true
})
