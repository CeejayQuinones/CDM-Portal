<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { navbarContextForRoute } from '../config/navbarContexts'
import { useAppointmentAvailabilityState } from '../modules/document-request/appointmentAvailabilityState'
import { isOfflineDemo } from '../config/demoMode'
import { resetOfflineDemoData } from '../services/offline/offlineSeeder'
import { useAuthStore } from '../stores/authStore'

defineProps({
  title: {
    type: String,
    default: 'CDM Portal',
  },
})

const emit = defineEmits(['toggle-sidebar'])
const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const resettingDemo = ref(false)
const { isOpen: appointmentAvailabilityOpen, open: openAppointmentAvailability } = useAppointmentAvailabilityState()
const installPrompt = ref(null)
const canInstall = ref(false)
const pageTitle = computed(() => router.currentRoute.value.meta.title || 'CDM Portal')
const navbarContext = computed(() =>
  navbarContextForRoute(route, { appointmentAvailabilityOpen: appointmentAvailabilityOpen.value }),
)
const handleInstallPrompt = (event) => {
  event.preventDefault()
  installPrompt.value = event
  canInstall.value = true
}

onMounted(() => {
  window.addEventListener('beforeinstallprompt', handleInstallPrompt)
})

onBeforeUnmount(() => window.removeEventListener('beforeinstallprompt', handleInstallPrompt))

const installApp = async () => {
  if (!installPrompt.value) return

  await installPrompt.value.prompt()
  installPrompt.value = null
  canInstall.value = false
}

const handleContextAction = (action) => {
  if (action === 'open-appointment-availability') openAppointmentAvailability()
}

const resetDemo = async () => {
  if (!window.confirm('Reset all offline demo data to its original classroom sample?')) return
  resettingDemo.value = true
  try {
    await resetOfflineDemoData()
    authStore.clearAuth()
    await router.replace('/login')
  } finally {
    resettingDemo.value = false
  }
}

</script>

<template>
  <header class="navbar">
    <button
      class="menu-button"
      type="button"
      aria-label="Toggle sidebar"
      title="Toggle navigation"
      @click="emit('toggle-sidebar')"
    >
      <span></span>
      <span></span>
      <span></span>
    </button>

    <nav v-if="navbarContext" class="contextual-nav" aria-label="Module navigation">
      <template v-for="item in navbarContext.items" :key="item.key">
        <RouterLink
          v-if="item.to"
          :to="item.to"
          class="contextual-nav-item"
          :class="{ active: item.active }"
          :aria-current="item.active ? 'page' : undefined"
        >
          {{ item.label }}
        </RouterLink>
        <button
          v-else
          type="button"
          class="contextual-nav-item"
          :class="{ active: item.active }"
          :aria-pressed="item.active"
          @click="handleContextAction(item.action)"
        >
          {{ item.label }}
        </button>
      </template>
    </nav>

    <div class="navbar-actions">
      <div v-if="isOfflineDemo" class="demo-controls" aria-label="Offline demo controls">
        <span class="demo-badge">Offline demo</span>
        <button type="button" class="demo-reset" :disabled="resettingDemo" @click="resetDemo">
          {{ resettingDemo ? 'Resetting…' : 'Reset data' }}
        </button>
      </div>
      <button v-if="canInstall" class="install-button" type="button" @click="installApp">Install App</button>
      <div class="navbar-title">
        <p class="navbar-label">COLEGIO DE MONTALBAN</p>
        <h1>{{ title === 'CDM Portal' ? pageTitle : title }}</h1>
      </div>
    </div>
  </header>
</template>

<style scoped>
.navbar {
  position: sticky;
  top: 0;
  z-index: 20;
  display: flex;
  min-height: 72px;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  border-bottom: 1px solid var(--color-border);
  background: rgba(255, 255, 255, 0.94);
  padding: 14px 28px;
  backdrop-filter: blur(12px);
}

.menu-button {
  display: none;
  width: 42px;
  height: 42px;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  gap: 5px;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-surface);
  cursor: pointer;
}

.menu-button:hover {
  background: #eef7f1;
  border-color: rgba(16, 106, 46, 0.38);
  box-shadow: 0 4px 10px rgba(16, 106, 46, 0.1);
}

