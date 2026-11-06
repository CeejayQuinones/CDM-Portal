<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import CDMBuddy from '../components/CDMBuddy.vue'
import StepUpAuthModal from '../components/StepUpAuthModal.vue'
import WebsiteMobileNavigation from '../components/website/WebsiteMobileNavigation.vue'
import WebsiteNavigationRail from '../components/website/WebsiteNavigationRail.vue'
import WebsiteTopbar from '../components/website/WebsiteTopbar.vue'
import { WEBSITE_DESKTOP_QUERY, WEBSITE_NAVIGATION_LAYOUT_EVENT, readWebsiteNavigation, writeWebsiteNavigation } from '../composables/useWebsiteNavigation'
import { moduleContainsRoute, websiteModulesFromMenu } from '../config/websiteNavigation'
import { STUDENT_DASHBOARD_PROFILE_EVENT } from '../config/studentDashboardProfile'
import { useAuthStore } from '../stores/authStore'
import { useNavigationStore } from '../stores/navigation'
import { useStudentProfileStore } from '../stores/studentProfile'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const navigationStore = useNavigationStore()
const studentProfile = useStudentProfileStore()
const mobileNavigation = ref(null)
const mobileOpen = ref(false)
const accountOpen = ref(false)
const navigationCollapsed = ref(false)
const isDesktop = ref(typeof window !== 'undefined' && window.matchMedia(WEBSITE_DESKTOP_QUERY).matches)
let desktopMedia

const accountKey = computed(() => authStore.currentUser?.id || authStore.currentUser?.username || 'session')
const displayName = computed(() => {
  const profile = authStore.currentUser?.profile
  const preferred = authStore.currentRole === 'Student' ? studentProfile.preferredDisplayName : ''
  return preferred || [profile?.first_name, profile?.last_name].filter(Boolean).join(' ') || authStore.currentUser?.username || 'Portal User'
})
const initials = computed(() => displayName.value.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase())
const avatarUrl = computed(() => studentProfile.websiteAvatarUrl)
const modules = computed(() => websiteModulesFromMenu(navigationStore.menuItemsForRole(authStore.currentRole, authStore.currentUser?.admission)))
const routeModule = computed(() => modules.value.find(module => moduleContainsRoute(module, route)) || null)
const pageTitle = computed(() => route.meta.title || 'CDM Portal')

const notifyLayout = () => nextTick(() => window.dispatchEvent(new CustomEvent(WEBSITE_NAVIGATION_LAYOUT_EVENT)))
const persist = () => writeWebsiteNavigation(localStorage, accountKey.value, { panelOpen: false, moduleName: routeModule.value?.name, collapsed: navigationCollapsed.value })
const restore = () => {
  const stored = readWebsiteNavigation(localStorage, accountKey.value)
  navigationCollapsed.value = Boolean(stored.collapsed)
  notifyLayout()
}
const toggleNavigation = () => { navigationCollapsed.value = !navigationCollapsed.value; persist(); notifyLayout() }
const openMobile = () => { mobileOpen.value = true; nextTick(() => mobileNavigation.value?.focusFirst()) }
const closeMobile = (restoreFocus = false) => {
  mobileOpen.value = false
  if (restoreFocus) nextTick(() => document.querySelector('.website-topbar .mobile-menu')?.focus())
}
const handleEscape = event => {
  if (event.key !== 'Escape') return
  if (mobileOpen.value) closeMobile(true)
  else if (accountOpen.value) accountOpen.value = false
}
const handleViewport = event => {
  isDesktop.value = event.matches
  if (event.matches) closeMobile()
  notifyLayout()
}
const logout = async () => { await authStore.logout(); await router.replace({ name: 'login' }) }
const openStudentProfile = async () => {
  if (authStore.currentRole !== 'Student') return
  accountOpen.value = false
  if (route.name !== 'student-dashboard') await router.push({ name: 'student-dashboard' })
  await nextTick()
  window.dispatchEvent(new CustomEvent(STUDENT_DASHBOARD_PROFILE_EVENT, { detail: { expanded: true, focus: true } }))
}

