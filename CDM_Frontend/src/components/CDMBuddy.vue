<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { isRoleAllowedOnClient } from '../config/clientPlatform'
import {
  buddyGuidanceFor,
  buddyModuleForRoute,
  buddyMuteStorageKey,
  noticeMatchesBuddyModule,
} from '../config/buddyModuleGuidance'
import { useBuddyPosition } from '../composables/useBuddyPosition'
import { useBuddyProactive } from '../composables/useBuddyProactive'
import { acknowledgePortalNotice, getPortalNotices } from '../services/portalNoticeService'
import { useAuthStore } from '../stores/authStore'

const AUTO_MINIMIZE_DELAY = 10000
const PEEK_DELAY = 5000
const SLEEP_DELAY = 45000
const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const expanded = ref(false)
const loading = ref(false)
const notices = ref([])
const selected = ref(0)
const panel = ref(null)
const trigger = ref(null)
const interactionPaused = ref(false)
const acknowledging = ref(false)
const liveMessage = ref('')
const muted = ref(false)
const contextNotice = ref(null)
const showingContext = ref(false)
const openedAutomatically = ref(false)
const sleeping = ref(false)
const pageHidden = ref(false)
const greetingWave = ref(false)
let minimizeTimer
let peekTimer
let sleepTimer
let waveTimer
let minimizeStartedAt = 0
let minimizeRemaining = AUTO_MINIMIZE_DELAY
let loadedForAccount = null
let noticeLoadGeneration = 0

const excludedRoutes = new Set(['settings', 'professor-settings', 'registrar-settings', 'login', 'register', 'terms', 'privacy', 'unauthorized'])
const accountKey = computed(() => authStore.currentUser?.id || authStore.currentUser?.username || 'session')
const accountFirstName = computed(() => authStore.currentUser?.profile?.first_name || '')
const muteKey = computed(() => buddyMuteStorageKey(accountKey.value))
const seenKey = computed(() => `cdm-buddy-seen:${accountKey.value}`)
const buddyVisible = computed(() => {
  const role = authStore.currentRole
  return authStore.isAuthenticated
    && ['Student', 'Guest'].includes(role)
    && isRoleAllowedOnClient(role)
    && route.meta.roles?.includes(role)
    && !excludedRoutes.has(String(route.name || ''))
})
const moduleKey = computed(() => buddyVisible.value ? buddyModuleForRoute(route.name) : null)
const fallbackNotice = computed(() => buddyGuidanceFor(moduleKey.value || 'portal', authStore.currentRole, accountFirstName.value))
const displayNotices = computed(() => notices.value.length ? notices.value : [fallbackNotice.value])
const notice = computed(() => showingContext.value
  ? contextNotice.value || fallbackNotice.value
  : displayNotices.value[selected.value] || fallbackNotice.value)
const isBlockingNotice = computed(() =>
  notice.value.acknowledgement?.required && !notice.value.acknowledgement?.acknowledged,
)
const isAutoPersistent = computed(() => notice.value.priority === 'urgent' || isBlockingNotice.value)
const hasAttentionNotice = computed(() => displayNotices.value.some(item =>
  item.priority === 'urgent' || (item.acknowledgement?.required && !item.acknowledgement?.acknowledged),
))
const statusText = computed(() => loading.value
  ? 'Checking for verified updates…'
  : notices.value.length
    ? `${notices.value.length} Portal update${notices.value.length === 1 ? '' : 's'}`
    : 'Ready with informational guidance')

function seenIds() {
  try { return JSON.parse(sessionStorage.getItem(seenKey.value) || '[]') }
  catch { return [] }
}

function markSeen(id) {
  sessionStorage.setItem(seenKey.value, JSON.stringify([...new Set([...seenIds(), id])]))
}

function pageIsBusy() {
  const active = document.activeElement
  const editing = active?.matches?.('input, textarea, select, [contenteditable="true"]')
  const activeForm = document.querySelector('form:focus-within, [data-buddy-sensitive="true"]')
  const modal = document.querySelector('dialog[open], [role="dialog"][aria-modal="true"]')
  const sensitiveRoute = route.name === 'student-admission-exam'
  return Boolean(editing || activeForm || sensitiveRoute || (expanded.value && isBlockingNotice.value) || (modal && modal !== panel.value))
}

