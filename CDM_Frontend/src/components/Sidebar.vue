<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import logoUrl from '../assets/styles/images/cdm_logo.png'
import { useAuthStore } from '../stores/authStore'
import { useStudentProfileStore } from '../stores/studentProfile'
import { useNavigationStore } from '../stores/navigation'

const props = defineProps({
  isOpen: {
    type: Boolean,
    default: false,
  },
  collapsed: {
    type: Boolean,
    default: false,
  },
  collapsible: {
    type: Boolean,
    default: false,
  },
  mobileDrawer: {
    type: Boolean,
    default: false,
  },
  websitePresentation: {
    type: Boolean,
    default: false,
  },
  desktopPresentation: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['close', 'toggle-collapse'])
const navigationStore = useNavigationStore()
const authStore = useAuthStore()
const studentProfile = useStudentProfileStore()
const avatarFailed = ref(false)
watch(() => studentProfile.revision, () => { avatarFailed.value = false })
const route = useRoute()
const router = useRouter()
const expandedItems = ref([])
const signingOut = ref(false)
const sidebarRoot = ref(null)

const displayName = computed(() => {
  if (authStore.currentRole === 'Student' && studentProfile.preferredDisplayName) {
    return studentProfile.preferredDisplayName
  }
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
const menuItems = computed(() => navigationStore.menuItemsForRole(authStore.currentRole, authStore.currentUser?.admission))
const isExpanded = (name) => expandedItems.value.includes(name)
const isChildActive = (item) =>
  item.children?.some((child) => child.path === route.path || child.activeRoutes?.includes(route.name))
const isItemActive = (item) => item.activeRoutes?.includes(route.name)
const toggleItem = (name) => {
  expandedItems.value = isExpanded(name)
    ? expandedItems.value.filter((item) => item !== name)
    : [...expandedItems.value, name]
}
const handleParentClick = (name) => {
  if (props.collapsible && props.collapsed) emit('toggle-collapse')
  toggleItem(name)
}

const drawerFocusables = () => Array.from(sidebarRoot.value?.querySelectorAll(
  'a[href], button:not(:disabled), [tabindex]:not([tabindex="-1"])',
) || []).filter(element => !element.hasAttribute('disabled') && element.getClientRects().length > 0)

const focusFirst = () => nextTick(() => drawerFocusables()[0]?.focus())

const handleDrawerKeydown = event => {
  if (!props.mobileDrawer || event.key !== 'Tab') return
  const focusables = drawerFocusables()
  if (!focusables.length) return
  const first = focusables[0]
  const last = focusables.at(-1)
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

defineExpose({ focusFirst })

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
    settings: 'M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.1 2.1-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1.03 1.56V20h-3v-.08a1.7 1.7 0 0 0-1.03-1.56 1.7 1.7 0 0 0-1.88.34l-.06.06-2.1-2.1.06-.06A1.7 1.7 0 0 0 7.06 15 1.7 1.7 0 0 0 5.5 13.97H5v-3h.5A1.7 1.7 0 0 0 7.06 9.4a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.1-2.1.06.06a1.7 1.7 0 0 0 1.88.34A1.7 1.7 0 0 0 11.73 4.2V4h3v.2a1.7 1.7 0 0 0 1.03 1.56 1.7 1.7 0 0 0 1.88-.34l.06-.06 2.1 2.1-.06.06a1.7 1.7 0 0 0-.34 1.88 1.7 1.7 0 0 0 1.56 1.03h.5v3h-.5A1.7 1.7 0 0 0 19.4 15z',
    'document-requests-menu': 'M7 3h7l4 4v14H7zM14 3v5h5M10 12h5M10 16h5',
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
  <aside
    ref="sidebarRoot"
    class="sidebar"
    :class="{ 'is-open': isOpen, 'is-collapsed': collapsible && collapsed, 'is-web-sidebar': websitePresentation, 'is-desktop-sidebar': desktopPresentation }"
    :role="mobileDrawer ? 'dialog' : undefined"
    :aria-modal="mobileDrawer ? 'true' : undefined"
    :aria-label="mobileDrawer ? 'Portal navigation' : undefined"
    @keydown="handleDrawerKeydown"
  >
    <header class="brand">
      <div class="brand-identity">
        <img class="brand-mark" :src="logoUrl" alt="Colegio de Montalban seal" />
        <strong>COLEGIO DE MONTALBAN</strong>
      </div>
      <button
        v-if="collapsible"
        class="sidebar-collapse-button"
        type="button"
        :aria-label="collapsed ? 'Expand navigation sidebar' : 'Collapse navigation sidebar'"
        :title="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
        @click="emit('toggle-collapse')"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="collapsed ? 'm9 6 6 6-6 6' : 'm15 6-6 6 6 6'" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </button>
      <button
        v-if="mobileDrawer"
        class="sidebar-drawer-close"
        type="button"
        aria-label="Close navigation menu"
        title="Close navigation"
        @click="emit('close', true)"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path d="M6 6l12 12M18 6 6 18" stroke-linecap="round" />
        </svg>
      </button>
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
            :aria-label="collapsed ? item.label : undefined"
            :title="collapsed ? item.label : undefined"
            @click="handleParentClick(item.name)"
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
            <span class="nav-label">{{ item.label }}</span>
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
          <Transition name="nav-children">
            <div v-if="isExpanded(item.name)" class="nav-children">
              <template v-for="child in item.children" :key="child.name">
              <span v-if="child.comingSoon" class="nav-link nav-child is-disabled" aria-disabled="true">
                <span class="nav-label">{{ child.label }}</span> <small>{{ child.phaseLabel || 'Coming soon' }}</small>
              </span>
              <RouterLink
                v-else
                :key="child.name"
                :to="child.path"
                class="nav-link nav-child"
                active-class=""
                exact-active-class="router-link-active"
                @click="emit('close')"
              >
                <span class="nav-label">{{ child.label }}</span>
              </RouterLink>
              </template>
            </div>
          </Transition>
        </div>

        <RouterLink
          v-else
          :to="item.path"
          class="nav-link"
          :class="{ 'router-link-active': isItemActive(item) }"
          :aria-label="collapsed ? item.label : undefined"
          :title="collapsed ? item.label : undefined"
          @click="emit('close')"
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
          <span class="nav-label">{{ item.label }}</span>
        </RouterLink>
      </template>
    </nav>

    <footer class="sidebar-footer">
      <div class="sidebar-user" :aria-label="`Signed in as ${displayName}, ${authStore.currentRole}`" :title="collapsed ? `${displayName} · ${authStore.currentRole}` : undefined">
        <span class="sidebar-avatar">
          <img v-if="studentProfile.avatarUrl && !avatarFailed" :key="studentProfile.revision" :src="studentProfile.avatarUrl" alt="Profile picture" @error="avatarFailed = true" />
          <template v-else>{{ initials }}</template>
        </span>
        <div class="user-copy">
          <strong>{{ displayName }}</strong>
          <small>{{ authStore.currentRole }}</small>
        </div>
      </div>

      <button class="sign-out-button" type="button" :disabled="signingOut" :aria-label="collapsed ? 'Sign out' : undefined" :title="collapsed ? 'Sign out' : undefined" @click="logout">
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
  transition: transform 180ms ease, width 220ms cubic-bezier(0.22, 1, 0.36, 1), padding 220ms ease;
  width: var(--sidebar-width, 232px);
  z-index: 30;
}

.brand {
  align-items: center;
  border-bottom: 1px solid rgba(255, 255, 255, 0.22);
  display: flex;
  flex: 0 0 auto;
  gap: 8px;
  justify-content: space-between;
  margin: 0 4px 22px;
  padding: 0 2px 20px;
}

.brand-identity {
  align-items: center;
  display: flex;
  gap: 10px;
  min-width: 0;
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

.sidebar-collapse-button,
.sidebar-drawer-close {
  align-items: center;
  background: rgba(255, 255, 255, 0.12);
  border: 1px solid rgba(255, 255, 255, 0.28);
  border-radius: 9px;
  color: #fff;
  cursor: pointer;
  display: inline-flex;
  flex: 0 0 auto;
  height: 36px;
  justify-content: center;
  padding: 0;
  width: 36px;
}

.sidebar-collapse-button:hover,
.sidebar-drawer-close:hover {
  background: rgba(255, 255, 255, 0.2);
}

.sidebar-collapse-button:focus-visible,
.sidebar-drawer-close:focus-visible {
  outline: 2px solid #fff05a;
  outline-offset: 2px;
}

.sidebar-collapse-button svg,
.sidebar-drawer-close svg {
  height: 18px;
  width: 18px;
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
  transition:
    background-color 170ms ease,
    box-shadow 170ms ease,
    color 170ms ease;
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
  background: rgba(255, 255, 255, 0.18);
  box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.08);
  color: #fff;
}

.nav-link:focus-visible,
.sign-out-button:focus-visible {
  outline: 2px solid #fff05a;
  outline-offset: 2px;
}

.nav-parent.is-active,
.nav-parent.is-active:hover,
.nav-link.router-link-active,
.nav-link.router-link-active:hover {
  background: linear-gradient(90deg, #c9df45, #a8cf38);
  box-shadow: 0 7px 18px rgba(2, 45, 24, 0.22);
  color: #174520;
}

.is-web-sidebar .nav-parent.is-active,
.is-web-sidebar .nav-parent.is-active:hover,
.is-web-sidebar .nav-link.router-link-active,
.is-web-sidebar .nav-link.router-link-active:hover {
  background: rgba(0, 69, 45, 0.9);
  box-shadow: inset 0 0 0 1px rgba(244, 211, 94, 0.3), 0 8px 18px rgba(2, 34, 20, 0.2);
  color: #fff;
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
  overflow: hidden;
}

.nav-child.is-disabled { cursor: not-allowed; opacity: .52; }
.nav-child.is-disabled small { font-size: .58rem; margin-left: auto; text-transform: uppercase; }

.nav-children-enter-active,
.nav-children-leave-active {
  max-height: 440px;
  opacity: 1;
  transition:
    max-height 190ms ease,
    opacity 160ms ease,
    margin 190ms ease;
}

.nav-children-enter-from,
.nav-children-leave-to {
  margin-bottom: 0;
  margin-top: 0;
  max-height: 0;
  opacity: 0;
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

.sidebar-avatar img {
  width: 100%;
  height: 100%;
  border-radius: inherit;
  object-fit: cover;
  display: block;
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
  background: rgba(255, 255, 255, 0.18);
  box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.1);
  color: #fff;
}

.sign-out-button:active {
  background: rgba(0, 0, 0, 0.12);
}

.sign-out-button:disabled {
  cursor: wait;
  opacity: 0.65;
}

.sign-out-button svg {
  height: 18px;
  width: 18px;
}

.sidebar.is-collapsed {
  padding-right: 12px;
  padding-left: 12px;
}

.sidebar.is-collapsed .brand {
  align-items: center;
  flex-direction: column;
  margin-right: 0;
  margin-left: 0;
  padding-right: 0;
  padding-left: 0;
}

.sidebar.is-collapsed .brand-identity {
  justify-content: center;
}

.sidebar.is-collapsed .brand-mark {
  height: 46px;
  width: 46px;
}

.sidebar.is-collapsed .brand strong,
.sidebar.is-collapsed .nav-label,
.sidebar.is-collapsed .nav-chevron,
.sidebar.is-collapsed .user-copy,
.sidebar.is-collapsed .sign-out-button span,
.sidebar.is-collapsed .nav-children {
  display: none;
}

.sidebar.is-collapsed .nav-link,
.sidebar.is-collapsed .sidebar-user,
.sidebar.is-collapsed .sign-out-button {
  justify-content: center;
}

.sidebar.is-collapsed .nav-link {
  gap: 0;
  min-height: 48px;
  padding: 10px;
}

.sidebar.is-collapsed .nav-parent.is-active::before,
.sidebar.is-collapsed .nav-link.router-link-active::before {
  height: 28px;
  left: -5px;
}

.sidebar.is-collapsed .nav-icon {
  height: 22px;
  width: 22px;
}

.sidebar.is-collapsed .sidebar-footer {
  padding-right: 0;
  padding-left: 0;
}

.sidebar.is-collapsed .sign-out-button {
  padding: 7px;
}

@media (max-width: 860px) {
  .sidebar {
    width: min(86vw, 320px);
    height: 100vh;
    height: 100dvh;
    box-shadow: 12px 0 32px rgba(3, 32, 18, 0.3);
    padding:
      calc(18px + env(safe-area-inset-top))
      13px
      calc(14px + env(safe-area-inset-bottom));
    transform: translateX(-100%);
  }

  .sidebar.is-collapsed {
    padding:
      calc(18px + env(safe-area-inset-top))
      13px
      calc(14px + env(safe-area-inset-bottom));
  }

  .sidebar.is-web-sidebar {
    max-width: 100%;
    padding:
      calc(18px + env(safe-area-inset-top))
      max(13px, env(safe-area-inset-right))
      calc(14px + env(safe-area-inset-bottom))
      max(13px, env(safe-area-inset-left));
  }

  .sidebar.is-open {
    transform: translateX(0);
  }
}

@media (prefers-reduced-motion: reduce) {
  .sidebar,
  .nav-link,
  .sidebar-collapse-button,
  .sidebar-drawer-close,
  .nav-chevron,
  .nav-children-enter-active,
  .nav-children-leave-active {
    transition-duration: 0.01ms;
  }
}
</style>
