<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { authorizedSearchDestinations, filterWebsiteDestinations, moveSearchSelection } from '../../config/websiteSearch'
import WebsiteUserAvatar from './WebsiteUserAvatar.vue'

const props = defineProps({
  title: { type: String, default: 'Dashboard' },
  moduleLabel: { type: String, default: 'CDM Portal' },
  modules: { type: Array, default: () => [] },
  displayName: { type: String, default: 'Portal User' },
  role: { type: String, default: '' },
  initials: { type: String, default: 'CDM' },
  avatarUrl: { type: String, default: '' },
  avatarRevision: { type: Number, default: 0 },
  accountOpen: Boolean,
})
const emit = defineEmits(['open-navigation', 'toggle-account', 'open-profile', 'logout'])
const router = useRouter()
const searchOpen = ref(false)
const search = ref('')
const selectedResult = ref(-1)
const searchInput = ref(null)
const searchTrigger = ref(null)
const searchWrap = ref(null)
const closeSearch = (restoreFocus = false) => {
  if (!searchOpen.value) return
  searchOpen.value = false
  search.value = ''
  selectedResult.value = -1
  if (restoreFocus) nextTick(() => searchTrigger.value?.focus())
}
const destinations = computed(() => authorizedSearchDestinations(props.modules))
const results = computed(() => filterWebsiteDestinations(destinations.value, search.value, search.value.trim() ? 8 : 6))
const openSearch = () => {
  if (searchOpen.value) return closeSearch(true)
  if (props.accountOpen) emit('toggle-account')
  searchOpen.value = true
  selectedResult.value = results.value.length ? 0 : -1
  nextTick(() => searchInput.value?.focus())
}
const clearSearch = () => {
  search.value = ''
  selectedResult.value = results.value.length ? 0 : -1
  nextTick(() => searchInput.value?.focus())
}
const selectDestination = async item => {
  if (!item) return
  await router.push(item.path || { name: item.name })
  closeSearch(false)
}
const handleSearchKeydown = event => {
  if (event.key === 'Escape') {
    event.preventDefault()
    closeSearch(true)
  } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault()
    selectedResult.value = moveSearchSelection(selectedResult.value, event.key === 'ArrowDown' ? 'next' : 'previous', results.value.length)
  } else if (event.key === 'Enter' && selectedResult.value >= 0) {
    event.preventDefault()
    selectDestination(results.value[selectedResult.value])
  }
}
const toggleAccount = () => {
  if (!props.accountOpen && searchOpen.value) closeSearch(false)
  emit('toggle-account')
}
const handleOutsidePointer = event => {
  if (searchOpen.value && !searchWrap.value?.contains(event.target)) closeSearch(false)
}

watch(search, () => { selectedResult.value = results.value.length ? 0 : -1 })
watch(() => props.accountOpen, open => { if (open && searchOpen.value) closeSearch(false) })
onMounted(() => document.addEventListener('pointerdown', handleOutsidePointer))
onBeforeUnmount(() => document.removeEventListener('pointerdown', handleOutsidePointer))
</script>

<template>
  <header class="website-topbar" :class="{ 'search-active': searchOpen }" data-website-topbar="true">
    <button class="mobile-menu" type="button" aria-label="Open navigation menu" @click="$emit('open-navigation')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" stroke-linecap="round" /></svg>
    </button>
    <div class="workspace-location">
      <span>{{ moduleLabel }}</span>
      <b aria-hidden="true">/</b>
      <strong>{{ title }}</strong>
    </div>
    <div class="topbar-actions">
      <div ref="searchWrap" class="search-wrap" :class="{ open: searchOpen }">
        <div class="search-surface">
          <button v-if="!searchOpen" ref="searchTrigger" class="search-trigger" type="button" aria-expanded="false" aria-controls="website-search-popover" aria-label="Search portal destinations" title="Search portal" @click="openSearch">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4" stroke-linecap="round"/></svg>
          </button>
          <div v-else class="search-input-content">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4" stroke-linecap="round"/></svg>
            <label class="sr-only" for="website-route-search">Find an authorized portal destination</label>
            <input id="website-route-search" ref="searchInput" v-model="search" type="search" autocomplete="off" placeholder="Search portal" role="combobox" aria-autocomplete="list" aria-controls="website-search-results" :aria-expanded="searchOpen" :aria-activedescendant="selectedResult >= 0 ? `website-search-result-${selectedResult}` : undefined" @keydown="handleSearchKeydown" />
            <button type="button" :aria-label="search ? 'Clear search' : 'Close search'" :title="search ? 'Clear search' : 'Close search'" @click="search ? clearSearch() : closeSearch(true)">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" stroke-linecap="round" /></svg>
            </button>
          </div>
        </div>
        <Transition name="search-results">
          <div v-if="searchOpen" id="website-search-popover" class="search-popover">
            <nav id="website-search-results" role="listbox" aria-label="Authorized portal destinations">
              <RouterLink v-for="(item, index) in results" :id="`website-search-result-${index}`" :key="item.name" :to="item.path" role="option" :aria-selected="selectedResult === index" :class="{ selected: selectedResult === index }" @mouseenter="selectedResult = index" @click="closeSearch(false)"><span>{{ item.label }}</span><small>{{ item.moduleLabel }}</small></RouterLink>
              <p v-if="!results.length" role="status">No matching authorized workspace.</p>
            </nav>
          </div>
        </Transition>
      </div>
      <button class="account-trigger" type="button" :aria-expanded="accountOpen" aria-label="Open account actions" @click="toggleAccount">
        <span class="account-avatar"><WebsiteUserAvatar :src="avatarUrl" :initials="initials" :revision="avatarRevision" :label="`${displayName} profile picture`" /></span><span class="account-copy"><strong>{{ displayName }}</strong><small>{{ role }}</small></span><span aria-hidden="true">⌄</span>
      </button>
      <div v-if="accountOpen" class="account-popover">
        <div class="account-identity">
          <span class="popover-avatar"><WebsiteUserAvatar :src="avatarUrl" :initials="initials" :revision="avatarRevision" :label="`${displayName} profile picture`" /></span>
          <p><strong>{{ displayName }}</strong><span>{{ role }} account</span></p>
        </div>
        <button v-if="role === 'Student'" type="button" @click="$emit('open-profile')">Profile</button>
        <RouterLink v-if="role === 'Student'" :to="{ name: 'settings' }" @click="$emit('toggle-account')">Settings</RouterLink>
        <RouterLink v-else-if="role === 'Professor'" :to="{ name: 'professor-settings' }" @click="$emit('toggle-account')">Profile and settings</RouterLink>
        <button type="button" @click="$emit('logout')">Sign out</button>
      </div>
    </div>
  </header>