function highestUnseenIndex() {
  const seen = seenIds()
  const index = displayNotices.value.findIndex(item => !seen.includes(item.id))
  return index >= 0 ? index : 0
}

function openNotice(reason = 'timer') {
  expanded.value = true
  openedAutomatically.value = reason !== 'manual'
  position.setPeek(false)
  liveMessage.value = `${notice.value.title}. ${notice.value.message}`
  greetingWave.value = reason === 'interaction' || reason === 'navigation'
  window.clearTimeout(waveTimer)
  waveTimer = window.setTimeout(() => { greetingWave.value = false }, 2400)
  scheduleMinimize()
}

function announceModule(module, reason = 'navigation') {
  const relevantIndex = notices.value.findIndex(item => noticeMatchesBuddyModule(item, module))
  if (relevantIndex >= 0) {
    selected.value = relevantIndex
    showingContext.value = false
  } else {
    contextNotice.value = buddyGuidanceFor(module, authStore.currentRole, accountFirstName.value)
    showingContext.value = true
  }
  openNotice(reason)
}

const proactive = useBuddyProactive({
  accountKey,
  enabled: buddyVisible,
  muted,
  moduleKey,
  isBusy: pageIsBusy,
  onOpen: announceModule,
})

const position = useBuddyPosition({
  accountKey,
  triggerRef: trigger,
  enabled: buddyVisible,
  onDragStart: () => {
    if (expanded.value && !isBlockingNotice.value) minimize(false)
    resetInactivity()
  },
})

const mascotState = computed(() => ({
  dragging: position.dragging.value,
  sleeping: sleeping.value && !expanded.value,
  greeting: greetingWave.value,
  curious: expanded.value && !greetingWave.value,
  attention: hasAttentionNotice.value && !expanded.value,
  hidden: pageHidden.value,
  peeking: position.peeking.value,
  positioned: position.initialized.value,
  [`dock-${position.dockSide.value}`]: true,
}))
const bubbleAbove = computed(() => position.position.value.y > (window.visualViewport?.height || window.innerHeight) / 2)

function clearDisplayTimers() {
  window.clearTimeout(minimizeTimer)
  window.clearTimeout(peekTimer)
  window.clearTimeout(sleepTimer)
  window.clearTimeout(waveTimer)
}

function scheduleMinimize() {
  window.clearTimeout(minimizeTimer)
  if (!expanded.value || isAutoPersistent.value || interactionPaused.value || document.hidden) return
  minimizeStartedAt = Date.now()
  minimizeTimer = window.setTimeout(() => minimize(false), minimizeRemaining)
}

function pauseMinimize() {
  interactionPaused.value = true
  if (minimizeStartedAt) minimizeRemaining = Math.max(500, minimizeRemaining - (Date.now() - minimizeStartedAt))
  window.clearTimeout(minimizeTimer)
  minimizeStartedAt = 0
  resetInactivity()
}

function resumeMinimize() {
  interactionPaused.value = false
  scheduleMinimize()
}

function schedulePeek() {
  window.clearTimeout(peekTimer)
  if (expanded.value || hasAttentionNotice.value || !buddyVisible.value) return
  peekTimer = window.setTimeout(() => position.setPeek(true), PEEK_DELAY)
}

function resetInactivity() {
  sleeping.value = false
  window.clearTimeout(sleepTimer)
  if (!document.hidden) sleepTimer = window.setTimeout(() => { sleeping.value = true }, SLEEP_DELAY)
}

