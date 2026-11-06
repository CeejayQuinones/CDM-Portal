<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue'
import WebsiteActivityPanel from '../../components/website/WebsiteActivityPanel.vue'
import WebsiteQuickAction from '../../components/website/WebsiteQuickAction.vue'
import WebsiteStatCard from '../../components/website/WebsiteStatCard.vue'
import WebsiteWorkspace from '../../components/website/WebsiteWorkspace.vue'
import StudentDashboardProfile from '../../components/website/StudentDashboardProfile.vue'
import { formatDashboardStatus } from './dashboardPresentation'
import './websiteDashboard.css'

const props = defineProps({ dashboard: { type: Object, required: true }, enrollment: { type: Object, default: null }, notices: { type: Array, default: () => [] }, loading: Boolean, error: { type: String, default: '' } })
defineEmits(['retry'])
const officialNotices = computed(() => props.notices.filter(notice => notice.kind === 'official' && notice.verified).slice(0, 4))
const profileExpanded = ref(false)
const profileCell = ref(null)
const summaryGrid = ref(null)
let layoutAnimations = []
const clock = ref(Date.now())
const period = computed(() => props.enrollment?.period || null)
const applicationStatus = computed(() => props.enrollment?.current_application?.status || (props.dashboard.has_finalized_enrollment ? 'enrolled' : null))
const periodStatus = computed(() => period.value?.state ? formatDashboardStatus(period.value.state) : 'Not configured')
const deadline = computed(() => period.value?.closes_at ? Date.parse(period.value.closes_at) : null)
const remaining = computed(() => {
  if (period.value?.state !== 'open' || !deadline.value || deadline.value <= clock.value) return null
  const minutes = Math.max(0, Math.floor((deadline.value - clock.value) / 60000))
  const days = Math.floor(minutes / 1440)
  const hours = Math.floor((minutes % 1440) / 60)
  return days ? `${days}d ${hours}h remaining` : `${hours}h ${minutes % 60}m remaining`
})
const enrollmentProgress = computed(() => {
  const status = applicationStatus.value
  return [
    { label: 'Submitted', complete: ['submitted','under_review','approved','enrolled'].includes(status), current: status === 'submitted' },
    { label: 'Assessment', detail: 'Registrar review', complete: ['approved','enrolled'].includes(status), current: status === 'under_review' },
    { label: 'Enrolled', complete: status === 'enrolled' || props.dashboard.has_finalized_enrollment, current: status === 'approved' },
  ]
})
const setProfileExpanded = async value => {
  if (profileExpanded.value === value) return
  const elements = [profileCell.value, summaryGrid.value].filter(Boolean)
  const before = elements.map(element => element.getBoundingClientRect())
  layoutAnimations.forEach(animation => animation.cancel())
  layoutAnimations = []
  profileExpanded.value = value
  await nextTick()
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return
  elements.forEach((element, index) => {
    if (typeof element.animate !== 'function') return
    const after = element.getBoundingClientRect()
    const deltaX = before[index].left - after.left
    const deltaY = before[index].top - after.top
    const startClip = value && after.width > before[index].width
      ? `inset(0 ${Math.max(0, after.width - before[index].width)}px 0 0 round 20px)`
      : 'inset(0 0 0 0 round 20px)'
    const animation = element.animate([
      { transform: `translate3d(${deltaX}px,${deltaY}px,0)`, clipPath: startClip },
      { transform: 'translate3d(0,0,0)', clipPath: 'inset(0 0 0 0 round 20px)' },
    ], { duration: 380, easing: 'cubic-bezier(.22,1,.36,1)' })
    layoutAnimations.push(animation)
    animation.onfinish = () => { layoutAnimations = layoutAnimations.filter(item => item !== animation) }
  })
}
onBeforeUnmount(() => { layoutAnimations.forEach(animation => animation.cancel()) })
const quickActions = [
  { title: 'Enrollment', description: 'Check your application and enrollment status.', to: { name: 'enrollment-status' } },
  { title: 'Courses Offered', description: 'Explore active programs and verified curricula.', to: { name: 'enrollment-courses' } },
  { title: 'Enrollment Guide', description: 'Understand each stage and its official requirements.', to: { name: 'enrollment-steps' } },
  { title: 'My subjects', description: 'Review finalized enrolled subjects.', to: { name: 'enrollment-subjects' } },
  { title: 'My schedule', description: 'See assigned class times and rooms.', to: { name: 'enrollment-schedule' } },
  { title: 'Grades', description: 'View officially published grades.', to: { name: 'grading' } },
  { title: 'Document requests', description: 'Request and track official documents.', to: { name: 'student-document-requests' } },
]
</script>

