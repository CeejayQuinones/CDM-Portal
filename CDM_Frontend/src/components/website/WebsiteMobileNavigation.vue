<script setup>
import { nextTick, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import CDMSeal from './CDMSeal.vue'
import { destinationContainsRouteName, moduleContainsRouteName } from '../../config/websiteNavigation'

const props = defineProps({ modules: { type: Array, default: () => [] }, open: Boolean, activeRouteName: { type: [String, Symbol], default: '' } })
const emit = defineEmits(['close'])
const root = ref(null)
const expanded = ref([])

watch(() => props.open, (open) => {
  if (open) expanded.value = props.modules
    .filter(module => module.destinations.length > 1 && moduleContainsRouteName(module, props.activeRouteName))
    .map(module => module.name)
})

const toggle = name => { expanded.value = expanded.value.includes(name) ? expanded.value.filter(value => value !== name) : [...expanded.value, name] }
const focusables = () => Array.from(root.value?.querySelectorAll('a[href], button:not(:disabled)') || []).filter(element => element.getClientRects().length)
const focusFirst = () => nextTick(() => focusables()[0]?.focus())
const onKeydown = event => {
  if (event.key !== 'Tab') return
  const items = focusables()
  if (!items.length) return
  if (event.shiftKey && document.activeElement === items[0]) { event.preventDefault(); items.at(-1).focus() }
  else if (!event.shiftKey && document.activeElement === items.at(-1)) { event.preventDefault(); items[0].focus() }
}
defineExpose({ focusFirst })
</script>

<template>
  <Transition name="mobile-backdrop"><div v-if="open" class="mobile-nav-backdrop" aria-hidden="true" @click="$emit('close', true)"></div></Transition>
  <Transition name="mobile-drawer">
    <aside v-if="open" ref="root" class="website-mobile-navigation" role="dialog" aria-modal="true" aria-label="Portal navigation" @keydown="onKeydown">
      <header><CDMSeal class="mobile-seal" /><div><strong>CDM Portal</strong><span>Academic workspace</span></div><button type="button" aria-label="Close navigation menu" @click="$emit('close', true)">×</button></header>
      <nav aria-label="Mobile portal navigation">
        <section v-for="module in modules" :key="module.name">
          <button v-if="module.destinations.length > 1" type="button" class="mobile-module" :class="{ active: moduleContainsRouteName(module, activeRouteName) }" :aria-expanded="expanded.includes(module.name)" @click="toggle(module.name)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="module.icon" stroke-linecap="round" stroke-linejoin="round" /></svg><span>{{ module.label }}</span><b aria-hidden="true">⌄</b>
          </button>
          <RouterLink v-else :to="module.destinations[0]?.path" class="mobile-module" :class="{ active: moduleContainsRouteName(module, activeRouteName) }" :aria-current="moduleContainsRouteName(module, activeRouteName) ? 'page' : undefined" @click="$emit('close')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="module.icon" stroke-linecap="round" stroke-linejoin="round" /></svg><span>{{ module.label }}</span>
          </RouterLink>
          <div v-if="module.destinations.length > 1 && expanded.includes(module.name)" class="mobile-destinations">
            <RouterLink v-for="item in module.destinations" :key="item.name" :to="item.path" :class="{ active: destinationContainsRouteName(item, activeRouteName) }" :aria-current="destinationContainsRouteName(item, activeRouteName) ? 'page' : undefined" @click="$emit('close')">{{ item.label }}</RouterLink>
          </div>
        </section>
      </nav>
    </aside>
  </Transition>
</template>

<style scoped>
.mobile-nav-backdrop { position: fixed; inset: 0; z-index: 58; background: var(--website-overlay); backdrop-filter: blur(3px); }
.website-mobile-navigation { position: fixed; inset: 0 auto 0 0; z-index: 60; width: min(88vw, 360px); padding: max(18px, env(safe-area-inset-top)) 16px calc(20px + env(safe-area-inset-bottom)); background: var(--website-surface-elevated); color: var(--website-ink); box-shadow: 24px 0 60px rgba(0,0,0,.32); overflow-y: auto; }
header { display: grid; grid-template-columns: 44px 1fr 42px; align-items: center; gap: 12px; padding-bottom: 18px; border-bottom: 1px solid var(--website-border); }
.mobile-seal { width: 44px; height: 44px; }
header div { display: grid; gap: 2px; }
header strong { color: var(--website-deep-green); }
header span { color: var(--website-muted); font-size: 12px; }
header button { width: 42px; height: 42px; border: 1px solid var(--website-border); border-radius: 12px; background: var(--website-input); color: var(--website-muted); font-size: 25px; }
nav { display: grid; gap: 5px; padding-top: 16px; }
.mobile-module { width: 100%; min-height: 52px; display: grid; grid-template-columns: 26px 1fr auto; align-items: center; gap: 12px; padding: 10px 12px; border: 1px solid transparent; border-radius: 13px; background: transparent; color: var(--website-ink); text-align: left; text-decoration: none; font: inherit; font-weight: 720; cursor: pointer; }
.mobile-module svg { width: 22px; height: 22px; color: var(--website-deep-green); }
.mobile-module.active { background: var(--website-success-bg); border-color: var(--website-success-border); box-shadow: inset 3px 0 var(--website-gold); }
.mobile-destinations { display: grid; gap: 3px; margin: 3px 0 8px 38px; }
.mobile-destinations a { min-height: 44px; display: flex; align-items: center; padding: 8px 12px; border-radius: 10px; color: var(--website-muted); text-decoration: none; font-size: 14px; font-weight: 650; }
.mobile-destinations a.active { color: var(--website-emerald); background: var(--website-hover); }
.mobile-drawer-enter-active,.mobile-drawer-leave-active,.mobile-backdrop-enter-active,.mobile-backdrop-leave-active { transition: 210ms ease; }
.mobile-drawer-enter-from,.mobile-drawer-leave-to { transform: translateX(-100%); }
.mobile-backdrop-enter-from,.mobile-backdrop-leave-to { opacity: 0; }
@media (min-width: 901px) { .website-mobile-navigation,.mobile-nav-backdrop { display: none; } }
</style>