async function loadNotices(force = false) {
  if (!buddyVisible.value || (!force && loadedForAccount === accountKey.value)) return
  const requestedAccount = accountKey.value
  const requestGeneration = ++noticeLoadGeneration
  loadedForAccount = requestedAccount
  loading.value = true
  try {
    const data = await getPortalNotices()
    if (requestGeneration !== noticeLoadGeneration || accountKey.value !== requestedAccount) return
    notices.value = Array.isArray(data?.notices) ? data.notices : []
    if (expanded.value && notices.value.length) {
      if (!showingContext.value) {
        selected.value = highestUnseenIndex()
        liveMessage.value = `${notice.value.title}. ${notice.value.message}`
      }
    } else {
      const seen = seenIds()
      const importantUnseen = notices.value.some(item =>
        !seen.includes(item.id)
        && (item.priority === 'urgent' || (item.acknowledgement?.required && !item.acknowledgement?.acknowledged)),
      )
      if (importantUnseen && !muted.value && !pageIsBusy()) {
        selected.value = highestUnseenIndex()
        showingContext.value = false
        proactive.cancel()
        openNotice('notice')
      }
    }
  } catch {
    if (requestGeneration !== noticeLoadGeneration || accountKey.value !== requestedAccount) return
    loadedForAccount = null
    notices.value = []
  } finally {
    if (requestGeneration === noticeLoadGeneration) loading.value = false
  }
}

async function toggle() {
  if (!position.allowClick()) return
  if (loadedForAccount === null) void loadNotices()
  resetInactivity()
  position.setPeek(false)
  if (expanded.value) {
    minimize()
    return
  }
  showingContext.value = false
  selected.value = Math.min(selected.value, displayNotices.value.length - 1)
  minimizeRemaining = AUTO_MINIMIZE_DELAY
  openNotice('manual')
  await nextTick()
  panel.value?.focus()
}

function minimize(restoreFocus = true) {
  expanded.value = false
  openedAutomatically.value = false
  interactionPaused.value = false
  minimizeRemaining = AUTO_MINIMIZE_DELAY
  window.clearTimeout(minimizeTimer)
  minimizeStartedAt = 0
  schedulePeek()
  if (restoreFocus) nextTick(() => trigger.value?.focus())
}

function nextNotice() {
  showingContext.value = false
  selected.value = (selected.value + 1) % displayNotices.value.length
  markSeen(notice.value.id)
  minimizeRemaining = AUTO_MINIMIZE_DELAY
  liveMessage.value = `${notice.value.title}. ${notice.value.message}`
  scheduleMinimize()
}

function restoreMutePreference() {
  muted.value = localStorage.getItem(muteKey.value) === 'true'
}

function toggleMute() {
  muted.value = !muted.value
  localStorage.setItem(muteKey.value, String(muted.value))
  liveMessage.value = muted.value ? 'Automatic CDM Buddy messages muted.' : 'Automatic CDM Buddy messages unmuted.'
  if (muted.value && expanded.value && openedAutomatically.value && !isBlockingNotice.value) minimize(false)
}

async function acknowledge() {
  if (!notice.value.id.startsWith('campus-')) return
  acknowledging.value = true
  try {
    await acknowledgePortalNotice(notice.value.id.slice(7))
    notice.value.acknowledgement.acknowledged = true
    liveMessage.value = 'Notice acknowledged.'
    minimizeRemaining = AUTO_MINIMIZE_DELAY
    scheduleMinimize()
  } finally {
    acknowledging.value = false
  }
}

async function openTarget() {
  if (!notice.value.link) return
  minimize(false)
  await router.push(notice.value.link)
}

function onTriggerKeydown(event) {
  if (position.nudge(event)) resetInactivity()
}

function onGlobalKeydown(event) {
  resetInactivity()
  if (event.key === 'Escape' && expanded.value && !isBlockingNotice.value) minimize()
}

function onVisibilityChange() {
  pageHidden.value = document.hidden
  if (document.hidden) {
    pauseMinimize()
    window.clearTimeout(sleepTimer)
  } else {
    resumeMinimize()
    resetInactivity()
  }
}

function onFocusOut(event) {
  if (!panel.value?.contains(event.relatedTarget)) resumeMinimize()
}

