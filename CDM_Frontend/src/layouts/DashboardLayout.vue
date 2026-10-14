<script setup>
import { computed, ref } from 'vue'
import Navbar from '../components/Navbar.vue'
import PortalAnimatedBackdrop from '../components/PortalAnimatedBackdrop.vue'
import Sidebar from '../components/Sidebar.vue'
import StepUpAuthModal from '../components/StepUpAuthModal.vue'
import { useAuthStore } from '../stores/authStore'
import { useStudentTheme } from '../composables/useStudentTheme'

const isSidebarOpen = ref(false)
const authStore = useAuthStore()
const isStudentTheme = computed(() => authStore.currentRole === 'Student')
const isStaffTheme = computed(() => ['Registrar Staff', 'Admin'].includes(authStore.currentRole))
useStudentTheme(authStore)
</script>

<template>
  <div class="app-shell" :class="{ 'student-portal-shell': isStudentTheme, 'registrar-theme-shell': isStaffTheme }">
    <StepUpAuthModal />
    <Sidebar :is-open="isSidebarOpen" @close="isSidebarOpen = false" />

    <div v-if="isSidebarOpen" class="sidebar-backdrop" aria-hidden="true" @click="isSidebarOpen = false"></div>

    <div class="shell-content portal-page-shell">
      <PortalAnimatedBackdrop :role="authStore.currentRole" />
      <Navbar @toggle-sidebar="isSidebarOpen = !isSidebarOpen" />

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
