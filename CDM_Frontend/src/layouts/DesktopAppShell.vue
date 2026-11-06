<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import DesktopContextHeader from '../components/desktop/DesktopContextHeader.vue'
import DesktopContextPanel from '../components/desktop/DesktopContextPanel.vue'
import DesktopPrimaryRail from '../components/desktop/DesktopPrimaryRail.vue'
import StepUpAuthModal from '../components/StepUpAuthModal.vue'
import { desktopModuleForRoute, desktopModulesFromMenu } from '../config/desktopNavigation'
import { useAuthStore } from '../stores/authStore'
import { useNavigationStore } from '../stores/navigation'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const navigation = useNavigationStore()
const modulePanelOpen = ref(false)
const contextPanel = ref(null)
const contextHeader = ref(null)

const modules = computed(() => desktopModulesFromMenu(navigation.menuItemsForRole(auth.currentRole, auth.currentUser?.admission)))
const activeModule = computed(() => desktopModuleForRoute(modules.value, route) || modules.value[0] || null)
const hasContextPanel = computed(() => (activeModule.value?.destinations.length || 0) > 1)
const pageTitle = computed(() => route.meta.title || activeModule.value?.label || 'CDM Portal')
const studentRecordsRoute = computed(() => ['student-management', 'physical-records', 'student-record-reports'].includes(String(route.name)))

async function selectModule(module) {
  if (module.destinations.length === 1) {
    modulePanelOpen.value = false
    return
  }

  if (activeModule.value?.name === module.name && modulePanelOpen.value) {
    closeModuleNavigation()
    return
  }

  if (!desktopModuleForRoute([module], route)) await router.push(module.destinations[0].path)
  openModuleNavigation()
}

function focusModuleButton() {
  nextTick(() => contextHeader.value?.focusModuleButton())
}

function closeModuleNavigation() {
  modulePanelOpen.value = false
  focusModuleButton()
}

function openModuleNavigation() {
  modulePanelOpen.value = true
  nextTick(() => contextPanel.value?.focusPanel())
}

function handleEscape(event) {
  if (event.key === 'Escape' && modulePanelOpen.value) closeModuleNavigation()
}

onMounted(() => {
  window.addEventListener('keydown', handleEscape)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', handleEscape)
})
</script>

<template>
  <div class="desktop-workspace-shell">
    <StepUpAuthModal />
    <DesktopPrimaryRail :modules="modules" :active-module="activeModule" :module-panel-open="modulePanelOpen" @select="selectModule" />
    <Transition name="desktop-context-float">
      <div v-if="activeModule && hasContextPanel && modulePanelOpen" class="desktop-context-overlay" @mousedown.self="closeModuleNavigation">
        <DesktopContextPanel ref="contextPanel" :module="activeModule" :route="route" overlay @close="closeModuleNavigation" />
      </div>
    </Transition>
    <section class="desktop-workspace-stage">
      <DesktopContextHeader
        ref="contextHeader"
        :module-label="activeModule?.label"
        :page-title="pageTitle"
        :show-module-button="hasContextPanel && !modulePanelOpen"
        @toggle-module="openModuleNavigation"
      />
      <main class="desktop-workspace-content">
        <RouterView v-slot="{ Component, route: currentRoute }">
          <Transition :name="studentRecordsRoute ? '' : 'desktop-workspace-route'" mode="out-in">
            <div :key="currentRoute.path" class="desktop-workspace-route"><component :is="Component" /></div>
          </Transition>
        </RouterView>
      </main>
    </section>
  </div>
</template>

<style src="../assets/styles/desktop-ui-3.css"></style>
