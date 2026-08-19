<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useNavigationStore } from '../stores/navigation'
import { useAuthStore } from '../stores/authStore'
import logoUrl from '../assets/styles/images/cdm_logo.png'

defineProps({
  isOpen: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['close'])
const navigationStore = useNavigationStore()
const authStore = useAuthStore()
const displayName = computed(() => {
  const profile = authStore.currentUser?.profile
  return [profile?.first_name, profile?.last_name].filter(Boolean).join(' ') || authStore.currentUser?.username || 'Portal User'
})
const initials = computed(() => displayName.value.split(' ').map((part) => part[0]).slice(0, 2).join('').toUpperCase())
const iconPath = (name) => ({
  'student-management': 'M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6M9 10h.01M15 10h.01',
  admission: 'M12 3v18M3 12h18', enrollment: 'M4 5h16v14H4zM8 9h8M8 13h5', grading: 'M4 19V5l8-3 8 3v14l-8 3zM9 12l2 2 4-4',
  monitoring: 'M4 19V5M8 15v4M12 9v10M16 12v7M20 5v14', 'document-request': 'M7 3h7l4 4v14H7zM14 3v5h5M10 12h5M10 16h5',
  'event-attendance': 'M5 4h14v16H5zM8 2v4M16 2v4M8 10h8M8 14h5', appointments: 'M12 8v4l3 2M4 5h16v16H4z',
}[name] || 'M4 5h16v14H4zM8 9h8M8 13h5')
</script>

<template>
  <aside class="sidebar" :class="{ 'is-open': isOpen }">
    <div class="brand">
      <img class="brand-mark" :src="logoUrl" alt="CDM logo" />
      <div>
        <strong>CDM Portal</strong>
        <small>OneServe</small>
      </div>
    </div>

    <nav class="sidebar-nav" aria-label="Main navigation">
      <RouterLink
        v-for="item in navigationStore.menuItemsForRole(authStore.currentRole)"
        :key="item.name"
        :to="item.path"
        class="nav-link"
        @click="emit('close')"
      >
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path :d="iconPath(item.name)" stroke-linecap="round" stroke-linejoin="round" /></svg>
        <span>{{ item.label }}</span>
      </RouterLink>
    </nav>
    <div class="sidebar-user"><span class="sidebar-avatar">{{ initials }}</span><div><strong>{{ displayName }}</strong><small>{{ authStore.currentRole }}</small></div></div>
  </aside>
</template>

<style scoped>
.sidebar {
  position: fixed;
  inset: 0 auto 0 0;
  z-index: 30;
  width: 250px;
  border: 0;
  background: linear-gradient(165deg, var(--color-dartmouth-green), var(--color-dark-spring-green));
  padding: 24px 14px 18px;
  transform: translateX(0);
  transition: transform 180ms ease;
}

.brand {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 0 8px 26px;
}

.brand-mark {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  background: #fff;
  object-fit: contain;
  padding: 3px;
}

.brand strong,
.brand small {
  display: block;
}

.brand small {
  margin-top: 2px;
  color: rgba(255,255,255,.82);
}

.brand strong {
  color: #fff;
  font-size: 1.02rem;
}

.sidebar-nav {
  display: grid;
  gap: 7px;
  height: calc(100% - 180px);
}

.nav-link {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 44px;
  border-radius: 11px;
  padding: 10px 12px;
  color: rgba(255,255,255,.9);
  font-weight: 600;
}

.nav-link:hover {
  background: rgba(255,255,255,.13);
  color: var(--color-anti-flash-white);
}

.nav-link.router-link-active {
  background: linear-gradient(90deg, rgba(244,211,94,.98), #fff0b1);
  color: var(--color-dartmouth-green);
  box-shadow: 0 7px 16px rgba(0,0,0,.12);
}

.nav-icon {
  width: 20px;
  height: 20px;
  flex: 0 0 auto;
}

.sidebar-user { display:flex; align-items:center; gap:10px; border-top:1px solid rgba(255,255,255,.18); color:#fff; padding:17px 8px 0; }.sidebar-user strong,.sidebar-user small { display:block; }.sidebar-user strong { font-size:.84rem; }.sidebar-user small { color:rgba(255,255,255,.72); font-size:.75rem; margin-top:2px; }.sidebar-avatar { align-items:center; background:var(--color-naples-yellow); border-radius:50%; color:var(--color-dartmouth-green); display:flex; font-size:.75rem; font-weight:800; height:34px; justify-content:center; width:34px; }

@media (max-width: 860px) {
  .sidebar {
    transform: translateX(-100%);
  }

  .sidebar.is-open {
    transform: translateX(0);
  }
}
</style>
