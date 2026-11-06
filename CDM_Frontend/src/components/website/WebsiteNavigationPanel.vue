<script setup>
import { RouterLink } from 'vue-router'
import { destinationContainsRouteName } from '../../config/websiteNavigation'

defineProps({
  module: { type: Object, default: null },
  open: { type: Boolean, default: false },
  activeRouteName: { type: [String, Symbol], default: '' },
})
defineEmits(['close'])
</script>

<template>
  <Transition name="website-panel">
    <aside v-if="open && module" class="website-navigation-panel" data-website-navigation-boundary="true" aria-label="Module navigation">
      <header>
        <div>
          <p>Workspace</p>
          <h2>{{ module.label }}</h2>
          <span>{{ module.description }}</span>
        </div>
        <button type="button" aria-label="Collapse module navigation" title="Collapse navigation" @click="$emit('close')">×</button>
      </header>
      <nav :aria-label="`${module.label} destinations`">
        <RouterLink
          v-for="destination in module.destinations"
          :key="destination.name"
          :to="destination.path"
          class="panel-link"
          :class="{ active: destinationContainsRouteName(destination, activeRouteName) }"
          :aria-current="destinationContainsRouteName(destination, activeRouteName) ? 'page' : undefined"
        >
          <span>{{ destination.label }}</span>
          <small v-if="destination.description">{{ destination.description }}</small>
        </RouterLink>
      </nav>
    </aside>
  </Transition>
</template>

<style scoped>
.website-navigation-panel { position: fixed; inset: 0 auto 0 72px; z-index: 38; width: min(280px, calc(100vw - 72px)); padding: 25px 18px; background: var(--website-card); border-right: 1px solid var(--website-border); box-shadow: 18px 0 40px rgba(0,0,0,.16); overflow-y: auto; }
header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 0 4px 22px; border-bottom: 1px solid var(--website-border); }
header p { margin: 0 0 4px; color: var(--website-emerald); font-size: 11px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
h2 { margin: 0; color: var(--website-ink); font-size: 22px; line-height: 1.2; }
header span { display: block; margin-top: 6px; color: var(--website-muted); font-size: 13px; line-height: 1.45; }
header button { width: 38px; height: 38px; flex: 0 0 auto; border: 1px solid var(--website-border); border-radius: 11px; background: var(--website-input); color: var(--website-muted); font-size: 24px; cursor: pointer; }
nav { display: grid; gap: 6px; padding-top: 18px; }
.panel-link { display: grid; gap: 4px; padding: 13px 14px; border: 1px solid transparent; border-radius: 12px; color: var(--website-ink); text-decoration: none; transition: 150ms ease; }
.panel-link span { font-size: 14px; font-weight: 750; }
.panel-link small { color: var(--website-muted); font-size: 12px; line-height: 1.35; }
.panel-link:hover { background: var(--website-hover); border-color: var(--website-border); }
.panel-link.active { background: var(--website-success-bg); border-color: var(--website-success-border); color: var(--website-emerald); box-shadow: inset 3px 0 var(--website-gold); }
@media (max-width: 900px) { .website-navigation-panel { display: none; } }
</style>
