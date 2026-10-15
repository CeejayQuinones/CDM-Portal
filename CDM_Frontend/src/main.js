import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import './assets/styles/main.css'
import './pwa'
import { performanceMonitor } from './services/performance/performanceMonitor'

const app = createApp(App)

app.use(createPinia())
app.use(router)

app.mount('#app')
performanceMonitor.markStartupComplete()
