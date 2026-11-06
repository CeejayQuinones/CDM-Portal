import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import './assets/styles/main.css'
import './design-system/tokens.css'
import './pwa'
import { performanceMonitor } from './services/performance/performanceMonitor'
import { bootstrapPortalAppearance } from './composables/useStudentTheme'
import { clientPlatform } from './config/clientPlatform'

bootstrapPortalAppearance()
document.documentElement.classList.toggle('cdm-desktop-client', clientPlatform === 'desktop')
const app = createApp(App)

app.use(createPinia())
app.use(router)

app.mount('#app')
performanceMonitor.markStartupComplete()
