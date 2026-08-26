<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import logoUrl from '../assets/styles/images/cdm_logo.png'
import { useAuthStore } from '../stores/authStore'
import { useNavigationStore } from '../stores/navigation'

defineProps({
  isOpen: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['close'])
const navigationStore = useNavigationStore()
const authStore = useAuthStore()
const route = useRoute()
const router = useRouter()
const expandedItems = ref([])
const signingOut = ref(false)

const displayName = computed(() => {
  const profile = authStore.currentUser?.profile
  return (
    [profile?.first_name, profile?.last_name].filter(Boolean).join(' ') ||
    authStore.currentUser?.username ||
    'Portal User'
  )
})
const initials = computed(() =>
  displayName.value
    .split(' ')
    .map((part) => part[0])
    .slice(0, 2)
    .join('')
    .toUpperCase(),
)
const menuItems = computed(() => navigationStore.menuItemsForRole(authStore.currentRole))
const isExpanded = (name) => expandedItems.value.includes(name)
const isChildActive = (item) =>
  item.children?.some((child) => child.path === route.path || child.activeRoutes?.includes(route.name))
const toggleItem = (name) => {
  expandedItems.value = isExpanded(name)
    ? expandedItems.value.filter((item) => item !== name)
    : [...expandedItems.value, name]
}

watch(
  [() => route.path, () => authStore.currentRole],
  () => {
    const activeParent = menuItems.value.find((item) => isChildActive(item))
    if (activeParent && !isExpanded(activeParent.name)) expandedItems.value.push(activeParent.name)
  },
  { immediate: true },
)

const dashboardIcon = 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z'
const iconPath = (name) =>
  ({
    'guest-dashboard': dashboardIcon,
    'student-dashboard': dashboardIcon,
    'professor-dashboard': dashboardIcon,
    'registrar-dashboard': dashboardIcon,
    'admin-dashboard': dashboardIcon,
    'guest-profile': 'M20 21a8 8 0 0 0-16 0M12 13a5 5 0 1 0 0-10 5 5 0 0 0 0 10z',
    'student-management': 'M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6M9 10h.01M15 10h.01',
    'student-management-menu': 'M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6M9 10h.01M15 10h.01',
    admission: 'M12 3v18M3 12h18',
    enrollment: 'M4 5h16v14H4zM8 9h8M8 13h5',
    grading: 'M4 19V5l8-3 8 3v14l-8 3zM9 12l2 2 4-4',
    monitoring: 'M4 19V5M8 15v4M12 9v10M16 12v7M20 5v14',
    'document-requests-menu': 'M7 3h7l4 4v14H7zM14 3v5h5M10 12h5M10 16h5',
    'event-attendance': 'M5 4h14v16H5zM8 2v4M16 2v4M8 10h8M8 14h5',
    appointments: 'M12 8v4l3 2M4 5h16v16H4z',
  })[name] || 'M4 5h16v14H4zM8 9h8M8 13h5'

const logout = async () => {
  if (signingOut.value) return

  signingOut.value = true
  try {
    await authStore.logout()
    emit('close')
    await router.replace({ name: 'login' })
  } finally {
    signingOut.value = false
  }
}
</script>

<template>
  <aside class="sidebar" :class="{ 'is-open': isOpen }">
    <header class="brand">
      <img class="brand-mark" :src="logoUrl" alt="Colegio de Montalban seal" />
      <strong>COLEGIO DE MONTALBAN</strong>
    </header>

    <nav class="sidebar-nav" aria-label="Main navigation">
      <template v-for="item in menuItems" :key="item.name">
        <div v-if="item.children" class="nav-group">
          <button
            type="button"
            class="nav-link nav-parent"
            :class="{
              'is-active': isChildActive(item) || isExpanded(item.name),
            }"
            :aria-expanded="isExpanded(item.name)"
            @click="toggleItem(item.name)"
          >
            <svg
              class="nav-icon"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.9"
              aria-hidden="true"
            >
              <path :d="iconPath(item.name)" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <span>{{ item.label }}</span>
            <svg
              class="nav-chevron"
              :class="{ expanded: isExpanded(item.name) }"
              viewBox="0 0 20 20"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              aria-hidden="true"
            >
              <path d="m7 5 5 5-5 5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </button>
          <div v-show="isExpanded(item.name)" class="nav-children">
            <RouterLink
              v-for="child in item.children"
              :key="child.name"
              :to="child.path"
              class="nav-link nav-child"
              active-class=""
              exact-active-class="router-link-active"
              @click="emit('close')"
            >
              {{ child.label }}
            </RouterLink>
          </div>
        </div>

        <RouterLink v-else :to="item.path" class="nav-link" @click="emit('close')">
          <svg
            class="nav-icon"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.9"
            aria-hidden="true"
          >
            <path :d="iconPath(item.name)" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
          <span>{{ item.label }}</span>
        </RouterLink>
      </template>
    </nav>

    <footer class="sidebar-footer">
      <div class="sidebar-user" aria-label="Signed in user">
        <span class="sidebar-avatar">{{ initials }}</span>
        <div class="user-copy">
          <strong>{{ displayName }}</strong>
          <small>{{ authStore.currentRole }}</small>
        </div>
      </div>

      <button class="sign-out-button" type="button" :disabled="signingOut" @click="logout">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
          <path d="M10 5H5v14h5M14 8l4 4-4 4M18 12H9" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        <span>{{ signingOut ? 'Signing out…' : 'Sign out' }}</span>
      </button>
    </footer>
  </aside>
