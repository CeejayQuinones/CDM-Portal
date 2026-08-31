<script setup>
import { computed, ref } from 'vue'
import Navbar from '../components/Navbar.vue'
import Sidebar from '../components/Sidebar.vue'
import StepUpAuthModal from '../components/StepUpAuthModal.vue'
import { useAuthStore } from '../stores/authStore'

const isSidebarOpen = ref(false)
const authStore = useAuthStore()
const isStudentTheme = computed(() => authStore.currentRole === 'Student')
</script>

<template>
  <div class="app-shell" :class="{ 'student-portal-shell': isStudentTheme }">
    <StepUpAuthModal />
    <Sidebar :is-open="isSidebarOpen" @close="isSidebarOpen = false" />

    <div v-if="isSidebarOpen" class="sidebar-backdrop" aria-hidden="true" @click="isSidebarOpen = false"></div>

    <div class="shell-content">
      <Navbar @toggle-sidebar="isSidebarOpen = !isSidebarOpen" />

      <main class="main-content">
        <RouterView />
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
  max-width: 100%;
  min-width: 0;
  min-height: 100vh;
  margin-left: var(--sidebar-width);
}

.main-content {
  max-width: 100%;
  min-width: 0;
  width: min(1180px, 100%);
  margin: 0 auto;
  padding: 32px;
}

.sidebar-backdrop {
  position: fixed;
  inset: 0;
  z-index: 25;
  background: rgba(31, 31, 31, 0.44);
}

@media (max-width: 860px) {
  .shell-content {
    margin-left: 0;
  }

  .main-content {
    padding: 22px 18px;
  }
}
</style>

<style src="../assets/styles/student-portal.css"></style>