.menu-button:active {
  background: #e3f0e7;
  box-shadow: none;
}

.menu-button span {
  width: 18px;
  height: 2px;
  border-radius: 999px;
  background: var(--color-eerie-black);
}

.contextual-nav {
  align-self: stretch;
  display: flex;
  flex: 1 1 auto;
  gap: 20px;
  min-width: 0;
  overflow-x: auto;
  scrollbar-width: thin;
}

.contextual-nav-item {
  align-items: center;
  background: transparent;
  border: 0;
  border-bottom: 3px solid transparent;
  color: var(--color-muted);
  cursor: pointer;
  display: inline-flex;
  flex: 0 0 auto;
  font: inherit;
  font-size: 0.92rem;
  font-weight: 700;
  padding: 0 1px;
  text-decoration: none;
  transition:
    border-color 160ms ease,
    color 160ms ease,
    background-color 160ms ease;
  white-space: nowrap;
}

.contextual-nav-item:hover {
  color: var(--color-dark-spring-green);
  background: rgba(13, 120, 86, 0.045);
}

.contextual-nav-item.active {
  border-bottom-color: var(--color-naples-yellow);
  color: var(--color-dartmouth-green);
}

.navbar-label {
  margin: 0 0 2px;
  color: var(--color-dark-spring-green);
  font-size: 0.78rem;
}

h1 {
  margin: 0;
  color: var(--color-eerie-black);
  font-size: 1.25rem;
  line-height: 1.2;
}

.navbar-actions {
  display: flex;
  flex: 0 1 auto;
  align-items: center;
  gap: 14px;
  margin-left: auto;
  min-width: 0;
}

.navbar-title {
  min-width: 0;
  text-align: right;
}

.demo-controls { display: flex; align-items: center; gap: 8px; white-space: nowrap; }
.demo-badge { border-left: 3px solid var(--color-naples-yellow); color: var(--color-dartmouth-green); font-size: .7rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; padding-left: 7px; }
.demo-reset { border: 1px solid rgba(16,106,46,.3); border-radius: 6px; background: #fff; color: var(--color-dark-spring-green); cursor: pointer; font: inherit; font-size: .72rem; font-weight: 700; padding: 5px 8px; }
.demo-reset:hover { background: #eef7f1; border-color: var(--color-dartmouth-green); }
.demo-reset:disabled { cursor: wait; opacity: .6; }

.navbar-title p,
.navbar-title h1 {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.install-button {
  min-height: 38px;
  border: 1px solid var(--color-dartmouth-green);
  border-radius: 8px;
  background: var(--color-dartmouth-green);
  color: var(--color-anti-flash-white);
  padding: 0 14px;
  font-weight: 700;
  cursor: pointer;
}

.install-button:hover {
  background: var(--color-dark-spring-green);
  border-color: #095d41;
  box-shadow: 0 5px 12px rgba(13, 120, 86, 0.18);
}

.menu-button:focus-visible,
.install-button:focus-visible,
.contextual-nav-item:focus-visible {
  outline: 2px solid var(--color-dartmouth-green);
  outline-offset: 3px;
}

@media (max-width: 860px) {
  .navbar {
    padding: 12px 18px;
  }

  .menu-button {
    display: flex;
  }

  .install-button {
    padding: 0 10px;
  }

  .navbar-actions {
    gap: 10px;
    max-width: 42%;
  }

  .contextual-nav {
    gap: 14px;
  }

  .contextual-nav-item {
    font-size: 0.84rem;
  }

  .navbar-title h1 {
    font-size: 1.1rem;
  }
}

@media (max-width: 560px) {
  .navbar {
    gap: 10px;
    padding-inline: 12px;
  }

  .navbar-actions {
    max-width: 36%;
  }

  .demo-badge { display: none; }
  .demo-reset { font-size: 0; padding: 6px; }
  .demo-reset::after { content: 'Reset'; font-size: .68rem; }

  .navbar-label {
    font-size: 0.65rem;
  }

  .navbar-title h1 {
    font-size: 0.96rem;
  }
}

</style>
