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
  return (
    [profile?.first_name, profile?.last_name].filter(Boolean).join(' ') || authStore.currentUser?.username || 'User'
  )
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

</script>

<template>
  <header class="navbar">
    <button class="menu-button" type="button" aria-label="Toggle sidebar" @click="emit('toggle-sidebar')">
      <span></span>
      <span></span>
      <span></span>
    </button>

    <div>
      <p class="navbar-label">COLEGIO DE MONTALBAN</p>
      <h1>{{ title === 'CDM Portal' ? pageTitle : title }}</h1>
    </div>

    <div class="navbar-actions">
      <button v-if="canInstall" class="install-button" type="button" @click="installApp">Install App</button>

      <div class="navbar-user" aria-label="Signed in user">
        <span class="user-avatar">{{ initials }}</span>
        <span class="user-name">
          <strong>{{ displayName }}</strong>
          <small>{{ authStore.currentRole }}</small>
        </span>
        <RouterLink class="settings-button" :to="{ name: 'settings' }" aria-label="Settings" title="Settings">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path
              d="M12 15.25A3.25 3.25 0 1 0 12 8.75a3.25 3.25 0 0 0 0 6.5ZM19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.86 2.86-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21H9.4v-.1A1.7 1.7 0 0 0 9 19.8a1.7 1.7 0 0 0-1-.6 1.7 1.7 0 0 0-1.88.34l-.06.06-2.86-2.86.06-.06A1.7 1.7 0 0 0 3.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H1.8V9.4h.1A1.7 1.7 0 0 0 3 9a1.7 1.7 0 0 0 .6-1 1.7 1.7 0 0 0-.34-1.88l-.06-.06L6.06 3.2l.06.06A1.7 1.7 0 0 0 8 3.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1v-.1h4.2v.1A1.7 1.7 0 0 0 14 3a1.7 1.7 0 0 0 1 .6 1.7 1.7 0 0 0 1.88-.34l.06-.06 2.86 2.86-.06.06A1.7 1.7 0 0 0 19.4 8c.12.4.33.74.6 1 .3.27.7.4 1.1.4h.1v4.2h-.1c-.4 0-.8.13-1.1.4-.27.26-.48.6-.6 1Z"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
        </RouterLink>
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

.settings-button {
  align-items: center;
  background: rgba(13, 120, 86, 0.08);
  border: 1px solid rgba(16, 106, 46, 0.18);
  border-radius: 50%;
  color: var(--color-dartmouth-green);
  display: inline-flex;
  flex: 0 0 38px;
  height: 38px;
  justify-content: center;
  padding: 0;
  width: 38px;
}

.settings-button:hover,
.settings-button.router-link-active {
  background: rgba(244, 211, 94, 0.34);
  border-color: rgba(244, 211, 94, 0.75);
  color: #0c5c2b;
}

.settings-button svg {
  height: 18px;
  width: 18px;
}

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
.user-name strong,
.user-name small {
  display: block;
}
.user-name strong {
  font-size: 0.86rem;
}
.user-name small {
  color: var(--color-muted);
  font-size: 0.72rem;
  font-weight: 500;
  margin-top: 2px;
}

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