watch(buddyVisible, visible => {
  if (visible) {
    void loadNotices()
    resetInactivity()
  } else {
    expanded.value = false
    position.setPeek(false)
    window.clearTimeout(minimizeTimer)
    window.clearTimeout(peekTimer)
  }
})
watch(accountKey, () => {
  loadedForAccount = null
  notices.value = []
  selected.value = 0
  showingContext.value = false
  contextNotice.value = null
  restoreMutePreference()
  void loadNotices(true)
})
watch(moduleKey, (module, previous) => {
  if (module && module !== previous && expanded.value && openedAutomatically.value && !isBlockingNotice.value) minimize(false)
})
watch(isAutoPersistent, persistent => { if (!persistent) scheduleMinimize() })

onMounted(() => {
  restoreMutePreference()
  window.addEventListener('keydown', onGlobalKeydown)
  window.addEventListener('pointerdown', resetInactivity, { passive: true })
  document.addEventListener('visibilitychange', onVisibilityChange)
  proactive.resetForAccount()
  void loadNotices()
  resetInactivity()
})
onBeforeUnmount(() => {
  noticeLoadGeneration++
  clearDisplayTimers()
  window.removeEventListener('keydown', onGlobalKeydown)
  window.removeEventListener('pointerdown', resetInactivity)
  document.removeEventListener('visibilitychange', onVisibilityChange)
})
</script>

