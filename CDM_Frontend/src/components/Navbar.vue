<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/authStore'

defineProps({
  title: {
    type: String,
    default: 'CDM Portal',
  },
})

const emit = defineEmits(['toggle-sidebar'])
const router = useRouter()
const authStore = useAuthStore()
const installPrompt = ref(null)
const canInstall = ref(false)
const displayName = computed(() => {
  const profile = authStore.currentUser?.profile
  return [profile?.first_name, profile?.last_name].filter(Boolean).join(' ') || authStore.currentUser?.username || 'User'
})
const initials = computed(() => displayName.value.slice(0, 1).toUpperCase())
const pageTitle = computed(() => router.currentRoute.value.meta.title || 'CDM Portal')

onMounted(() => {
  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault()
    installPrompt.value = event
    canInstall.value = true
  })
})

const installApp = async () => {
  if (!installPrompt.value) return

  await installPrompt.value.prompt()
  installPrompt.value = null
  canInstall.value = false
}

const logout = async () => {
  await authStore.logout()
  router.replace({ name: 'login' })
}
</script>

<template>
  <header class="navbar">
    <button class="menu-button" type="button" aria-label="Toggle sidebar" @click="emit('toggle-sidebar')">
      <span></span>
      <span></span>
      <span></span>
    </button>

    <div>
      <p class="navbar-label">CDM OneServe</p>
      <h1>{{ title === 'CDM Portal' ? pageTitle : title }}</h1>
    </div>

    <div class="navbar-actions">
      <button v-if="canInstall" class="install-button" type="button" @click="installApp">
        Install App
      </button>

      <div class="navbar-user" aria-label="Signed in user">
        <span class="user-avatar">{{ initials }}</span>
        <span class="user-name"><strong>{{ displayName }}</strong><small>{{ authStore.currentRole }}</small></span>
        <button class="logout-button" type="button" @click="logout">Sign out</button>
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
  background: rgba(255,255,255,.94);
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

.navbar-actions,
.navbar-user {
  display: flex;
  align-items: center;
}

.navbar-actions {
  gap: 14px;
}

.navbar-user {
  gap: 10px;
  color: var(--color-eerie-black);
  font-weight: 600;
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

.logout-button { border: 1px solid var(--color-border); border-radius: 8px; background: #fff; color: var(--color-dartmouth-green); font-weight: 700; padding: 8px 11px; cursor: pointer; }

.install-button:hover {
  background: var(--color-dark-spring-green);
}

.user-avatar {
  display: grid;
  width: 36px;
  height: 36px;
  place-items: center;
  border-radius: 50%;
  background: var(--color-naples-yellow);
  color: var(--color-eerie-black);
}
.user-name strong,.user-name small { display:block; }.user-name strong { font-size:.86rem; }.user-name small { color:var(--color-muted); font-size:.72rem; font-weight:500; margin-top:2px; }

@media (max-width: 860px) {
  .navbar {
    padding: 12px 18px;
  }

  .menu-button {
    display: flex;
  }

  .user-name {
    display: none;
  }

  .install-button {
    padding: 0 10px;
  }
}
</style>
