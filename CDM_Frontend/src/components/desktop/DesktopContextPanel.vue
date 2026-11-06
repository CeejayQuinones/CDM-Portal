<script setup>
import { ref } from 'vue'
import { RouterLink } from 'vue-router'

defineProps({
  module: { type: Object, required: true },
  route: { type: Object, required: true },
  overlay: { type: Boolean, default: false },
})
defineEmits(['close'])

const closeButton = ref(null)
defineExpose({ focusPanel: () => closeButton.value?.focus() })

const active = (destination, route) =>
  destination.path === route.fullPath
  || (!destination.path.includes('?') && destination.path === route.path)
  || (destination.activeQuery
    && destination.path.split('?')[0] === route.path
    && Object.entries(destination.activeQuery).every(([key, value]) => String(route.query[key] ?? '') === String(value)))
  || destination.name === route.name
  || destination.activeRoutes?.includes(route.name)
</script>

<template>
  <aside id="desktop-module-navigation" class="desktop-context-panel" :class="{ 'is-overlay': overlay }" :aria-label="`${module.label} navigation`">
    <header>
      <div><small>Workspace</small><h2>{{ module.label }}</h2></div>
      <button ref="closeButton" class="desktop-context-panel-close" type="button" aria-label="Close module navigation" title="Close module navigation" @click="$emit('close')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 6 3 12l6 6M3 12h14M21 4v16" /></svg>
      </button>
    </header>
    <nav>
      <RouterLink
        v-for="destination in module.destinations"
        :key="destination.name"
        :to="destination.path"
        :class="{ active: active(destination, route) }"
        :aria-current="active(destination, route) ? 'page' : undefined"
        @click="$emit('close')"
      >
        <span>{{ destination.label }}</span><span aria-hidden="true">›</span>
      </RouterLink>
    </nav>
  </aside>
</template>