watch(() => route.path, () => {
  accountOpen.value = false
  closeMobile()
  persist()
})
watch(accountKey, restore)
watch(mobileOpen, open => document.documentElement.classList.toggle('website-navigation-open', open))

onMounted(() => {
  document.documentElement.classList.add('website-ui-2-client', 'website-academic-client')
  desktopMedia = window.matchMedia(WEBSITE_DESKTOP_QUERY)
  isDesktop.value = desktopMedia.matches
  desktopMedia.addEventListener?.('change', handleViewport)
  window.addEventListener('keydown', handleEscape)
  restore()
})
onBeforeUnmount(() => {
  document.documentElement.classList.remove('website-ui-2-client', 'website-academic-client', 'website-navigation-open')
  desktopMedia?.removeEventListener?.('change', handleViewport)
  window.removeEventListener('keydown', handleEscape)
})

</script>

<template>
  <div class="website-ui-2-shell website-academic-shell" :class="{ 'navigation-collapsed': navigationCollapsed }">
    <StepUpAuthModal />
    <WebsiteNavigationRail :modules="modules" :active-route-name="route.name" :collapsed="navigationCollapsed" @toggle-collapse="toggleNavigation" />
    <WebsiteMobileNavigation ref="mobileNavigation" :modules="modules" :open="mobileOpen" :active-route-name="route.name" @close="closeMobile" />

    <div class="website-shell-content" :inert="mobileOpen">
      <WebsiteTopbar
        :title="pageTitle"
        :module-label="routeModule?.label || 'CDM Portal'"
        :modules="modules"
        :display-name="displayName"
        :role="authStore.currentRole"
        :initials="initials"
        :avatar-url="avatarUrl"
        :avatar-revision="studentProfile.websiteAvatarRevision"
        :account-open="accountOpen"
        @open-navigation="openMobile"
        @toggle-account="accountOpen = !accountOpen"
        @open-profile="openStudentProfile"
        @logout="logout"
      />
      <div class="website-route-stage">
        <RouterView v-slot="{ Component, route: currentRoute }">
          <Transition name="website-route">
            <div :key="currentRoute.path" class="website-route-view">
              <component :is="Component" />
            </div>
          </Transition>
        </RouterView>
      </div>
      <CDMBuddy />
    </div>
  </div>
</template>

<style scoped>
.website-ui-2-shell { min-height: 100vh; min-height: 100dvh; background: var(--website-canvas); color: var(--website-ink); }
.website-shell-content { position: relative; min-width: 0; min-height: 100vh; min-height: 100dvh; margin-left: 248px; overflow-x: clip; transition: margin-left 190ms ease; }
.navigation-collapsed .website-shell-content { margin-left: 72px; }
.website-route-stage { display: grid; grid-template-columns: minmax(0,1fr); align-items: start; width: 100%; min-width: 0; min-height: calc(100dvh - 72px); overflow-x: clip; }
.website-route-view { grid-area: 1 / 1; width: 100%; max-width: 100%; min-width: 0; min-height: calc(100dvh - 72px); padding: clamp(22px,3vw,42px); }
.website-route-enter-active,.website-route-leave-active { transition: opacity 150ms ease; }
.website-route-leave-active { pointer-events: none; }
.website-route-enter-from,.website-route-leave-to { opacity: 0; }
@media (max-width: 900px) {
  .website-shell-content { margin-left: 0; }
  .website-route-stage { min-height: calc(100dvh - 64px); }
  .website-route-view { min-height: calc(100dvh - 64px); padding: 20px max(16px,env(safe-area-inset-right)) calc(28px + env(safe-area-inset-bottom)) max(16px,env(safe-area-inset-left)); }
}
@media (max-width: 480px) { .website-route-view { padding-top: 16px; } }
@media (prefers-reduced-motion: reduce) { .website-shell-content,.website-route-enter-active,.website-route-leave-active { transition: none; } }
:global(html.website-navigation-open),:global(html.website-navigation-open body) { overflow: hidden; overscroll-behavior: none; }
</style>

<style src="../assets/styles/website-ui-2.css"></style>
<style src="../assets/styles/emerald-academic-web.css"></style>
<style src="../assets/styles/website-academic-modules.css"></style>
