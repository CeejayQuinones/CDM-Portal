<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/authStore'

defineProps({
  moduleLabel: { type: String, default: 'Workspace' },
  pageTitle: { type: String, default: 'CDM Portal' },
  showModuleButton: { type: Boolean, default: false },
})
defineEmits(['toggle-module'])

const moduleButton = ref(null)
defineExpose({ focusModuleButton: () => moduleButton.value?.focus() })

const auth = useAuthStore()
const router = useRouter()
const signingOut = ref(false)
const displayName = computed(() => {
  const profile = auth.currentUser?.profile
  return [profile?.first_name, profile?.last_name].filter(Boolean).join(' ') || auth.currentUser?.username || 'Portal User'
})
const initials = computed(() => displayName.value.split(/\s+/).map(part => part[0]).slice(0, 2).join('').toUpperCase())

async function logout() {
  if (signingOut.value) return
  signingOut.value = true
  try {
    await auth.logout()
    await router.replace({ name: 'login' })
  } finally { signingOut.value = false }
}
</script>

<template>
  <header class="desktop-context-header">
    <button v-if="showModuleButton" ref="moduleButton" class="desktop-module-button" type="button" aria-label="Open module navigation" aria-controls="desktop-module-navigation" :aria-expanded="false" title="Open module navigation" @click="$emit('toggle-module')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m15 18 6-6-6-6M21 12H7M3 4v16" /></svg>
    </button>
    <div class="desktop-header-context">
      <p>{{ moduleLabel }} <span aria-hidden="true">/</span> {{ pageTitle }}</p>
      <h1>{{ pageTitle }}</h1>
    </div>
    <div class="desktop-header-account">
      <span class="desktop-header-avatar" aria-hidden="true">{{ initials }}</span>
      <span class="desktop-header-identity"><strong>{{ displayName }}</strong><small>{{ auth.currentRole }}</small></span>
      <button type="button" :disabled="signingOut" @click="logout">{{ signingOut ? 'Signing out…' : 'Sign out' }}</button>
    </div>
  </header>
</template>

