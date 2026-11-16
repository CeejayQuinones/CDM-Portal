<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import DesktopContextHeader from '../components/desktop/DesktopContextHeader.vue'
import DesktopContextPanel from '../components/desktop/DesktopContextPanel.vue'
import DesktopPrimaryRail from '../components/desktop/DesktopPrimaryRail.vue'
import StepUpAuthModal from '../components/StepUpAuthModal.vue'
import { desktopModuleForRoute, desktopModulesFromMenu } from '../config/desktopNavigation'
import { useAuthStore } from '../stores/authStore'
import { useNavigationStore } from '../stores/navigation'
import { apiClient } from '../services/apiClient'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const navigation = useNavigationStore()
const registrarRail = computed(() => auth.currentRole === 'Registrar Staff')
const modulePanelOpen = ref(false)
const contextPanel = ref(null)
const contextHeader = ref(null)
const primaryRail = ref(null)
const narrowWindow = ref(false)
const registrarDrawerOpen = ref(false)

const modules = computed(() => desktopModulesFromMenu(navigation.menuItemsForRole(auth.currentRole, auth.currentUser?.admission)))
const activeModule = computed(() => desktopModuleForRoute(modules.value, route) || modules.value[0] || null)
const hasContextPanel = computed(() => (activeModule.value?.destinations.length || 0) > 1)
const pageTitle = computed(() => route.meta.title || activeModule.value?.label || 'CDM Portal')
const studentRecordsRoute = computed(() => ['student-management', 'physical-records', 'student-record-reports'].includes(String(route.name)))
const saveStudentTheme = theme => {
  if (auth.currentRole === 'Student') apiClient.patch('/student/settings/preferences', { appearance: theme }).catch(() => {})
}

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
  if (event.key === 'Escape' && registrarDrawerOpen.value) {
    closeRegistrarDrawer()
    return
  }
  if (event.key === 'Escape' && modulePanelOpen.value) closeModuleNavigation()
}

function updateWindowWidth() {
  narrowWindow.value = window.innerWidth <= 1100
  if (!narrowWindow.value) registrarDrawerOpen.value = false
}

function openRegistrarDrawer() {
  registrarDrawerOpen.value = true
  nextTick(() => primaryRail.value?.focusDrawerClose())
}

function closeRegistrarDrawer() {
  registrarDrawerOpen.value = false
  focusModuleButton()
}

watch(() => route.fullPath, () => { registrarDrawerOpen.value = false })

onMounted(() => {
  updateWindowWidth()
  window.addEventListener('keydown', handleEscape)
  window.addEventListener('resize', updateWindowWidth)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', handleEscape)
  window.removeEventListener('resize', updateWindowWidth)
})
</script>

<template>
  <div class="desktop-workspace-shell" :class="{ 'registrar-workspace': registrarRail }">
    <StepUpAuthModal />
    <div v-if="registrarRail && narrowWindow && registrarDrawerOpen" class="registrar-sidebar-backdrop" @click="closeRegistrarDrawer"></div>
    <DesktopPrimaryRail ref="primaryRail" :modules="modules" :active-module="activeModule" :module-panel-open="modulePanelOpen" :registrar-presentation="registrarRail" :appearance-role="auth.currentRole" :appearance-account="auth.currentUser?.id || auth.currentUser?.username" :mobile-drawer="registrarRail && narrowWindow" :drawer-open="registrarDrawerOpen" @select="selectModule" @close="closeRegistrarDrawer" @theme-change="saveStudentTheme" />
    <Transition name="desktop-context-float">
      <div v-if="!registrarRail && activeModule && hasContextPanel && modulePanelOpen" class="desktop-context-overlay" @mousedown.self="closeModuleNavigation">
        <DesktopContextPanel ref="contextPanel" :module="activeModule" :route="route" overlay @close="closeModuleNavigation" />
      </div>
    </Transition>
    <section class="desktop-workspace-stage">
      <DesktopContextHeader
        ref="contextHeader"
        :module-label="activeModule?.label"
        :page-title="pageTitle"
        :show-module-button="registrarRail ? narrowWindow && !registrarDrawerOpen : hasContextPanel && !modulePanelOpen"
        :navigation-controls-id="registrarRail ? 'desktop-registrar-navigation' : 'desktop-module-navigation'"
        @toggle-module="registrarRail ? openRegistrarDrawer() : openModuleNavigation()"
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
