<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import Navbar from '../components/Navbar.vue'
import PortalAnimatedBackdrop from '../components/PortalAnimatedBackdrop.vue'
import Sidebar from '../components/Sidebar.vue'
import StepUpAuthModal from '../components/StepUpAuthModal.vue'
import { clientPlatform } from '../config/clientPlatform'
import { useAuthStore } from '../stores/authStore'
import { useStudentTheme } from '../composables/useStudentTheme'

const HOVER_SIDEBAR_QUERY = '(min-width: 861px) and (hover: hover) and (pointer: fine)'
const SIDEBAR_CLOSE_DELAY = 220
const isSidebarOpen = ref(false)
const hasHoverPointer = ref(typeof window !== 'undefined' && window.matchMedia(HOVER_SIDEBAR_QUERY).matches)
const pointerInEdge = ref(false)
const pointerInSidebar = ref(false)
const manuallyOpened = ref(false)
const edgeTrigger = ref(null)
const authStore = useAuthStore()
const route = useRoute()
const isStudentTheme = computed(() => authStore.currentRole === 'Student')
const isStaffTheme = computed(() => ['Registrar Staff', 'Admin'].includes(authStore.currentRole))
const usesHoverSidebar = computed(() =>
  clientPlatform === 'web' &&
  ['Student', 'Professor'].includes(authStore.currentRole) &&
  hasHoverPointer.value,
)
let hoverMedia
let closeTimer

const clearCloseTimer = () => {
  if (closeTimer) window.clearTimeout(closeTimer)
  closeTimer = undefined
}
const closeSidebar = (restoreFocus = false) => {
  clearCloseTimer()
  isSidebarOpen.value = false
  manuallyOpened.value = false
  if (restoreFocus && usesHoverSidebar.value) edgeTrigger.value?.focus()
}
const openSidebar = (manual = false) => {
  clearCloseTimer()
  isSidebarOpen.value = true
  if (manual) manuallyOpened.value = true
}
const scheduleHoverClose = () => {
  clearCloseTimer()
  if (!usesHoverSidebar.value || manuallyOpened.value) return
  closeTimer = window.setTimeout(() => {
    if (!pointerInEdge.value && !pointerInSidebar.value && !manuallyOpened.value) {
      isSidebarOpen.value = false
    }
  }, SIDEBAR_CLOSE_DELAY)
}
const handleEdgeEnter = () => {
  pointerInEdge.value = true
  openSidebar()
}
const handleEdgeLeave = () => {
  pointerInEdge.value = false
  scheduleHoverClose()
}
const handleSidebarEnter = () => {
  if (!usesHoverSidebar.value) return
  pointerInSidebar.value = true
  clearCloseTimer()
}
const handleSidebarLeave = () => {
  if (!usesHoverSidebar.value) return
  pointerInSidebar.value = false
  scheduleHoverClose()
}
const toggleSidebar = () => {
  if (!usesHoverSidebar.value) {
    isSidebarOpen.value = !isSidebarOpen.value
    return
  }
  if (isSidebarOpen.value) closeSidebar()
  else openSidebar(true)
}
const handleEscape = (event) => {
  if (event.key === 'Escape' && isSidebarOpen.value) closeSidebar(true)
}
const updateHoverCapability = event => {
  hasHoverPointer.value = event.matches
}

watch(() => route.fullPath, () => closeSidebar())
watch(usesHoverSidebar, enabled => {
  if (!enabled) closeSidebar()
})

onMounted(() => {
  hoverMedia = window.matchMedia(HOVER_SIDEBAR_QUERY)
  hasHoverPointer.value = hoverMedia.matches
  hoverMedia.addEventListener?.('change', updateHoverCapability)
  window.addEventListener('keydown', handleEscape)
})
onBeforeUnmount(() => {
  clearCloseTimer()
  hoverMedia?.removeEventListener?.('change', updateHoverCapability)
  window.removeEventListener('keydown', handleEscape)
})

useStudentTheme(authStore)
</script>

<template>
  <div class="app-shell" :class="{ 'student-portal-shell': isStudentTheme, 'registrar-theme-shell': isStaffTheme, 'hover-sidebar-shell': usesHoverSidebar }">
    <StepUpAuthModal />
    <button
      v-if="usesHoverSidebar"
      ref="edgeTrigger"
      class="sidebar-edge-trigger"
      type="button"
      aria-label="Open navigation sidebar"
      :aria-expanded="isSidebarOpen"
      @click="openSidebar(true)"
      @mouseenter="handleEdgeEnter"
      @mouseleave="handleEdgeLeave"
    ></button>
    <Sidebar
      :is-open="isSidebarOpen"
      @close="closeSidebar()"
      @mouseenter="handleSidebarEnter"
      @mouseleave="handleSidebarLeave"
    />

    <div v-if="isSidebarOpen && !usesHoverSidebar" class="sidebar-backdrop" aria-hidden="true" @click="closeSidebar()"></div>

    <div class="shell-content portal-page-shell">
      <PortalAnimatedBackdrop :role="authStore.currentRole" />
      <Navbar :show-menu-button="usesHoverSidebar" :sidebar-open="isSidebarOpen" @toggle-sidebar="toggleSidebar" />

      <main class="main-content">
        <RouterView v-slot="{ Component, route }">
          <Transition name="portal-route" mode="out-in">
            <div :key="route.path" class="portal-route-view">
              <component :is="Component" />
            </div>
          </Transition>
        </RouterView>
      </main>
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

.sidebar-edge-trigger {
  display: none;
}

@media (min-width: 861px) and (hover: hover) and (pointer: fine) {
  .hover-sidebar-shell .shell-content {
    margin-left: 0;
  }

  .hover-sidebar-shell .main-content {
    max-width: none;
    width: 100%;
  }

  .hover-sidebar-shell :deep(.sidebar) {
    border-right: 1px solid rgb(244 211 94 / 38%);
    box-shadow: 12px 0 30px rgb(0 0 0 / 24%);
    transform: translateX(calc(-100% - 16px));
    transition: transform 260ms cubic-bezier(0.22, 1, 0.36, 1);
  }

  .hover-sidebar-shell :deep(.sidebar.is-open) {
    transform: translateX(0);
  }

  .hover-sidebar-shell .sidebar-edge-trigger {
    background: transparent;
    border: 0;
    cursor: default;
    display: block;
    height: 100vh;
    height: 100dvh;
    inset: 0 auto 0 0;
    padding: 0;
    position: fixed;
    width: 14px;
    z-index: 29;
  }

  .hover-sidebar-shell .sidebar-edge-trigger:focus-visible {
    background: var(--accent, var(--color-dartmouth-green));
    box-shadow: 3px 0 0 var(--color-naples-yellow);
    outline: 0;
  }
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
  .hover-sidebar-shell :deep(.sidebar),
  .portal-route-enter-active,
  .portal-route-leave-active {
    transition-duration: 0.01ms;
  }

  .portal-route-enter-from,
  .portal-route-leave-to {
    transform: none;
  }
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
