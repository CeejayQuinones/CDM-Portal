<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { getPortalNotices } from '../services/portalNoticeService'
import { useAuthStore } from '../stores/authStore'

const route = useRoute()
const authStore = useAuthStore()
const expanded = ref(false)
const dismissed = ref(false)
const loading = ref(false)
const notices = ref([])
const selected = ref(0)
const panel = ref(null)
const trigger = ref(null)

const fallbackNotices = [
  {
    id: 'guide-records',
    title: 'Keep your portal details current',
    message: 'Review your profile and academic records, then report anything that needs correction.',
    kind: 'information',
    source_label: 'CDM Buddy guide',
    link: '/settings',
  },
  {
    id: 'guide-official',
    title: 'Use verified school channels',
    message: 'CDM Buddy shows official updates only when they come from an approved Portal record.',
    kind: 'information',
    source_label: 'CDM Buddy guide',
    link: null,
  },
]

const onDashboard = computed(() => ['student-dashboard', 'guest-dashboard'].includes(route.name))
const storageKey = computed(() => `cdm-buddy-dismissed:${authStore.currentUser?.id || authStore.currentUser?.username || 'session'}`)
const displayNotices = computed(() => notices.value.length ? notices.value : fallbackNotices)
const notice = computed(() => displayNotices.value[selected.value] || fallbackNotices[0])
const statusText = computed(() => loading.value
  ? 'Checking for verified updates…'
  : notices.value.length
    ? `${notices.value.length} verified Portal update${notices.value.length === 1 ? '' : 's'}`
    : 'Informational guidance')

function restoreDismissedState() {
  dismissed.value = sessionStorage.getItem(storageKey.value) === 'true'
}

async function loadNotices() {
  if (!onDashboard.value || !['Student', 'Guest'].includes(authStore.currentRole)) return
  loading.value = true
  try {
    const data = await getPortalNotices()
    notices.value = Array.isArray(data?.notices) ? data.notices : []
  } catch {
    notices.value = []
  } finally {
    loading.value = false
  }
}

async function toggle() {
  expanded.value = !expanded.value
  if (expanded.value) {
    await nextTick()
    panel.value?.focus()
  }
}

function minimize(restoreFocus = true) {
  expanded.value = false
  if (restoreFocus) nextTick(() => trigger.value?.focus())
}

function dismiss() {
  sessionStorage.setItem(storageKey.value, 'true')
  expanded.value = false
  dismissed.value = true
}

function nextNotice() {
  selected.value = (selected.value + 1) % displayNotices.value.length
}

function onKeydown(event) {
  if (event.key === 'Escape' && expanded.value) minimize()
}

watch(() => route.name, () => {
  expanded.value = false
  selected.value = 0
})
watch(storageKey, restoreDismissedState)

onMounted(() => {
  restoreDismissedState()
  window.addEventListener('keydown', onKeydown)
  void loadNotices()
})
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <aside v-if="onDashboard && !dismissed" class="cdm-buddy" aria-label="CDM Buddy portal assistant">
    <section
      v-if="expanded"
      id="cdm-buddy-panel"
      ref="panel"
      class="buddy-card"
      role="dialog"
      aria-modal="false"
      aria-labelledby="buddy-title"
      tabindex="-1"
    >
      <div class="buddy-head">
        <div>
          <span class="buddy-source" :class="{ official: notice.kind === 'official' }">
            {{ notice.kind === 'official' ? 'Verified update' : 'Information' }}
          </span>
          <strong id="buddy-title">{{ notice.title }}</strong>
        </div>
        <button type="button" aria-label="Minimize CDM Buddy" @click="minimize()">×</button>
      </div>
      <p>{{ notice.message }}</p>
      <a v-if="notice.link" class="buddy-link" :href="`#${notice.link}`">Open in Portal</a>
      <div class="buddy-actions">
        <button v-if="displayNotices.length > 1" type="button" @click="nextNotice">Next message</button>
        <button type="button" @click="dismiss">Dismiss for this session</button>
      </div>
      <small>{{ notice.source_label }} · {{ statusText }}</small>
    </section>

    <button
      ref="trigger"
      class="buddy-trigger"
      type="button"
      :aria-expanded="expanded"
      aria-controls="cdm-buddy-panel"
      aria-label="Open CDM Buddy"
      @click="toggle"
    >
      <svg viewBox="0 0 100 112" role="img" aria-label="Smiling green mountain explorer mascot" xmlns="http://www.w3.org/2000/svg">
        <path d="M16 102 Q49 84 84 102 L87 110 H13Z" fill="#286b45"/>
        <path d="M35 70 L22 82 L29 103 L72 103 L78 82 L65 70" fill="#4d9d61" stroke="#19472e" stroke-width="3"/>
        <path d="M29 79 L16 93 M71 79 L84 91" stroke="#e9b288" stroke-width="10" stroke-linecap="round"/>
        <path d="M33 99 L31 110 M67 99 L69 110" stroke="#77563d" stroke-width="13" stroke-linecap="round"/>
        <path d="M27 31 Q17 57 29 73 Q49 91 72 71 Q85 53 73 30Z" fill="#f5c89e" stroke="#5c392b" stroke-width="2.5"/>
        <path d="M23 41 Q27 15 53 17 Q76 18 79 42 Q63 36 55 28 Q46 44 23 41" fill="#513729"/>
        <path d="M12 34 Q27 7 47 6 Q70 7 90 35 Q77 42 50 40 Q25 42 12 34" fill="#2e804a" stroke="#19472e" stroke-width="3"/>
        <path d="M35 14 L45 5 L54 13 L62 7 L75 24 Q51 21 26 25Z" fill="#e5f6d4"/>
        <ellipse cx="37" cy="53" rx="6" ry="8" fill="#392a22"/><ellipse cx="62" cy="53" rx="6" ry="8" fill="#392a22"/>
        <circle cx="39" cy="50" r="2" fill="white"/><circle cx="64" cy="50" r="2" fill="white"/>
        <path d="M42 68 Q50 77 59 68" fill="none" stroke="#943d39" stroke-width="3.5" stroke-linecap="round"/>
        <path d="M43 79 L50 86 L58 79" fill="none" stroke="#eaf2d8" stroke-width="3"/>
        <path d="M26 77 Q18 67 14 56" fill="none" stroke="#e9b288" stroke-width="9" stroke-linecap="round"/>
        <circle cx="12" cy="50" r="8" fill="#f5c89e"/>
      </svg>
      <span>CDM Buddy</span>
    </button>
  </aside>
