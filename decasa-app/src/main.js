import { createApp } from 'vue'
import { createPinia } from 'pinia'
import '@/assets/main.css'
import '@/plugins/echo'

import App from './App.vue'
import router from './router'
import AppSpinner from '@/components/common/AppSpinner.vue'

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.component('AppSpinner', AppSpinner)

app.mount('#app')

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js')
      .then((reg) => console.info('[PWA] Service worker registrado', reg.scope))
      .catch((err) => console.warn('[PWA] Error al registrar service worker:', err))
  })
}