<template>
  <aside
    v-show="buddyVisible"
    class="cdm-buddy"
    :class="mascotState"
    :style="position.style.value"
    aria-label="CDM Buddy campus companion"
  >
    <p class="sr-only" aria-live="polite">{{ liveMessage }}</p>
    <Transition name="buddy-bubble">
      <section
        v-if="expanded"
        id="cdm-buddy-panel"
        ref="panel"
        class="buddy-card"
        :class="{ above: bubbleAbove }"
        role="dialog"
        aria-modal="false"
        aria-labelledby="buddy-title"
        tabindex="-1"
        @mouseenter="pauseMinimize"
        @mouseleave="resumeMinimize"
        @focusin="pauseMinimize"
        @focusout="onFocusOut"
        @pointerdown="pauseMinimize"
      >
        <div class="buddy-head">
          <div>
            <span class="buddy-category" :class="[notice.kind, notice.priority]">
              {{ notice.kind === 'official' ? 'Verified' : 'Info' }} · {{ notice.type?.replaceAll('_', ' ') || 'guidance' }}
            </span>
            <strong id="buddy-title">{{ notice.title }}</strong>
          </div>
          <div class="buddy-head-actions">
            <button
              class="icon-button"
              type="button"
              :aria-label="muted ? 'Unmute automatic CDM Buddy messages' : 'Mute automatic CDM Buddy messages'"
              :title="muted ? 'Unmute automatic messages' : 'Mute automatic messages'"
              :aria-pressed="muted"
              @click="toggleMute"
            >
              <svg v-if="muted" viewBox="0 0 24 24" aria-hidden="true"><path d="M13.7 21a2 2 0 0 1-3.4 0M18 8a6 6 0 0 0-9.3-5M6.3 6.3A6 6 0 0 0 6 8c0 7-3 7-3 9h14M3 3l18 18" /></svg>
              <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0" /></svg>
            </button>
            <button v-if="!isBlockingNotice" class="icon-button" type="button" aria-label="Close CDM Buddy message" title="Close" @click="minimize()">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg>
            </button>
          </div>
        </div>
        <p class="buddy-message">{{ notice.message }}</p>
        <p v-if="notice.effective_at || notice.expires_at" class="buddy-period">
          Effective {{ notice.effective_at ? new Date(notice.effective_at).toLocaleString() : 'now' }}
          <template v-if="notice.expires_at"> until {{ new Date(notice.expires_at).toLocaleString() }}</template>
        </p>
        <div class="buddy-primary-actions">
          <button
            v-if="notice.acknowledgement?.required && !notice.acknowledgement?.acknowledged"
            class="primary-action"
            type="button"
            :disabled="acknowledging"
            @click="acknowledge"
          >{{ acknowledging ? 'Saving…' : 'Acknowledge' }}</button>
          <button v-if="notice.link" class="primary-action" type="button" @click="openTarget">Open related page</button>
          <a v-if="notice.attribution_url" class="secondary-action" :href="notice.attribution_url" target="_blank" rel="noopener noreferrer">Weather source</a>
        </div>
        <div class="buddy-secondary-actions">
          <button
            v-if="showingContext ? notices.length > 0 : displayNotices.length > 1"
            class="icon-button next-button"
            type="button"
            aria-label="Show next CDM Buddy notice"
            title="Next notice"
            @click="nextNotice"
          ><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg></button>
        </div>
        <small>{{ notice.source_label }} · {{ statusText }}</small>
      </section>
    </Transition>

    <button
      ref="trigger"
      class="buddy-trigger"
      type="button"
      :aria-expanded="expanded"
      aria-controls="cdm-buddy-panel"
      :aria-label="expanded ? 'Minimize CDM Buddy' : position.peeking.value ? 'Show and open CDM Buddy' : 'Open CDM Buddy. Drag to reposition.'"
      @click="toggle"
      @keydown="onTriggerKeydown"
      @pointerdown="position.onPointerDown"
      @pointermove="position.onPointerMove"
      @pointerup="position.onPointerUp"
      @pointercancel="position.onPointerUp"
    >
      <span v-if="hasAttentionNotice" class="attention-dot" aria-label="Important notice available"></span>
      <svg viewBox="0 0 100 112" role="img" aria-label="Smiling green mountain explorer mascot" xmlns="http://www.w3.org/2000/svg">
        <g class="mascot-body">
          <path d="M16 102 Q49 84 84 102 L87 110 H13Z" fill="#286b45"/>
          <path d="M35 70 L22 82 L29 103 L72 103 L78 82 L65 70" fill="#4d9d61" stroke="#19472e" stroke-width="3"/>
          <path d="M29 79 L16 93 M71 79 L84 91" stroke="#e9b288" stroke-width="10" stroke-linecap="round"/>
          <path d="M33 99 L31 110 M67 99 L69 110" stroke="#77563d" stroke-width="13" stroke-linecap="round"/>
        </g>
        <g class="mascot-head">
          <path d="M27 31 Q17 57 29 73 Q49 91 72 71 Q85 53 73 30Z" fill="#f5c89e" stroke="#5c392b" stroke-width="2.5"/>
          <path d="M23 41 Q27 15 53 17 Q76 18 79 42 Q63 36 55 28 Q46 44 23 41" fill="#513729"/>
          <path d="M12 34 Q27 7 47 6 Q70 7 90 35 Q77 42 50 40 Q25 42 12 34" fill="#2e804a" stroke="#19472e" stroke-width="3"/>
          <path d="M35 14 L45 5 L54 13 L62 7 L75 24 Q51 21 26 25Z" fill="#e5f6d4"/>
          <g class="mascot-eyes"><ellipse cx="37" cy="53" rx="6" ry="8" fill="#392a22"/><ellipse cx="62" cy="53" rx="6" ry="8" fill="#392a22"/><circle cx="39" cy="50" r="2" fill="white"/><circle cx="64" cy="50" r="2" fill="white"/></g>
          <path d="M42 68 Q50 77 59 68" fill="none" stroke="#943d39" stroke-width="3.5" stroke-linecap="round"/>
          <path d="M43 79 L50 86 L58 79" fill="none" stroke="#eaf2d8" stroke-width="3"/>
        </g>
        <g class="mascot-wave-arm"><path d="M26 77 Q18 67 14 56" fill="none" stroke="#e9b288" stroke-width="9" stroke-linecap="round"/><circle cx="12" cy="50" r="8" fill="#f5c89e"/></g>
      </svg>
      <span v-if="sleeping && !expanded" class="sleep-mark" aria-hidden="true">zZ</span>
    </button>
  </aside>
</template>