</template>

<style scoped>
.sidebar {
  background: linear-gradient(170deg, #063c26 0%, #0a6332 52%, #6eae16 100%);
  box-shadow: 8px 0 28px rgba(4, 50, 28, 0.16);
  color: #fff;
  display: flex;
  flex-direction: column;
  inset: 0 auto 0 0;
  overflow: hidden;
  padding: 22px 13px 18px;
  position: fixed;
  transform: translateX(0);
  transition: transform 180ms ease;
  width: var(--sidebar-width, 232px);
  z-index: 30;
}

.brand {
  align-items: center;
  border-bottom: 1px solid rgba(255, 255, 255, 0.22);
  display: flex;
  flex: 0 0 auto;
  gap: 10px;
  margin: 0 4px 22px;
  padding: 0 2px 20px;
}

.brand-mark {
  background: rgba(255, 255, 255, 0.96);
  border: 2px solid rgba(244, 211, 94, 0.88);
  border-radius: 50%;
  flex: 0 0 auto;
  height: 48px;
  object-fit: contain;
  width: 48px;
}

.brand strong {
  color: #fff;
  font-family: Georgia, 'Times New Roman', serif;
  font-size: 0.78rem;
  letter-spacing: 0.045em;
  line-height: 1.32;
  text-wrap: balance;
}

.sidebar-nav {
  align-content: start;
  display: grid;
  flex: 1 1 auto;
  gap: 6px;
  min-height: 0;
  overflow-x: hidden;
  overflow-y: auto;
  padding: 0 1px 18px;
  -ms-overflow-style: none;
  scrollbar-width: none;
}

.sidebar-nav::-webkit-scrollbar {
  display: none;
  height: 0;
  width: 0;
}

.nav-link {
  align-items: center;
  border-radius: 10px;
  color: rgba(255, 255, 255, 0.92);
  display: flex;
  font-size: 0.88rem;
  font-weight: 600;
  gap: 11px;
  line-height: 1.2;
  min-height: 42px;
  padding: 9px 11px;
  position: relative;
}

.nav-parent {
  background: transparent;
  border: 0;
  cursor: pointer;
  font: inherit;
  text-align: left;
  width: 100%;
}

.nav-link:hover {
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
}

.nav-parent.is-active,
.nav-parent.is-active:hover,
.nav-link.router-link-active,
.nav-link.router-link-active:hover {
  background: linear-gradient(90deg, #c9df45, #a8cf38);
  box-shadow: 0 7px 18px rgba(2, 45, 24, 0.22);
  color: #174520;
}

.nav-parent.is-active::before,
.nav-link.router-link-active::before {
  background: #fff05a;
  border-radius: 999px;
  content: '';
  height: 24px;
  left: 0;
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  width: 4px;
}

.nav-chevron {
  flex: 0 0 auto;
  height: 16px;
  margin-left: auto;
  transform: rotate(0deg);
  transition: transform 160ms ease;
  width: 16px;
}

.nav-chevron.expanded {
  transform: rotate(90deg);
}

.nav-children {
  display: grid;
  gap: 3px;
  margin: 4px 0 3px 29px;
}

.nav-child {
  background: transparent;
  color: rgba(255, 255, 255, 0.86);
  font-size: 0.84rem;
  min-height: 36px;
  padding: 8px 10px;
}

.nav-icon {
  flex: 0 0 auto;
  height: 19px;
  width: 19px;
}

.sidebar-footer {
  border-top: 1px solid rgba(255, 255, 255, 0.28);
  flex: 0 0 auto;
  padding: 16px 6px 0;
}

.sidebar-user,
.sign-out-button {
  align-items: center;
  display: flex;
  gap: 9px;
}

.sidebar-user strong,
.sidebar-user small {
  display: block;
}

.user-copy {
  min-width: 0;
}

.sidebar-user strong {
  font-size: 0.82rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.sidebar-user small {
  color: rgba(255, 255, 255, 0.72);
  font-size: 0.61rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  margin-top: 3px;
  overflow: hidden;
  text-overflow: ellipsis;
  text-transform: uppercase;
  white-space: nowrap;
}

.sidebar-avatar {
  align-items: center;
  background: rgba(255, 255, 255, 0.14);
  border: 2px solid rgba(255, 255, 255, 0.88);
  border-radius: 50%;
  color: #fff;
  display: flex;
  flex: 0 0 auto;
  font-size: 0.68rem;
  font-weight: 800;
  height: 36px;
  justify-content: center;
  width: 36px;
}

.sign-out-button {
  background: transparent;
  border: 0;
  border-radius: 8px;
  color: rgba(255, 255, 255, 0.9);
  cursor: pointer;
  font-size: 0.79rem;
  margin-top: 13px;
  min-height: 38px;
  padding: 7px 8px;
  width: 100%;
}

.sign-out-button:hover {
  background: rgba(255, 255, 255, 0.13);
  color: #fff;
}

.sign-out-button:disabled {
  cursor: wait;
  opacity: 0.65;
}

.sign-out-button svg {
  height: 18px;
  width: 18px;
}

@media (max-width: 860px) {
  .sidebar {
    box-shadow: 12px 0 32px rgba(3, 32, 18, 0.3);
    transform: translateX(-100%);
  }

  .sidebar.is-open {
    transform: translateX(0);
  }
}
</style>
