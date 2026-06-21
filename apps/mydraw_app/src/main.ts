import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import { router } from './router'
import { initNativeShell } from '@/utils/nativeShell'
import { initAndroidBackButton } from '@/utils/androidBackButton'
import './assets/styles/main.css'

const app = createApp(App)

app.use(createPinia())
app.use(router)

void initNativeShell().finally(() => {
  initAndroidBackButton(router)
  app.mount('#app')
})
