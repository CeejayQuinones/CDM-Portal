<script setup>
import { computed, nextTick, ref, toRef, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { destinationContainsDesktopRoute } from '../../config/desktopNavigation'
import { usePortalThemeToggle } from '../../composables/usePortalThemeToggle'
import logoUrl from '../../assets/styles/images/cdm_logo.png'

const props = defineProps({
  modules: { type: Array, default: () => [] },
  activeModule: { type: Object, default: null },
  modulePanelOpen: { type: Boolean, default: false },
  registrarPresentation: { type: Boolean, default: false },
  mobileDrawer: { type: Boolean, default: false },
  drawerOpen: { type: Boolean, default: false },
  appearanceAccount: { type: [String, Number], default: null },
  appearanceRole: { type: String, default: 'Registrar Staff' },
})
const emit = defineEmits(['select', 'close', 'theme-change'])
const router = useRouter()
const route = useRoute()
const expandedModule = ref(null)
const drawerClose = ref(null)
const sidebar = ref(null)
const { effectiveDark, themeAction, toggleTheme } = usePortalThemeToggle(toRef(props, 'appearanceRole'), toRef(props, 'appearanceAccount'), theme => emit('theme-change', theme))

const icons = {
  'registrar-dashboard': 'M4 13h6V4H4v9Zm10 7h6v-9h-6v9ZM4 20h6v-3H4v3Zm10-13h6V4h-6v3Z',
  'admin-dashboard': 'M4 13h6V4H4v9Zm10 7h6v-9h-6v9ZM4 20h6v-3H4v3Zm10-13h6V4h-6v3Z',
  'student-management-menu': 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm13 10v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
  enrollment: 'M5 3h14v18H5zM8 7h8M8 11h8M8 15h5',
  grading: 'M4 19V5l8-3 8 3v14l-8 3-8-3Zm5-7 2 2 4-4',
  'document-requests-menu': 'M7 3h7l4 4v14H7zM14 3v5h5M10 12h5M10 16h5',
  'admission-menu': 'M12 3v18M3 12h18',
  'account-management': 'M4 20v-1a5 5 0 0 1 5-5h2a5 5 0 0 1 5 5v1M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm9-4v6m-3-3h6',
  'campus-notices': 'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 7h18s-3 0-3-7M10 19h4',
  settings: 'M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM19 12h2M3 12h2M12 3v2M12 19v2',
  'registrar-settings': 'M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM19 12h2M3 12h2M12 3v2M12 19v2',
}
const iconFor = name => icons[name] || 'M5 5h14v14H5z'
const isActive = module => props.activeModule?.name === module.name
const destinationActive = destination => destinationContainsDesktopRoute(destination, route)
const primaryModules = computed(() => props.modules.filter(module => !['settings', 'registrar-settings'].includes(module.name)))
const utilityModules = computed(() => props.modules.filter(module => ['settings', 'registrar-settings'].includes(module.name)))

watch(() => [props.activeModule?.name, route.fullPath], () => {
  if (props.registrarPresentation) expandedModule.value = props.activeModule?.destinations.length > 1 ? props.activeModule.name : null
}, { immediate: true })

function select(module) {
  emit('select', module)
  if (module.destinations.length === 1) router.push(module.destinations[0].path)
}

function closeDrawer() {
  if (props.mobileDrawer) emit('close')
}

function trapDrawerFocus(event) {
  if (!props.mobileDrawer || !props.drawerOpen || event.key !== 'Tab') return
  const targets = [...(sidebar.value?.querySelectorAll('a[href], button:not([disabled])') || [])]
    .filter(element => element.getClientRects().length)
  if (!targets.length) return
  const first = targets[0]
  const last = targets[targets.length - 1]
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus() }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus() }
}

defineExpose({ focusDrawerClose: () => nextTick(() => drawerClose.value?.focus()) })
</script>

