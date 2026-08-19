<script setup>
import { ref } from 'vue'
import Navbar from '../components/Navbar.vue'
import Sidebar from '../components/Sidebar.vue'

const isSidebarOpen = ref(false)
</script>

<template>
  <div class="app-shell">
    <Sidebar :is-open="isSidebarOpen" @close="isSidebarOpen = false" />

    <div
      v-if="isSidebarOpen"
      class="sidebar-backdrop"
      aria-hidden="true"
      @click="isSidebarOpen = false"
    ></div>

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
  min-height: 100vh;
  background: var(--color-anti-flash-white);
}

.shell-content {
  min-height: 100vh;
  margin-left: 250px;
}

.main-content {
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