</template>

<style scoped>
.cdm-buddy { align-items: flex-end; bottom: calc(20px + env(safe-area-inset-bottom)); display: flex; flex-direction: column; gap: 10px; pointer-events: none; position: fixed; right: max(16px, env(safe-area-inset-right)); z-index: 18; }
.cdm-buddy > * { pointer-events: auto; }
.buddy-trigger { background: #e9f8e7; border: 2px solid #256b43; border-radius: 22px; box-shadow: 0 8px 22px #0003; color: #165338; cursor: pointer; display: grid; font: inherit; font-size: 0.7rem; font-weight: 800; justify-items: center; line-height: 1.4; padding: 5px 7px 4px; transition: transform .2s ease; }
.buddy-trigger:hover { transform: translateY(-3px); }
.buddy-trigger:focus-visible, .buddy-card button:focus-visible, .buddy-link:focus-visible { outline: 3px solid #f4d35e; outline-offset: 3px; }
.buddy-trigger svg { display: block; height: 78px; width: 72px; }
.buddy-card { background: #fff; border: 2px solid #347c52; border-radius: 20px; box-shadow: 0 12px 34px #0003; color: #183727; line-height: 1.45; max-width: min(360px, calc(100vw - 32px)); padding: 16px 18px; }
.buddy-head { align-items: flex-start; display: flex; gap: 12px; justify-content: space-between; }
.buddy-head > div { display: grid; gap: 4px; }
.buddy-head strong { font-size: 1rem; }
.buddy-head button { background: transparent; border: 0; color: #25553c; cursor: pointer; font-size: 1.6rem; line-height: 1; }
.buddy-source { color: #627668; font-size: .68rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
.buddy-source.official { color: #176341; }
.buddy-card p { font-size: .88rem; margin: 10px 0; }
.buddy-link { color: #176341; display: inline-block; font-size: .82rem; font-weight: 800; margin-bottom: 8px; }
.buddy-actions { display: flex; flex-wrap: wrap; gap: 8px 14px; }
.buddy-actions button { background: none; border: 0; color: #176341; cursor: pointer; font: inherit; font-size: .78rem; font-weight: 800; padding: 5px 0; }
.buddy-card small { color: #627668; display: block; font-size: .68rem; margin-top: 8px; }
@media (max-width: 760px) {
  .cdm-buddy { align-items: stretch; bottom: auto; margin-top: 20px; position: static; right: auto; }
  .buddy-trigger { align-self: flex-end; grid-template-columns: 38px auto; padding: 4px 10px 4px 5px; }
  .buddy-trigger svg { height: 42px; width: 38px; }
  .buddy-card { max-width: none; width: 100%; }
}
@media (prefers-reduced-motion: reduce) { .buddy-trigger { transition: none; } .buddy-trigger:hover { transform: none; } }
</style>
