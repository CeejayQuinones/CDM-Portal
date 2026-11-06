<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import CDMSeal from './CDMSeal.vue'
import { destinationContainsRouteName, moduleContainsRouteName } from '../../config/websiteNavigation'

const props = defineProps({
  modules: { type: Array, default: () => [] },
  activeRouteName: { type: [String, Symbol], default: '' },
  collapsed: Boolean,
})

defineEmits(['toggle-collapse'])

const settingsNames = ['settings', 'professor-settings']
const primaryModules = computed(() => props.modules.filter(module => !settingsNames.includes(module.name)))
const utilityModules = computed(() => props.modules.filter(module => settingsNames.includes(module.name)))
const targetFor = module => module.destinations?.[0]?.path || module.path || '/'
</script>

<template>
  <aside class="website-navigation-rail" :class="{ collapsed }" data-website-navigation-boundary="true" aria-label="Primary portal navigation">
    <header class="rail-brand">
      <CDMSeal class="rail-seal" />
      <span v-if="!collapsed"><strong>CDM Portal</strong><small>Academic workspace</small></span>
    </header>

    <nav class="rail-modules" aria-label="Portal modules">
      <section v-for="module in primaryModules" :key="module.name" class="rail-group">
        <RouterLink :to="targetFor(module)" class="rail-button" :class="{ active: moduleContainsRouteName(module, activeRouteName) }" :aria-current="moduleContainsRouteName(module, activeRouteName) ? 'page' : undefined" :aria-label="collapsed ? module.label : undefined" :title="collapsed ? module.label : undefined">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="module.icon" stroke-linecap="round" stroke-linejoin="round" /></svg>
          <span v-if="!collapsed" class="rail-label">{{ module.shortLabel || module.label }}</span>
          <span v-if="collapsed" class="rail-tooltip" role="tooltip">{{ module.shortLabel || module.label }}</span>
        </RouterLink>
        <div v-if="!collapsed && module.destinations.length > 1 && moduleContainsRouteName(module, activeRouteName)" class="rail-destinations">
          <RouterLink v-for="destination in module.destinations" :key="destination.name" :to="destination.path" :class="{ active: destinationContainsRouteName(destination, activeRouteName) }" :aria-current="destinationContainsRouteName(destination, activeRouteName) ? 'page' : undefined">{{ destination.label }}</RouterLink>
        </div>
      </section>
    </nav>

    <nav v-if="utilityModules.length" class="rail-utilities" aria-label="Account navigation">
      <RouterLink v-for="module in utilityModules" :key="module.name" :to="targetFor(module)" class="rail-button" :class="{ active: moduleContainsRouteName(module, activeRouteName) }" :aria-label="collapsed ? module.label : undefined" :title="collapsed ? module.label : undefined">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="module.icon" stroke-linecap="round" stroke-linejoin="round" /></svg>
        <span v-if="!collapsed" class="rail-label">{{ module.label }}</span>
        <span v-if="collapsed" class="rail-tooltip" role="tooltip">{{ module.label }}</span>
      </RouterLink>
    </nav>

    <button type="button" class="rail-collapse" :aria-label="collapsed ? 'Expand portal navigation' : 'Collapse portal navigation'" :title="collapsed ? 'Expand navigation' : 'Collapse navigation'" @click="$emit('toggle-collapse')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="collapsed ? 'm9 6 6 6-6 6' : 'm15 6-6 6 6 6'" stroke-linecap="round" stroke-linejoin="round" /></svg>
      <span v-if="!collapsed">Collapse</span>
    </button>
  </aside>
</template>

<style scoped>
.website-navigation-rail { position: fixed; inset: 0 auto 0 0; z-index: 42; width: 248px; display: flex; flex-direction: column; padding: 14px 12px calc(14px + env(safe-area-inset-bottom)); border-right: 1px solid color-mix(in srgb,var(--website-forest) 80%,#fff); background: var(--website-forest); color: #fff; transition: width 190ms ease; }
.website-navigation-rail.collapsed { width: 72px; padding-inline: 10px; }
.rail-brand { min-height: 52px; display: flex; align-items: center; gap: 11px; padding: 0 7px 13px; border-bottom: 1px solid rgba(255,255,255,.14); }
.rail-seal { width: 42px; height: 42px; flex: 0 0 42px; }
.rail-brand span { min-width: 0; display: grid; gap: 2px; }
.rail-brand strong { font-size: .86rem; }
.rail-brand small { color: rgba(255,255,255,.64); font-size: .67rem; }
.rail-modules { display: grid; gap: 3px; margin-top: 12px; overflow: auto; scrollbar-width: none; }
.rail-modules::-webkit-scrollbar { display: none; }
.rail-group { display: grid; gap: 2px; }
.rail-button { position: relative; min-height: 42px; display: grid; grid-template-columns: 24px minmax(0,1fr); align-items: center; gap: 11px; padding: 8px 10px; border-radius: 7px; color: rgba(255,255,255,.75); text-decoration: none; font-size: .78rem; font-weight: 680; }
.collapsed .rail-button { grid-template-columns: 1fr; place-items: center; padding: 8px; }
.rail-button svg { width: 20px; height: 20px; }
.rail-button:hover { background: rgba(255,255,255,.08); color: #fff; }
.rail-button.active { background: rgba(255,255,255,.14); color: #fff; box-shadow: inset 3px 0 var(--website-gold); }
.rail-button:focus-visible,.rail-destinations a:focus-visible,.rail-collapse:focus-visible { outline: 2px solid var(--website-focus); outline-offset: -2px; }
.rail-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rail-destinations { display: grid; gap: 1px; margin: 1px 0 5px 35px; padding-left: 9px; border-left: 1px solid rgba(255,255,255,.18); }
.rail-destinations a { min-height: 34px; display: flex; align-items: center; padding: 6px 8px; border-radius: 6px; color: rgba(255,255,255,.62); text-decoration: none; font-size: .7rem; }
.rail-destinations a:hover,.rail-destinations a.active { background: rgba(255,255,255,.08); color: #fff; }
.rail-destinations a.active { font-weight: 750; }
.rail-utilities { display: grid; gap: 3px; margin-top: auto; padding-top: 9px; border-top: 1px solid rgba(255,255,255,.14); }
.rail-collapse { min-height: 38px; display: grid; grid-template-columns: 22px minmax(0,1fr); align-items: center; gap: 9px; margin-top: 8px; padding: 7px 10px; border: 1px solid rgba(255,255,255,.16); border-radius: 7px; background: transparent; color: rgba(255,255,255,.7); text-align: left; font: inherit; font-size: .72rem; cursor: pointer; }
.collapsed .rail-collapse { grid-template-columns: 1fr; place-items: center; padding: 7px; }
.rail-collapse:hover { background: rgba(255,255,255,.08); color: #fff; }
.rail-collapse svg { width: 18px; height: 18px; }
.rail-tooltip { position: absolute; left: calc(100% + 10px); top: 50%; z-index: 8; width: max-content; max-width: 190px; padding: 7px 9px; border-radius: 6px; background: #0f172a; color: #fff; font-size: .7rem; opacity: 0; visibility: hidden; transform: translate(-4px,-50%); pointer-events: none; transition: 120ms ease; }
.rail-button:hover .rail-tooltip,.rail-button:focus-visible .rail-tooltip { opacity: 1; visibility: visible; transform: translate(0,-50%); }
@media (max-width: 900px) { .website-navigation-rail { display: none; } }
@media (prefers-reduced-motion: reduce) { .website-navigation-rail,.rail-tooltip { transition: none; } }
</style>
