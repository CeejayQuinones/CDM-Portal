<script setup>
import { computed, defineAsyncComponent, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import CDMBuddy from '../components/CDMBuddy.vue'
import Navbar from '../components/Navbar.vue'
import PortalAnimatedBackdrop from '../components/PortalAnimatedBackdrop.vue'
import Sidebar from '../components/Sidebar.vue'
import StepUpAuthModal from '../components/StepUpAuthModal.vue'
import { clientPlatform } from '../config/clientPlatform'
import { useAuthStore } from '../stores/authStore'
import { useStudentTheme } from '../composables/useStudentTheme'
import {
  readWebsiteSidebarPreference,
  WEBSITE_DESKTOP_SIDEBAR_QUERY,
  WEBSITE_SIDEBAR_LAYOUT_EVENT,
  writeWebsiteSidebarPreference,
} from '../composables/useWebsiteSidebar'

const isSidebarOpen = ref(false)
const WebsiteAppShell = defineAsyncComponent(() => import('./WebsiteAppShell.vue'))
const DesktopAppShell = defineAsyncComponent(() => import('./DesktopAppShell.vue'))
const desktopSidebarCollapsed = ref(false)
const isDesktopViewport = ref(typeof window !== 'undefined' && window.matchMedia(WEBSITE_DESKTOP_SIDEBAR_QUERY).matches)
const sidebar = ref(null)
const navbar = ref(null)
const authStore = useAuthStore()
const route = useRoute()
const isWebsitePresentation = clientPlatform === 'web'
const isDesktopPresentation = clientPlatform === 'desktop'
const isStudentTheme = computed(() => authStore.currentRole === 'Student')
const isStaffTheme = computed(() => ['Registrar Staff', 'Admin'].includes(authStore.currentRole))
const accountKey = computed(() => authStore.currentUser?.id || authStore.currentUser?.username || 'session')
const isDesktopWebsite = computed(() => isWebsitePresentation && isDesktopViewport.value)
const isMobileWebsite = computed(() => isWebsitePresentation && !isDesktopViewport.value)
const isDesktopShellWide = computed(() => isDesktopPresentation && isDesktopViewport.value)
const isDesktopShellDrawer = computed(() => isDesktopPresentation && !isDesktopViewport.value)
let desktopMedia

const notifySidebarLayout = () => nextTick(() => {
  if (!isWebsitePresentation) return
  window.dispatchEvent(new CustomEvent(WEBSITE_SIDEBAR_LAYOUT_EVENT))
})
const restoreDesktopPreference = () => {
  if (isWebsitePresentation) {
    desktopSidebarCollapsed.value = readWebsiteSidebarPreference(localStorage, accountKey.value)
    return
  }
  if (isDesktopPresentation) {
    desktopSidebarCollapsed.value = localStorage.getItem(`cdm-desktop-sidebar:${accountKey.value}`) === 'collapsed'
  }
}
const closeSidebar = (restoreFocus = false) => {
  isSidebarOpen.value = false
  if (restoreFocus && (isMobileWebsite.value || isDesktopShellDrawer.value)) nextTick(() => navbar.value?.focusMenuButton())
}
const openSidebar = () => {
  isSidebarOpen.value = true
  if (isMobileWebsite.value || isDesktopShellDrawer.value) nextTick(() => sidebar.value?.focusFirst())
}
const toggleSidebar = () => {
  if (isSidebarOpen.value) closeSidebar(true)
  else openSidebar()
}
const toggleDesktopSidebar = () => {
  if (!isDesktopWebsite.value && !isDesktopShellWide.value) return
  desktopSidebarCollapsed.value = !desktopSidebarCollapsed.value
  if (isDesktopWebsite.value) writeWebsiteSidebarPreference(localStorage, accountKey.value, desktopSidebarCollapsed.value)
  else localStorage.setItem(`cdm-desktop-sidebar:${accountKey.value}`, desktopSidebarCollapsed.value ? 'collapsed' : 'expanded')
  notifySidebarLayout()
}
const handleEscape = (event) => {
  if (event.key === 'Escape' && isSidebarOpen.value) closeSidebar(true)
}
const updateDesktopViewport = event => {
  isDesktopViewport.value = event.matches
  if (event.matches) closeSidebar()
  notifySidebarLayout()
}

watch(() => route.fullPath, () => {
  if (isSidebarOpen.value) closeSidebar()
  notifySidebarLayout()
})
watch(accountKey, restoreDesktopPreference)
watch(() => (isMobileWebsite.value || isDesktopShellDrawer.value) && isSidebarOpen.value, locked => {
  document.documentElement.classList.toggle('website-sidebar-drawer-open', locked)
})

onMounted(() => {
  if (isWebsitePresentation || isDesktopPresentation) return
  desktopMedia = window.matchMedia(WEBSITE_DESKTOP_SIDEBAR_QUERY)
  isDesktopViewport.value = desktopMedia.matches
  desktopMedia.addEventListener?.('change', updateDesktopViewport)
  window.addEventListener('keydown', handleEscape)
  restoreDesktopPreference()
})
onBeforeUnmount(() => {
  if (isWebsitePresentation || isDesktopPresentation) return
  document.documentElement.classList.remove('website-sidebar-drawer-open')
  desktopMedia?.removeEventListener?.('change', updateDesktopViewport)
  window.removeEventListener('keydown', handleEscape)
})

useStudentTheme(authStore)
</script>

<template>
  <WebsiteAppShell v-if="isWebsitePresentation" />
  <DesktopAppShell v-else-if="isDesktopPresentation" />
  <div v-else class="app-shell" :class="{ 'student-portal-shell': isStudentTheme, 'registrar-theme-shell': isStaffTheme }">
    <StepUpAuthModal />
    <Sidebar
      ref="sidebar"
      :is-open="isSidebarOpen"
      :collapsed="(isDesktopWebsite || isDesktopShellWide) && desktopSidebarCollapsed"
      :collapsible="isDesktopWebsite || isDesktopShellWide"
      :mobile-drawer="(isMobileWebsite || isDesktopShellDrawer) && isSidebarOpen"
      :website-presentation="isWebsitePresentation"
      :desktop-presentation="isDesktopPresentation"
      @close="restoreFocus => closeSidebar(Boolean(restoreFocus))"
      @toggle-collapse="toggleDesktopSidebar"
    />

    <div v-if="isSidebarOpen && (isDesktopShellDrawer || isMobileWebsite)" class="sidebar-backdrop" aria-hidden="true" @click="closeSidebar(true)"></div>

    <div class="shell-content portal-page-shell" :inert="(isMobileWebsite || isDesktopShellDrawer) && isSidebarOpen">
      <PortalAnimatedBackdrop :role="authStore.currentRole" />
      <Navbar ref="navbar" :show-menu-button="isMobileWebsite || isDesktopShellDrawer" :sidebar-open="isSidebarOpen" :desktop-presentation="isDesktopPresentation" @toggle-sidebar="toggleSidebar" />

      <main class="main-content">
        <RouterView v-slot="{ Component, route }">
          <Transition name="portal-route" mode="out-in">
            <div :key="route.path" class="portal-route-view">
              <component :is="Component" />
            </div>
          </Transition>
        </RouterView>
      </main>
      <CDMBuddy />
    </div>
  </div>
</template>

<style scoped>
.app-shell {
  --sidebar-width: 232px;

  min-height: 100vh;
  background: var(--color-anti-flash-white);
}

.shell-content {
  position: relative;
  max-width: 100%;
  min-width: 0;
  min-height: 100vh;
  min-height: 100dvh;
  margin-left: var(--sidebar-width);
}

.portal-page-shell {
  overflow-x: clip;
}

.portal-page-shell > .navbar,
.portal-page-shell > .main-content {
  position: relative;
  z-index: 1;
}

.main-content {
  max-width: 100%;
  min-width: 0;
  width: min(1180px, 100%);
  margin: 0 auto;
  padding: 32px;
}

.portal-route-view {
  min-width: 0;
}

.sidebar-backdrop {
  position: fixed;
  inset: 0;
  z-index: 25;
  background: rgba(31, 31, 31, 0.44);
}

.website-academic-shell .sidebar-backdrop {
  z-index: 29;
}

.website-desktop-sidebar {
  --sidebar-width: 264px;
}

.website-desktop-sidebar.website-sidebar-collapsed {
  --sidebar-width: 84px;
}

.website-desktop-sidebar .shell-content {
  transition: margin-left 220ms cubic-bezier(0.22, 1, 0.36, 1);
}

.website-desktop-sidebar .main-content {
  width: min(1240px, 100%);
  padding: clamp(24px, 3vw, 38px);
}

.portal-route-enter-active,
.portal-route-leave-active {
  transition:
    opacity 170ms ease,
    transform 170ms ease;
}

.portal-route-enter-from {
  opacity: 0;
  transform: translateY(4px);
}

.portal-route-leave-to {
  opacity: 0;
  transform: translateY(-2px);
}

@media (prefers-reduced-motion: reduce) {
  .website-desktop-sidebar .shell-content,
  .portal-route-enter-active,
  .portal-route-leave-active {
    transition-duration: 0.01ms;
  }

  .portal-route-enter-from,
  .portal-route-leave-to {
    transform: none;
  }
}

:global(html.website-sidebar-drawer-open),
:global(html.website-sidebar-drawer-open body) {
  overflow: hidden;
  overscroll-behavior: none;
}

@media (max-width: 860px) {
  .shell-content {
    margin-left: 0;
  }

  .main-content {
    width: 100%;
    padding:
      16px max(16px, env(safe-area-inset-right))
      calc(24px + env(safe-area-inset-bottom))
      max(16px, env(safe-area-inset-left));
  }
}

@media (max-width: 480px) {
  .main-content {
    padding:
      12px max(12px, env(safe-area-inset-right))
      calc(20px + env(safe-area-inset-bottom))
      max(12px, env(safe-area-inset-left));
  }
}
</style>

<style src="../assets/styles/student-portal.css"></style>
<style src="../assets/styles/student-theme.css"></style>
