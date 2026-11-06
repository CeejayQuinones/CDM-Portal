<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useAuthStore } from '../../stores/authStore'
import { useStudentProfileStore } from '../../stores/studentProfile'
import { STUDENT_DASHBOARD_PROFILE_EVENT, studentDashboardProfileContext } from '../../config/studentDashboardProfile'
import WebsiteUserAvatar from './WebsiteUserAvatar.vue'

const props = defineProps({
  identity: { type: Object, default: () => ({}) },
  loading: Boolean,
  expanded: Boolean,
})
const emit = defineEmits(['update:expanded'])

const authStore = useAuthStore()
const studentProfile = useStudentProfileStore()
const toggle = ref(null)
const authProfile = computed(() => authStore.currentUser?.profile || {})
const details = computed(() => studentDashboardProfileContext(props.identity, authProfile.value))
const displayName = computed(() => studentProfile.preferredDisplayName || props.identity.name || [authProfile.value.first_name, authProfile.value.last_name].filter(Boolean).join(' ') || authStore.currentUser?.username || 'Student')
const initials = computed(() => displayName.value.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase() || 'ST')
const academicContext = computed(() => [details.value.yearLevel, details.value.section ? `Section ${details.value.section}` : ''].filter(Boolean).join(' · '))
const available = value => value !== null && value !== undefined && String(value).trim() !== ''
const compactFacts = computed(() => [
  { label: 'Student number', value: details.value.studentNumber },
  { label: 'Academic level', value: academicContext.value },
  { label: 'Program', value: details.value.programName || details.value.program, wide: true },
].filter(row => available(row.value)))
const rows = computed(() => [
  { label: 'Official name', value: details.value.officialName, wide: true },
  { label: 'Program', value: details.value.programName || details.value.program, wide: true },
  { label: 'Student number', value: details.value.studentNumber },
  { label: 'Email', value: details.value.email },
  { label: 'Contact number', value: details.value.contactNumber },
  { label: 'Year level', value: details.value.yearLevel },
  { label: 'Section', value: details.value.section },
].filter(row => available(row.value)))

const setExpanded = (value, focus = false) => {
  if (props.expanded !== value) emit('update:expanded', value)
  if (focus) nextTick(() => toggle.value?.focus())
}
const toggleExpanded = () => setExpanded(!props.expanded)
const handleProfileAction = event => setExpanded(event.detail?.expanded !== false, Boolean(event.detail?.focus))

onMounted(() => window.addEventListener(STUDENT_DASHBOARD_PROFILE_EVENT, handleProfileAction))
onBeforeUnmount(() => window.removeEventListener(STUDENT_DASHBOARD_PROFILE_EVENT, handleProfileAction))
</script>