<style scoped>
.sr-only { clip: rect(0 0 0 0); clip-path: inset(50%); height: 1px; overflow: hidden; position: absolute; white-space: nowrap; width: 1px; }
.cdm-buddy { height: 100px; opacity: 0; pointer-events: none; position: fixed; transition: left .24s cubic-bezier(.2,.8,.2,1), top .24s cubic-bezier(.2,.8,.2,1), transform .24s ease, opacity .12s ease; width: 82px; z-index: 28; }
.cdm-buddy.positioned { opacity: 1; }
.cdm-buddy.dragging { transition: none; z-index: 40; }
.cdm-buddy.peeking.dock-right { transform: translateX(48px); }
.cdm-buddy.peeking.dock-left { transform: translateX(-48px); }
.cdm-buddy > * { pointer-events: auto; }
.buddy-trigger { align-items: center; background: transparent; border: 0; color: #165338; cursor: grab; display: grid; font: inherit; height: 100px; justify-items: center; padding: 0; position: relative; touch-action: none; user-select: none; width: 82px; }
.buddy-trigger::before { background: radial-gradient(ellipse, #dff5d8b8 0%, #dff5d840 48%, transparent 72%); border-radius: 50%; content: ''; inset: 24px 1px 2px; pointer-events: none; position: absolute; }
.buddy-trigger:active { cursor: grabbing; }
.buddy-trigger:focus-visible, .buddy-card button:focus-visible, .buddy-card a:focus-visible { outline: 3px solid #f4d35e; outline-offset: 3px; }
.buddy-trigger > svg { animation: buddy-float 4s ease-in-out infinite; display: block; filter: drop-shadow(0 8px 8px #123b2438); height: 94px; overflow: visible; position: relative; transform-origin: 50% 100%; width: 78px; }
.attention-dot { background: #d64141; border: 3px solid #fff; border-radius: 50%; height: 16px; position: absolute; right: -2px; top: -2px; width: 16px; z-index: 2; }
.sleep-mark { color: #2c6d48; font-size: .78rem; font-weight: 900; position: absolute; right: 4px; top: 8px; }
.mascot-body { animation: buddy-breathe 3.2s ease-in-out infinite; transform-box: fill-box; transform-origin: center bottom; }
.mascot-head { transform-box: fill-box; transform-origin: center bottom; transition: transform .28s ease; }
.mascot-eyes { animation: buddy-blink 7s ease-in-out infinite; transform-box: fill-box; transform-origin: center; }
.mascot-wave-arm { transform-box: fill-box; transform-origin: 80% 90%; }
.greeting .mascot-wave-arm { animation: buddy-wave .5s ease-in-out 4; }
.curious .mascot-head { transform: rotate(4deg) translateY(-1px); }
.attention .buddy-trigger { animation: buddy-attention 1.6s ease-in-out 2; }
.dragging .buddy-trigger svg { transform: rotate(-5deg) scale(.96); }
.sleeping .buddy-trigger svg { animation: none; transform: translateY(4px) scale(.98); }
.sleeping .mascot-eyes { animation: none; transform: scaleY(.14); }
.hidden .buddy-trigger svg, .hidden .mascot-body, .hidden .mascot-eyes { animation-play-state: paused; }
.buddy-card { --buddy-bg: var(--website-surface-elevated, #fff); --buddy-text: var(--website-ink, #183727); backdrop-filter: blur(14px); background: color-mix(in srgb, var(--buddy-bg) 94%, var(--website-success-bg, #dff4e4)); border: 1px solid var(--website-success-border, #7dad8c); border-radius: 22px 22px 7px 22px; box-shadow: var(--website-shadow, 0 18px 48px #102d1c35); color: var(--buddy-text); line-height: 1.45; max-height: min(520px, calc(100dvh - 130px)); overflow: auto; padding: 15px 16px; position: absolute; top: calc(100% + 10px); width: min(350px, calc(100vw - 28px)); }
.buddy-card.above { bottom: calc(100% + 10px); top: auto; }
.dock-right .buddy-card { border-radius: 22px 22px 22px 7px; right: 0; }.dock-left .buddy-card { left: 0; }
.buddy-head { align-items: flex-start; display: flex; gap: 12px; justify-content: space-between; }
.buddy-head > div { display: grid; gap: 5px; }.buddy-head strong { font-size: 1rem; line-height: 1.25; }
.buddy-head-actions { display: flex !important; gap: 5px !important; }
.icon-button { align-items: center; background: var(--website-success-bg, #e7f3e9); border: 0; border-radius: 50%; color: var(--website-success, #25553c); cursor: pointer; display: inline-flex; flex: 0 0 auto; height: 40px; justify-content: center; padding: 0; width: 40px; }
.icon-button svg { fill: none; height: 19px; pointer-events: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round; stroke-width: 2; width: 19px; }
.buddy-category { align-items: center; background: var(--website-surface-soft, #e7efe9); border-radius: 999px; color: var(--website-muted, #506359); display: inline-flex; font-size: .62rem; font-weight: 850; letter-spacing: .04em; padding: 4px 8px; text-transform: uppercase; width: fit-content; }
.buddy-category.official { background: var(--website-success-bg, #dff2e5); color: var(--website-success, #176341); }.buddy-category.urgent { background: var(--website-danger-bg, #fee5e5); color: var(--website-danger, #9c2727); }
.buddy-message { font-size: .86rem; margin: 10px 0; }.buddy-period { color: var(--website-muted, #52685a); font-size: .7rem; margin: -3px 0 10px; }
.buddy-primary-actions, .buddy-secondary-actions { align-items: center; display: flex; flex-wrap: wrap; gap: 7px; }
.buddy-primary-actions { margin-bottom: 8px; }.buddy-primary-actions a { text-decoration: none; }
.primary-action { background: var(--website-emerald, #216843); border: 1px solid var(--website-emerald, #216843); border-radius: 10px; color: var(--website-on-primary, #fff); cursor: pointer; font: inherit; font-size: .75rem; font-weight: 800; padding: 8px 10px; }
.secondary-action { background: transparent; border: 0; color: var(--website-success, #246745); cursor: pointer; font: inherit; font-size: .7rem; font-weight: 750; padding: 4px 2px; text-decoration: underline; text-underline-offset: 2px; }
.buddy-secondary-actions { justify-content: flex-end; min-height: 40px; }.next-button { background: transparent; }
.buddy-card small { color: var(--website-muted, #627668); display: block; font-size: .64rem; margin-top: 8px; }
.buddy-bubble-enter-active, .buddy-bubble-leave-active { transition: opacity .2s ease, transform .22s cubic-bezier(.2,.8,.2,1); }
.buddy-bubble-enter-from, .buddy-bubble-leave-to { opacity: 0; transform: translateY(8px) scale(.97); }
:global(html[data-student-theme='dark'].website-ui-2-client) .buddy-card { color-scheme: dark; }
@media (prefers-color-scheme: dark) { :global(html[data-student-theme='system'].website-ui-2-client) .buddy-card { color-scheme: dark; } }
@keyframes buddy-float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-3px); } }
@keyframes buddy-breathe { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.015, .985); } }
@keyframes buddy-blink { 0%, 45%, 48%, 100% { transform: scaleY(1); } 46%, 47% { transform: scaleY(.12); } }
@keyframes buddy-wave { 0%, 100% { transform: rotate(0); } 50% { transform: rotate(-20deg); } }
@keyframes buddy-attention { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-4px); } }
@media (max-width: 520px) {
  .cdm-buddy { height: 86px; width: 70px; }.buddy-trigger { height: 86px; width: 70px; }.buddy-trigger > svg { height: 82px; width: 68px; }.buddy-card { max-height: min(440px, calc(100dvh - 110px)); width: min(330px, calc(100vw - 24px)); }.icon-button { height: 44px; width: 44px; }.cdm-buddy.peeking.dock-right { transform: translateX(40px); }.cdm-buddy.peeking.dock-left { transform: translateX(-40px); }
}
@media (prefers-reduced-motion: reduce) {
  .cdm-buddy, .buddy-trigger, .buddy-trigger svg, .mascot-body, .mascot-head, .mascot-eyes, .mascot-wave-arm, .buddy-bubble-enter-active, .buddy-bubble-leave-active { animation: none !important; scroll-behavior: auto; transition-duration: .01ms !important; }
}
</style>
