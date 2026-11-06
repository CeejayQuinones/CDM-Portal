<script setup>
import { computed } from 'vue'
import WebsiteActivityPanel from '../../components/website/WebsiteActivityPanel.vue'
import WebsiteQuickAction from '../../components/website/WebsiteQuickAction.vue'
import WebsiteStatCard from '../../components/website/WebsiteStatCard.vue'
import WebsiteWorkspace from '../../components/website/WebsiteWorkspace.vue'
import CdmButton from '../../design-system/CdmButton.vue'
import CdmCard from '../../design-system/CdmCard.vue'
import CdmPageHeader from '../../design-system/CdmPageHeader.vue'
import { guestDashboardActionAvailability } from './dashboardPresentation'
import './websiteDashboard.css'

const props = defineProps({ displayName: { type: String, default: 'Applicant' }, accountName: { type: String, default: '' }, admitted: Boolean, identity: { type: Object, default: null }, workflow: { type: Object, default: () => ({}) }, notices: { type: Array, default: () => [] }, loading: Boolean, error: { type: String, default: '' } })
defineEmits(['retry'])
const application = computed(() => props.identity?.application || null)
const officialNotices = computed(() => props.notices.filter(notice => notice.kind === 'official' && notice.verified).slice(0, 4))
const applicationStatus = computed(() => props.admitted ? 'Admitted' : application.value?.status ? application.value.status.replaceAll('_', ' ') : 'Not started')
const examStatus = computed(() => {
  if (props.workflow.result?.published) return 'Result published'
  if (props.workflow.exam?.session?.exam_completed || props.workflow.exam?.latest_completed) return 'Submitted for review'
  if (props.workflow.exam?.session) return 'In progress'
  if (props.workflow.exam?.eligible) return 'Eligible to begin'
  return 'No available action'
})
const availableActions = computed(() => guestDashboardActionAvailability(props.workflow, props.admitted))
const nextAction = computed(() => {
  if (props.admitted) return { title: 'Continue to Enrollment', description: 'Your account has verified Admission eligibility.', to: { name: 'enrollment-status' } }
  if (props.workflow.exam?.session && !props.workflow.exam.session.exam_completed) return { title: 'Resume entrance examination', description: 'Continue the examination already linked to this account.', to: { name: 'student-admission-exam' } }
  if (props.workflow.result?.published) return { title: 'Review published result', description: 'Open your official Admission result.', to: { name: 'student-admission-result' } }
  if (availableActions.value.exam) return { title: 'Review entrance examination', description: 'Read the instructions before beginning.', to: { name: 'student-admission-exam' } }
  return { title: application.value ? 'Review Admission status' : 'Start with Admission', description: application.value ? 'Check the current application and verified next step.' : 'Review eligibility and the available application period.', to: { name: 'admission' } }
})
</script>

<template>
  <WebsiteWorkspace labelledby="guest-workspace-title">
    <CdmPageHeader eyebrow="Applicant workspace" :title="`Welcome, ${displayName}`" description="Follow the Admission journey using only status and actions authorized for this account.">
      <template #actions><CdmButton :to="nextAction.to">{{ nextAction.title }}</CdmButton></template>
    </CdmPageHeader>
    <div v-if="error" class="dashboard-error" role="alert"><span>{{ error }}</span><button type="button" @click="$emit('retry')">Try again</button></div>

    <div class="dashboard-command-grid">
    <CdmCard class="guest-journey-card" aria-labelledby="guest-workspace-title"><template #header><div class="guest-journey-heading"><div><p>Admission journey</p><h2 id="guest-workspace-title">Your verified next step</h2></div><span>Account-scoped guidance</span></div></template><p class="guest-journey-description">{{ nextAction.description }}</p><CdmButton :to="nextAction.to" size="lg">{{ nextAction.title }} <span aria-hidden="true">→</span></CdmButton></CdmCard>

    <section class="dashboard-stat-grid guest-stat-grid" aria-label="Admission progress summary">
      <WebsiteStatCard label="Application status" :value="applicationStatus" note="Current Admission record" :loading="loading" tone="gold" />
      <WebsiteStatCard label="Entrance examination" :value="examStatus" note="Authorized information only" :loading="loading" />
      <WebsiteStatCard label="Application number" :value="application?.applicant_number || 'Not available'" note="Shown after application creation" :loading="loading" />
      <WebsiteStatCard label="Account" :value="accountName || 'Signed in'" note="Guest portal account" :loading="loading" />
    </section>
    </div>

    <div class="dashboard-section-heading"><div><p>Admission services</p><h2>Continue your application</h2></div><span>Only steps available to your account appear here.</span></div>
    <nav class="dashboard-action-grid guest-actions" aria-label="Guest Admission services">
      <WebsiteQuickAction :to="{ name: 'admission' }" title="Admission status" description="Review application progress and requirements." />
      <WebsiteQuickAction v-if="availableActions.exam" :to="{ name: 'student-admission-exam' }" title="Entrance examination" description="Continue your available examination step." />
      <WebsiteQuickAction v-if="availableActions.result" :to="{ name: 'student-admission-result' }" title="Application result" description="View your officially published result." />
    </nav>

    <div class="dashboard-secondary-grid">
      <WebsiteActivityPanel title="Official announcements" eyebrow="Verified updates" empty="There are no verified official Admission announcements for this account right now.">
        <ul v-if="officialNotices.length" class="dashboard-notice-list"><li v-for="notice in officialNotices" :key="notice.id"><span>{{ notice.source_label }}</span><strong>{{ notice.title }}</strong><p>{{ notice.message }}</p></li></ul>
        <p v-else class="dashboard-panel-empty">There are no verified official Admission announcements for this account right now.</p>
      </WebsiteActivityPanel>
      <WebsiteActivityPanel title="Document requirements" eyebrow="Application record" empty="Open Admission status to review the document requirements attached to your current application. Requirements are not duplicated here without verified data." />
    </div>
  </WebsiteWorkspace>
</template>

<style scoped>
.guest-journey-card { min-height: 100%; }
.guest-journey-heading { display:flex;align-items:flex-start;justify-content:space-between;gap:14px; }.guest-journey-heading p{margin:0 0 4px;color:var(--cdm-primary);font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.guest-journey-heading h2{margin:0;font-size:18px}.guest-journey-heading>span{color:var(--cdm-text-muted);font-size:11px}.guest-journey-description{margin:0 0 18px;color:var(--cdm-text-secondary);font-size:13px;line-height:1.55}
.guest-stat-grid { grid-template-columns: repeat(4,minmax(0,1fr)); }
.guest-actions { grid-template-columns: repeat(auto-fit,minmax(min(240px,100%),1fr)); }
@media (max-width: 1100px) { .guest-stat-grid { grid-template-columns: repeat(2,minmax(0,1fr)); } }
@media (max-width: 480px) { .guest-stat-grid { grid-template-columns: 1fr; } }
</style>