<template>
  <WebsiteWorkspace>
    <section class="student-enrollment-hero" data-buddy-obstacle="true">
      <div class="student-enrollment-hero-copy"><p>Student enrollment</p><h1>{{ loading ? 'Welcome to CDM Portal' : `Welcome back, ${dashboard.identity.name || 'Student'}!` }}</h1><span>Review your authentic enrollment status and continue from the next authorized step.</span><div class="student-enrollment-actions"><RouterLink class="dashboard-primary-action" :to="{ name: 'enrollment-status' }">{{ applicationStatus === 'draft' ? 'Update Enrollment' : 'View Enrollment' }}</RouterLink><RouterLink :to="{ name: 'enrollment-steps' }">Enrollment Guide</RouterLink></div></div>
      <div class="student-enrollment-window"><small>Enrollment period</small><strong>{{ periodStatus }}</strong><span v-if="period">{{ period.academic_year }} · {{ period.semester }}</span><em v-if="remaining">{{ remaining }}</em><span v-else-if="period?.closes_at">Closes {{ new Date(period.closes_at).toLocaleString() }}</span></div>
      <ol class="student-enrollment-progress" aria-label="Enrollment progress">
        <li v-for="(item,index) in enrollmentProgress" :key="item.label" :class="{ complete:item.complete, current:item.current }" :aria-current="item.current?'step':undefined"><span>{{ item.complete ? '✓' : index + 1 }}</span><div><strong>{{ item.label }}</strong><small v-if="item.detail">{{ item.detail }}</small></div></li>
      </ol>
    </section>
    <div v-if="error" class="dashboard-error" role="alert"><span>{{ error }}</span><button type="button" @click="$emit('retry')">Try again</button></div>
    <div class="dashboard-command-grid student-dashboard-main-grid" :class="{ 'profile-expanded': profileExpanded }">
    <div ref="profileCell" class="dashboard-profile-cell">
      <StudentDashboardProfile :identity="dashboard.identity" :loading="loading" :expanded="profileExpanded" @update:expanded="setProfileExpanded" />
    </div>
    <section ref="summaryGrid" class="dashboard-stat-grid student-dashboard-stat-grid" aria-label="Student academic summary">
      <WebsiteStatCard label="Enrollment status" :value="formatDashboardStatus(dashboard.summary.enrollment_status)" note="Current account record" :loading="loading" tone="gold" />
      <WebsiteStatCard label="Academic term" :value="dashboard.summary.academic_term || 'No current term'" note="Configured active term" :loading="loading" />
      <WebsiteStatCard label="Enrolled subjects" :value="dashboard.summary.enrolled_subjects ?? 0" note="Finalized enrollment only" :loading="loading" />
    </section>
    </div>

    <p v-if="!loading && !dashboard.has_finalized_enrollment" class="dashboard-empty-note">No finalized enrollment is available yet. Open Enrollment to review your application status.</p>

    <div class="dashboard-section-heading"><div><p>Move faster</p><h2>Academic quick actions</h2></div><span>Open the workspace you need without leaving the portal.</span></div>
    <nav class="dashboard-action-grid" aria-label="Student academic services"><WebsiteQuickAction v-for="action in quickActions" :key="action.title" v-bind="action" /></nav>

    <div class="dashboard-secondary-grid">
      <WebsiteActivityPanel title="Official announcements" eyebrow="Verified updates" empty="There are no verified official announcements for this account right now.">
        <ul v-if="officialNotices.length" class="dashboard-notice-list">
          <li v-for="notice in officialNotices" :key="notice.id"><span>{{ notice.source_label }}</span><strong>{{ notice.title }}</strong><p>{{ notice.message }}</p></li>
        </ul>
        <p v-else class="dashboard-panel-empty">There are no verified official announcements for this account right now.</p>
      </WebsiteActivityPanel>
      <WebsiteActivityPanel title="Academic activity" eyebrow="Your record" empty="Recent academic activity is not included in the current dashboard data. Use the quick actions to review each authorized record." />
    </div>
  </WebsiteWorkspace>
</template>
