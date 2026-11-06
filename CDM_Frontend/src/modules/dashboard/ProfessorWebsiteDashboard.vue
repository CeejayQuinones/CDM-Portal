<script setup>
import WebsiteActivityPanel from '../../components/website/WebsiteActivityPanel.vue'
import WebsitePageHeading from '../../components/website/WebsitePageHeading.vue'
import WebsiteQuickAction from '../../components/website/WebsiteQuickAction.vue'
import WebsiteStatCard from '../../components/website/WebsiteStatCard.vue'
import WebsiteWorkspace from '../../components/website/WebsiteWorkspace.vue'
import './websiteDashboard.css'

defineProps({ dashboard: { type: Object, required: true }, loading: Boolean, error: { type: String, default: '' } })
defineEmits(['retry'])
const actions = [
  { title: 'Assigned classes', description: 'Review assigned sections, subjects and rosters.', to: { name: 'enrollment-teaching' } },
  { title: 'Grading and submission', description: 'Enter scores and review sheets before eligible submission.', to: { name: 'grading' } },
  { title: 'Professor settings', description: 'Review account details and appearance.', to: { name: 'professor-settings' } },
]
</script>

<template>
  <WebsiteWorkspace labelledby="professor-workspace-title">
    <WebsitePageHeading eyebrow="Professor workspace" :title="loading ? 'Teaching workspace' : `Good day, ${dashboard.identity.name || 'Professor'}`" description="Manage assigned classes, grade work, and scoped academic follow-up from one workspace.">
      <template #actions><RouterLink class="dashboard-primary-action" :to="{ name: 'grading' }">Open grading</RouterLink></template>
    </WebsitePageHeading>
    <div v-if="error" class="dashboard-error" role="alert"><span>{{ error }}</span><button type="button" @click="$emit('retry')">Try again</button></div>

    <div class="dashboard-command-grid">
    <section class="dashboard-overview professor-overview" aria-labelledby="professor-workspace-title">
      <div class="dashboard-overview-copy"><p>Teaching overview</p><h2 id="professor-workspace-title">Your active academic workload</h2><span>Counts come from your authorized teaching assignments and grade sheets.</span></div>
      <dl class="student-identity-strip professor-identity-strip">
        <div><dt>Faculty ID</dt><dd>{{ loading ? '—' : dashboard.identity.faculty_id || 'Not available' }}</dd></div>
        <div><dt>Department</dt><dd>{{ loading ? '—' : dashboard.identity.department || 'Not available' }}</dd></div>
      </dl>
    </section>

    <section class="dashboard-stat-grid professor-stat-grid" aria-label="Professor workload summary">
      <WebsiteStatCard label="Assigned classes" :value="dashboard.summary.assigned_classes ?? 0" note="Current teaching scope" :loading="loading" tone="gold" />
      <WebsiteStatCard label="Students" :value="dashboard.summary.students ?? 0" note="Across assigned classes" :loading="loading" />
      <WebsiteStatCard label="Grade sheets needing work" :value="dashboard.summary.grade_sheets_needing_work ?? 0" note="Pending academic task" :loading="loading" />
      <WebsiteStatCard label="Submitted grade sheets" :value="dashboard.summary.submitted_grade_sheets ?? 0" note="Recorded submissions" :loading="loading" />
    </section>
    </div>
    <p v-if="!loading && !dashboard.has_assignments" class="dashboard-empty-note">No classes have been assigned yet. The teaching workspace will update when authorized assignments are available.</p>

    <div class="dashboard-section-heading"><div><p>Frequent tasks</p><h2>Teaching quick actions</h2></div><span>Navigate directly to the existing academic workflows.</span></div>
    <nav class="dashboard-action-grid professor-actions" aria-label="Professor academic tasks"><WebsiteQuickAction v-for="action in actions" :key="action.title" v-bind="action" /></nav>

    <div class="dashboard-secondary-grid">
      <WebsiteActivityPanel title="Grading priorities" eyebrow="Task queue" :empty="loading ? 'Loading grading workload…' : dashboard.summary.grade_sheets_needing_work ? `${dashboard.summary.grade_sheets_needing_work} grade sheet(s) currently need work. Open Grading to review authorized details.` : 'No grade sheets currently need work.'" />
      <WebsiteActivityPanel title="Schedule" eyebrow="Assigned teaching" empty="The dashboard summary does not include meeting times. Open Assigned classes for the verified schedule." />
    </div>
  </WebsiteWorkspace>
</template>

<style scoped>
.professor-overview { background: linear-gradient(125deg,var(--website-forest),#083d2d); }
.professor-identity-strip { grid-template-columns: repeat(2,minmax(0,1fr)); }
.professor-stat-grid { grid-template-columns: repeat(5,minmax(0,1fr)); }
.professor-actions { grid-template-columns: repeat(auto-fit,minmax(min(220px,100%),1fr)); }
@media (max-width: 1200px) { .professor-stat-grid { grid-template-columns: repeat(3,minmax(0,1fr)); } }
@media (max-width: 760px) { .professor-actions { grid-template-columns: 1fr; } }
@media (max-width: 560px) { .professor-stat-grid { grid-template-columns: repeat(2,minmax(0,1fr)); } }
@media (max-width: 390px) { .professor-stat-grid { grid-template-columns: 1fr; } }
</style>
