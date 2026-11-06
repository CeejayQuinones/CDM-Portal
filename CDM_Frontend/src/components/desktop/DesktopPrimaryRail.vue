<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import logoUrl from '../../assets/styles/images/cdm-seal.png'

const props = defineProps({
  modules: { type: Array, default: () => [] },
  activeModule: { type: Object, default: null },
  modulePanelOpen: { type: Boolean, default: false },
})
const emit = defineEmits(['select'])
const router = useRouter()

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

function select(module) {
  emit('select', module)
  if (module.destinations.length === 1) router.push(module.destinations[0].path)
}

const primaryModules = computed(() => props.modules.filter(module => !['settings', 'registrar-settings'].includes(module.name)))
const utilityModules = computed(() => props.modules.filter(module => ['settings', 'registrar-settings'].includes(module.name)))
</script>

<template>
  <aside class="desktop-primary-rail" aria-label="Primary workspace navigation">
    <div class="desktop-rail-brand">
      <img :src="logoUrl" alt="Colegio de Montalban seal" />
    </div>
    <nav class="desktop-rail-modules">
      <button
        v-for="module in primaryModules"
        :key="module.name"
        type="button"
        class="desktop-rail-action"
        :class="{ active: isActive(module) }"
        :aria-label="module.label"
        :aria-current="isActive(module) ? 'page' : undefined"
        :aria-controls="module.destinations.length > 1 ? 'desktop-module-navigation' : undefined"
        :aria-expanded="module.destinations.length > 1 && isActive(module) ? modulePanelOpen : undefined"
        :title="module.label"
        @click="select(module)"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
          <path :d="iconFor(module.name)" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        <span class="desktop-rail-tooltip" role="tooltip">{{ module.label }}</span>
      </button>
    </nav>
    <nav v-if="utilityModules.length" class="desktop-rail-utilities" aria-label="Workspace settings">
      <button
        v-for="module in utilityModules"
        :key="module.name"
        type="button"
        class="desktop-rail-action"
        :class="{ active: isActive(module) }"
        :aria-label="module.label"
        :title="module.label"
        @click="select(module)"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="iconFor(module.name)" stroke-linecap="round" stroke-linejoin="round" /></svg>
        <span class="desktop-rail-tooltip" role="tooltip">{{ module.label }}</span>
      </button>
    </nav>
  </aside>
</template>
