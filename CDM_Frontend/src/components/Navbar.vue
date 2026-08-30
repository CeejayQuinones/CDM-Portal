<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'

defineProps({
  title: {
    type: String,
    default: 'CDM Portal',
  },
})

const emit = defineEmits(['toggle-sidebar'])
const router = useRouter()
const installPrompt = ref(null)
const canInstall = ref(false)
const pageTitle = computed(() => router.currentRoute.value.meta.title || 'CDM Portal')
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

    <div class="navbar-actions">
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
  align-items: center;
  gap: 14px;
  margin-left: auto;
  min-width: 0;
}

.navbar-title {
  min-width: 0;
  text-align: right;
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
.install-button:focus-visible {
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
  }

  .navbar-title h1 {
    font-size: 1.1rem;
  }
}

</style>