<template>
  <section class="student-dashboard-profile" :class="{ expanded }" aria-labelledby="student-profile-name" data-buddy-obstacle="true">
    <button ref="toggle" type="button" class="student-profile-expand" :aria-expanded="expanded" aria-controls="student-dashboard-profile-details" :aria-label="expanded ? 'Collapse student profile' : 'Expand student profile'" :title="expanded ? 'Collapse student profile' : 'Expand student profile'" @click="toggleExpanded">
      <svg v-if="!expanded" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M8 3H3v5M16 3h5v5M8 21H3v-5M16 21h5v-5" stroke-linecap="round" stroke-linejoin="round" /></svg>
      <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7" stroke-linecap="round" stroke-linejoin="round" /></svg>
    </button>
    <div class="student-profile-layout">
      <div class="student-profile-summary" :class="{ 'has-facts': compactFacts.length }">
        <small class="student-profile-eyebrow">Student profile</small>
        <div class="student-profile-identity">
          <span class="student-profile-avatar">
            <WebsiteUserAvatar :src="studentProfile.websiteAvatarUrl" :initials="initials" :revision="studentProfile.websiteAvatarRevision" :label="`${displayName} profile picture`" />
          </span>
          <div class="student-profile-heading">
            <strong id="student-profile-name">{{ loading ? 'Loading profile…' : displayName }}</strong>
            <b>Student</b>
          </div>
        </div>
        <dl v-if="compactFacts.length" class="student-profile-facts" aria-label="Student identity summary">
          <div v-for="fact in compactFacts" :key="fact.label" :class="{ wide: fact.wide }">
            <dt>{{ fact.label }}</dt>
            <dd>{{ fact.value }}</dd>
          </div>
        </dl>
      </div>
      <div id="student-dashboard-profile-details" class="student-profile-reveal" :aria-hidden="!expanded">
        <div class="student-profile-reveal-inner">
          <p><strong>Verified account information</strong><span>Only account-scoped identity and academic details are shown.</span></p>
          <dl v-if="rows.length" class="student-profile-details">
            <div v-for="row in rows" :key="row.label" :class="{ wide: row.wide }">
              <dt>{{ row.label }}</dt>
              <dd>{{ row.value }}</dd>
            </div>
          </dl>
          <p v-else class="student-profile-no-details">No additional verified profile details are available.</p>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.student-dashboard-profile { --profile-forest: #064e3b; --profile-deep: #042017; --profile-emerald: var(--website-emerald-bright,#10b981); --profile-gold: var(--website-gold,#d9a93e); --profile-text: #fff; --profile-muted: #b8e4d3; position: relative; min-width: 0; flex: 1; overflow: hidden; isolation: isolate; border: 1px solid color-mix(in srgb,var(--profile-gold) 32%,var(--profile-forest)); border-radius: 20px; background: radial-gradient(circle at 92% -18%,color-mix(in srgb,var(--profile-gold) 20%,transparent) 0 23%,transparent 24%),radial-gradient(circle at -8% 110%,color-mix(in srgb,var(--profile-emerald) 20%,transparent) 0 27%,transparent 28%),linear-gradient(138deg,var(--profile-forest),var(--profile-deep)); color: var(--profile-text); box-shadow: 0 18px 40px rgba(4,32,23,.2); }
.student-dashboard-profile::after { content: ''; position: absolute; z-index: -1; inset: 0; pointer-events: none; background: linear-gradient(115deg,rgba(255,255,255,.055),transparent 38% 66%,rgba(16,185,129,.07)); }
.student-profile-expand { position: absolute; top: 12px; right: 12px; z-index: 2; width: 44px; height: 44px; display: grid; place-items: center; padding: 0; border: 1px solid color-mix(in srgb,var(--profile-gold) 50%,transparent); border-radius: 12px; background: rgba(4,32,23,.72); color: var(--profile-text); box-shadow: 0 8px 18px rgba(4,32,23,.24); cursor: pointer; transition: border-color 160ms ease,background-color 160ms ease,color 160ms ease; }
.student-profile-expand:hover { border-color: var(--profile-gold); background: rgba(16,185,129,.3); color: #fff7dd; }
.student-profile-expand:focus-visible { outline: 3px solid color-mix(in srgb,var(--profile-gold) 78%,white); outline-offset: 2px; }
.student-profile-expand svg { width: 19px; height: 19px; }
.student-profile-layout { min-width: 0; height: 100%; display: grid; grid-template-columns: minmax(0,1fr); align-items: stretch; transition: grid-template-columns 380ms cubic-bezier(.22,1,.36,1); }
.expanded .student-profile-layout { grid-template-columns: minmax(260px,.66fr) minmax(0,1.34fr); }
.student-profile-summary { min-width: 0; display: flex; flex-direction: column; justify-content: flex-start; gap: 16px; padding: 21px 22px; }
.student-profile-summary.has-facts { justify-content: space-between; }
.student-profile-eyebrow { padding-right: 48px; color: color-mix(in srgb,var(--profile-gold) 90%,white); font-size: 10px; font-weight: 840; letter-spacing: .14em; text-transform: uppercase; }
.student-profile-identity { min-width: 0; display: grid; grid-template-columns: 86px minmax(0,1fr); align-items: center; gap: 17px; }
.student-profile-avatar { width: 86px; height: 86px; overflow: hidden; border: 3px solid color-mix(in srgb,var(--profile-gold) 80%,transparent); border-radius: 25px; background: rgba(255,255,255,.1); font-size: 18px; box-shadow: 0 12px 26px rgba(4,32,23,.32); }
.student-profile-heading { min-width: 0; display: grid; gap: 8px; }
.student-profile-heading strong { overflow: hidden; color: var(--profile-text); font-size: clamp(18px,2vw,23px); line-height: 1.18; letter-spacing: -.02em; text-overflow: ellipsis; white-space: nowrap; }
.student-profile-heading b { width: fit-content; padding: 5px 10px; border: 1px solid rgba(184,228,211,.24); border-radius: 999px; background: rgba(16,185,129,.2); color: #d8f8eb; font-size: 10px; letter-spacing: .04em; }
.student-profile-facts { min-width: 0; display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 8px; margin: 0; }
.student-profile-facts div { min-width: 0; padding: 9px 11px; border: 1px solid rgba(184,228,211,.13); border-radius: 10px; background: rgba(4,32,23,.34); }
.student-profile-facts div.wide { grid-column: 1 / -1; }
.student-profile-facts dt,.student-profile-details dt { color: var(--profile-muted); font-size: 9px; font-weight: 760; letter-spacing: .075em; text-transform: uppercase; }
.student-profile-facts dd { margin: 3px 0 0; overflow: hidden; color: var(--profile-text); font-size: 11px; font-weight: 720; line-height: 1.35; text-overflow: ellipsis; white-space: nowrap; }
.student-profile-reveal { min-width: 0; display: grid; grid-template-rows: 0fr; opacity: 0; visibility: hidden; transition: grid-template-rows 380ms cubic-bezier(.22,1,.36,1),opacity 180ms ease,visibility 0s linear 380ms; }
.expanded .student-profile-reveal { grid-template-rows: 1fr; opacity: 1; visibility: visible; transition-delay: 0s,110ms,0s; }
.student-profile-reveal-inner { min-width: 0; min-height: 0; display: grid; align-content: center; gap: 13px; overflow: hidden; padding: 22px 24px 22px 0; }
.student-profile-reveal-inner>p { display: grid; gap: 4px; margin: 0; }
.student-profile-reveal-inner>p strong { color: var(--profile-text); font-size: 14px; }
.student-profile-reveal-inner>p span { color: var(--profile-muted); font-size: 11px; }
.student-profile-details { min-width: 0; display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 9px; align-self: start; margin: 0; }
.student-profile-details div { min-width: 0; padding: 12px 14px; border: 1px solid rgba(184,228,211,.13); border-radius: 12px; background: rgba(255,255,255,.075); box-shadow: inset 0 1px 0 rgba(255,255,255,.04); }
.student-profile-details div.wide,.student-profile-details div:last-child:nth-child(odd) { grid-column: 1 / -1; }
.student-profile-details dd { margin: 5px 0 0; overflow-wrap: anywhere; color: var(--profile-text); font-size: 13px; font-weight: 720; line-height: 1.35; }
.student-profile-no-details { padding: 13px 15px; border: 1px solid rgba(184,228,211,.13); border-radius: 12px; background: rgba(4,32,23,.34); color: var(--profile-muted); font-size: 12px; }
:global(html[data-student-theme='dark'].website-ui-2-client) .student-dashboard-profile { --profile-forest: #075b46; --profile-deep: #031b14; border-color: rgba(217,169,62,.38); box-shadow: 0 22px 48px rgba(0,0,0,.34); }
@media (prefers-color-scheme: dark) { :global(html[data-student-theme='system'].website-ui-2-client) .student-dashboard-profile { --profile-forest: #075b46; --profile-deep: #031b14; border-color: rgba(217,169,62,.38); box-shadow: 0 22px 48px rgba(0,0,0,.34); } }
@media (max-width: 780px) { .expanded .student-profile-layout { grid-template-columns: 1fr; } .expanded .student-profile-summary { padding-bottom: 18px; } .student-profile-reveal-inner { padding: 0 20px 24px; } }
@media (max-width: 520px) { .student-profile-summary { gap: 14px; padding: 18px 17px; } .student-profile-identity { grid-template-columns: 68px minmax(0,1fr); gap: 13px; } .student-profile-avatar { width: 68px; height: 68px; border-radius: 20px; } .student-profile-heading strong { font-size: 18px; white-space: normal; } .student-profile-facts { grid-template-columns: 1fr; } .student-profile-facts div.wide { grid-column: auto; } .student-profile-details { grid-template-columns: 1fr; } .student-profile-details div.wide,.student-profile-details div:last-child:nth-child(odd) { grid-column: auto; } }
@media (prefers-reduced-motion: reduce) { .student-profile-layout,.student-profile-reveal,.student-profile-expand { transition: none; } }
</style>
