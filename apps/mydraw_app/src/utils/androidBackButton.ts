import { App } from '@capacitor/app'
import { Capacitor } from '@capacitor/core'
import type { Router, RouteRecordName } from 'vue-router'
import { consumeBackHandler } from '@/utils/backHandlerStack'

/** Página inicial do app — back aqui não fecha o aplicativo. */
export const ANDROID_HOME_ROUTE = 'gallery-published' as const

function isGalleryHome(name: RouteRecordName | null | undefined): boolean {
  return name === ANDROID_HOME_ROUTE
}

/** Navegação do hardware back (Android). */
export function resolveAndroidBackNavigation(router: Router): void {
  if (consumeBackHandler()) return

  const name = router.currentRoute.value.name

  if (name === 'gallery-published-post') {
    void router.push({ name: ANDROID_HOME_ROUTE })
    return
  }
  if (name === 'gallery-drafts-post') {
    void router.push({ name: 'gallery-drafts' })
    return
  }
  if (name === 'gallery-drafts') {
    void router.replace({ name: ANDROID_HOME_ROUTE })
    return
  }
  if (isGalleryHome(name)) {
    return
  }
  if (name === 'login') {
    void router.replace({ name: ANDROID_HOME_ROUTE })
    return
  }

  if (window.history.state?.back != null) {
    router.back()
    return
  }

  void router.replace({ name: ANDROID_HOME_ROUTE })
}

let listenerRegistered = false

export function initAndroidBackButton(router: Router): void {
  if (listenerRegistered) return
  if (!Capacitor.isNativePlatform() || Capacitor.getPlatform() !== 'android') return

  listenerRegistered = true

  void App.addListener('backButton', () => {
    resolveAndroidBackNavigation(router)
  })
}