</template>

<style scoped>
.website-topbar { position: sticky; top: 0; z-index: 30; min-height: 72px; display: flex; align-items: center; justify-content: space-between; gap: 18px; padding: 12px clamp(18px,3vw,36px); border-bottom: 1px solid var(--website-border); background: color-mix(in srgb,var(--website-canvas) 90%,transparent); backdrop-filter: blur(16px); }
.workspace-location { min-width: 0; flex: 1; display: flex; align-items: center; gap: 9px; color: var(--website-muted); font-size: 13px; }
.workspace-location strong { max-width: min(40vw,430px); overflow: hidden; color: var(--website-ink); text-overflow: ellipsis; white-space: nowrap; }
.workspace-location b { color: var(--website-border-strong); }
.topbar-actions { position: relative; flex-shrink: 0; display: flex; align-items: center; gap: 8px; }
.icon-button,.mobile-menu { width: 42px; height: 42px; display: grid; place-items: center; border: 1px solid var(--website-border); border-radius: 12px; background: var(--website-surface-elevated); color: var(--website-muted); cursor: pointer; text-decoration: none; }
.icon-button svg,.mobile-menu svg { width: 20px; height: 20px; }
.icon-button:hover,.mobile-menu:hover { color: var(--website-deep-green); border-color: rgba(5,150,105,.3); }
.mobile-menu { display: none; }
.search-wrap { position: relative; width: 42px; flex: 0 0 auto; transition: width 290ms cubic-bezier(.22,1,.36,1); }
.search-wrap.open { width: min(340px,38vw); }
.search-surface { width: 100%; height: 42px; overflow: hidden; border: 1px solid var(--website-border); border-radius: 12px; background: var(--website-surface-elevated); color: var(--website-muted); box-shadow: 0 0 0 0 transparent; transition: border-color 160ms ease,box-shadow 160ms ease; }
.search-wrap.open .search-surface { border-color: color-mix(in srgb,var(--website-emerald) 42%,var(--website-border)); box-shadow: 0 8px 22px rgba(4,32,23,.1); }
.search-wrap.open .search-surface:focus-within { border-color: var(--website-emerald); box-shadow: 0 0 0 3px color-mix(in srgb,var(--website-emerald) 22%,transparent),0 8px 22px rgba(4,32,23,.1); }
.search-trigger { width: 40px; height: 40px; display: grid; place-items: center; padding: 0; border: 0; background: transparent; color: inherit; cursor: pointer; }
.search-trigger svg,.search-input-content>svg { width: 20px; height: 20px; flex: 0 0 auto; }
.search-input-content { height: 40px; display: grid; grid-template-columns: 22px minmax(0,1fr) 32px; align-items: center; gap: 7px; padding: 0 4px 0 12px; animation: search-content-in 160ms ease both; }
.search-surface .search-input-content input,.search-surface .search-input-content input:focus,.search-surface .search-input-content input:focus-visible { appearance: none; min-width: 0; width: 100%; height: 38px; padding: 0; border: 0; border-radius: 0; outline: 0; background: transparent; box-shadow: none; color: var(--website-ink); font: inherit; font-size: 13px; caret-color: var(--website-emerald); }
.search-input-content input::placeholder { color: var(--website-muted); }
.search-input-content button { width: 32px; height: 32px; display: grid; place-items: center; padding: 0; border: 0; border-radius: 9px; background: transparent; color: var(--website-muted); cursor: pointer; }
.search-input-content button:hover { background: var(--website-hover); color: var(--website-ink); }
.search-input-content button svg { width: 17px; height: 17px; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); clip-path: inset(50%); white-space: nowrap; }
@keyframes search-content-in { from { opacity: 0; } to { opacity: 1; } }
.account-trigger { min-height: 44px; display: grid; grid-template-columns: 34px auto auto; align-items: center; gap: 9px; padding: 4px 9px 4px 5px; border: 1px solid var(--website-border); border-radius: 14px; background: var(--website-surface-elevated); color: var(--website-ink); cursor: pointer; }
.account-avatar { width: 34px; height: 34px; display: grid; place-items: center; overflow: hidden; border-radius: 11px; background: var(--website-deep-green); color: var(--website-on-primary); font-size: 11px; font-weight: 800; }
.account-copy { display: grid; min-width: 0; text-align: left; }
.account-copy strong { max-width: 150px; overflow: hidden; font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }
.account-copy small { color: var(--website-muted); font-size: 10px; }
.search-popover,.account-popover { position: absolute; top: calc(100% + 10px); right: 0; width: min(340px, calc(100vw - 24px)); padding: 14px; border: 1px solid var(--website-border); border-radius: 16px; background: var(--website-surface-elevated); color: var(--website-ink); box-shadow: var(--website-shadow); }
.search-popover { top: calc(100% + 9px); width: 100%; min-width: min(280px,calc(100vw - 24px)); transform-origin: top right; }
.search-popover nav { display: grid; gap: 3px; max-height: min(340px,calc(100vh - 100px)); overflow-y: auto; }
.search-popover a { display: flex; justify-content: space-between; gap: 12px; padding: 9px; border-radius: 9px; color: var(--website-ink); text-decoration: none; font-size: 13px; }
.search-popover a:hover,.search-popover a.selected { background: var(--website-hover); box-shadow: inset 3px 0 var(--website-gold); }
.search-popover a:focus-visible { outline: 2px solid var(--website-focus); outline-offset: -2px; }
.search-popover small,.search-popover p { color: var(--website-muted); }
.search-popover p { margin: 0; padding: 13px 10px; font-size: 12px; }
.search-results-enter-active,.search-results-leave-active { transition: opacity 150ms ease,transform 180ms cubic-bezier(.22,1,.36,1); }
.search-results-enter-from,.search-results-leave-to { opacity: 0; transform: translateY(-5px); }
.account-popover { width: 220px; display: grid; gap: 4px; }
.account-identity { display: grid; grid-template-columns: 38px minmax(0,1fr); align-items: center; gap: 10px; margin: 0 0 6px; padding: 5px 7px 12px; border-bottom: 1px solid var(--website-border); }
.popover-avatar { width: 38px; height: 38px; overflow: hidden; border-radius: 12px; font-size: 11px; }
.account-popover p { display: grid; gap: 2px; min-width: 0; margin: 0; }
.account-popover p strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.account-popover p span { color: var(--website-muted); font-size: 12px; }
.account-popover a,.account-popover button { min-height: 42px; display: flex; align-items: center; padding: 8px 10px; border: 0; border-radius: 9px; background: transparent; color: var(--website-ink); text-align: left; text-decoration: none; font: inherit; font-size: 13px; cursor: pointer; }
.account-popover a:hover,.account-popover button:hover { background: var(--website-hover); }
@media (max-width: 900px) { .website-topbar { min-height: 64px; padding: 10px max(14px,env(safe-area-inset-right)) 10px max(14px,env(safe-area-inset-left)); } .mobile-menu { display: grid; } .workspace-location span,.workspace-location b { display: none; } .account-copy,.account-trigger>span:last-child { display: none; } .account-trigger { grid-template-columns: 34px; padding: 4px; } .search-wrap.open { width: min(310px,40vw); } }
@media (max-width: 680px) { .website-topbar.search-active .workspace-location,.website-topbar.search-active .notification-button { display: none; } .website-topbar.search-active .topbar-actions { min-width: 0; flex: 1; justify-content: flex-end; } .search-wrap.open { width: min(310px,calc(100vw - 130px)); } }
@media (max-width: 480px) { .website-topbar { gap: 8px; } .mobile-menu { flex-shrink: 0; } .workspace-location strong { min-width: 0; max-width: 100%; } .topbar-actions { gap: 5px; } .search-wrap.open { width: min(280px,calc(100vw - 126px)); } .search-popover { min-width: 100%; } }
@media (prefers-reduced-motion: reduce) { .search-wrap,.search-surface,.search-results-enter-active,.search-results-leave-active { transition: none; } .search-input-content { animation: none; } }
</style>