<template>
  <aside v-if="registrarPresentation" id="desktop-registrar-navigation" ref="sidebar" class="desktop-registrar-sidebar" :class="{ 'drawer-open': drawerOpen }" :role="mobileDrawer && drawerOpen ? 'dialog' : undefined" :aria-modal="mobileDrawer && drawerOpen ? 'true' : undefined" aria-label="Registrar workspace navigation" @keydown="trapDrawerFocus">
    <header class="registrar-sidebar-brand">
      <img :src="logoUrl" alt="Colegio de Montalban seal" />
      <div><strong>CDM Portal</strong><span>REGISTRAR WORKSPACE</span></div>
      <button ref="drawerClose" type="button" class="registrar-sidebar-close" aria-label="Close Registrar navigation" @click="closeDrawer">×</button>
    </header>
    <nav class="registrar-sidebar-nav" aria-label="Registrar pages">
      <p class="registrar-sidebar-section">WORKSPACE</p>
      <template v-for="module in primaryModules" :key="module.name">
        <RouterLink v-if="module.destinations.length === 1" :to="module.destinations[0].path" class="registrar-sidebar-entry" :class="{ active: isActive(module) }" :aria-current="isActive(module) ? 'page' : undefined" @click="closeDrawer">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="iconFor(module.name)" stroke-linecap="round" stroke-linejoin="round" /></svg>
          <span>{{ module.label }}</span>
        </RouterLink>
        <template v-else>
          <button type="button" class="registrar-sidebar-entry registrar-sidebar-parent" :class="{ active: isActive(module) }" :aria-expanded="expandedModule === module.name" :aria-controls="`registrar-destinations-${module.name}`" @click="expandedModule = expandedModule === module.name ? null : module.name">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="iconFor(module.name)" stroke-linecap="round" stroke-linejoin="round" /></svg>
            <span>{{ module.label }}</span>
            <svg class="registrar-sidebar-chevron" :class="{ open: expandedModule === module.name }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
          </button>
          <div :id="`registrar-destinations-${module.name}`" class="registrar-sidebar-children" :class="{ open: expandedModule === module.name }" :inert="expandedModule !== module.name">
            <div>
              <RouterLink v-for="destination in module.destinations" :key="destination.name" :to="destination.path" :class="{ active: destinationActive(destination) }" :aria-current="destinationActive(destination) ? 'page' : undefined" @click="closeDrawer">{{ destination.label }}</RouterLink>
            </div>
          </div>
        </template>
      </template>
    </nav>
    <nav class="registrar-sidebar-footer" aria-label="Registrar account">
      <p class="registrar-sidebar-section">ACCOUNT</p>
      <button class="registrar-theme-shortcut" type="button" :aria-label="themeAction" :aria-pressed="effectiveDark" :title="themeAction" @click="toggleTheme">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z" /></svg>
      </button>
      <RouterLink v-for="module in utilityModules" :key="module.name" :to="module.destinations[0].path" class="registrar-sidebar-entry" :class="{ active: isActive(module) }" :aria-current="isActive(module) ? 'page' : undefined" @click="closeDrawer">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="iconFor(module.name)" stroke-linecap="round" stroke-linejoin="round" /></svg><span>{{ module.label }}</span>
      </RouterLink>
    </nav>
  </aside>
  <aside v-else class="desktop-primary-rail" aria-label="Primary workspace navigation">
    <div class="desktop-rail-brand"><img :src="logoUrl" alt="Colegio de Montalban seal" /></div>
    <nav class="desktop-rail-modules">
      <button v-for="module in primaryModules" :key="module.name" type="button" class="desktop-rail-action" :class="{ active: isActive(module) }" :aria-label="module.label" :aria-current="isActive(module) ? 'page' : undefined" :aria-controls="module.destinations.length > 1 ? 'desktop-module-navigation' : undefined" :aria-expanded="module.destinations.length > 1 && isActive(module) ? modulePanelOpen : undefined" :title="module.label" @click="select(module)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="iconFor(module.name)" stroke-linecap="round" stroke-linejoin="round" /></svg><span class="desktop-rail-tooltip" role="tooltip">{{ module.label }}</span>
      </button>
    </nav>
    <nav class="desktop-rail-utilities" aria-label="Workspace settings">
      <button type="button" class="desktop-rail-action desktop-theme-shortcut" :aria-label="themeAction" :aria-pressed="effectiveDark" :title="themeAction" @click="toggleTheme">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z" /></svg>
      </button>
      <button v-for="module in utilityModules" :key="module.name" type="button" class="desktop-rail-action" :class="{ active: isActive(module) }" :aria-label="module.label" :title="module.label" @click="select(module)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="iconFor(module.name)" stroke-linecap="round" stroke-linejoin="round" /></svg><span class="desktop-rail-tooltip" role="tooltip">{{ module.label }}</span>
      </button>
    </nav>
  </aside>
</template>
