<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { getStudentDashboard } from './dashboardService'
import { formatDashboardStatus } from './dashboardPresentation'
import './guideDashboard.css'

const loading = ref(true)
const error = ref('')
const dashboard = ref({ identity: {}, summary: {}, has_finalized_enrollment: false })
const cards = computed(() => [
  { label: 'Enrollment', value: formatDashboardStatus(dashboard.value.summary.enrollment_status) },
  { label: 'Academic term', value: dashboard.value.summary.academic_term || 'No current term' },
  { label: 'Enrolled subjects', value: dashboard.value.summary.enrolled_subjects ?? 0 },
  { label: 'Unread notices', value: dashboard.value.summary.unread_monitoring_notices ?? 0 },
])

const guide = [
  { step: '1', title: 'Enrollment', text: 'Check your enrollment application and finalized enrollment.', to: { name: 'enrollment-status' } },
  { step: '2', title: 'My Subjects', text: 'Review the Subjects included in your finalized enrollment.', to: { name: 'enrollment-subjects' } },
  { step: '3', title: 'My Schedule', text: 'Check when and where your enrolled classes meet.', to: { name: 'enrollment-schedule' } },
  { step: '4', title: 'Grading', text: 'View grades after they are officially published.', to: { name: 'grading' } },
  { step: '5', title: 'Academic Monitoring', text: 'Review academic signals, Study Plan, notices, and Academic Coach.', to: { name: 'monitoring' } },
  { step: '6', title: 'Document Requests', text: 'Request official documents and track their progress.', to: { name: 'student-document-requests' } },
]

async function loadDashboard() {
  loading.value = true
  error.value = ''
  try {
    dashboard.value = await getStudentDashboard()
  } catch (reason) {
    error.value = reason.response?.data?.message || 'Your dashboard could not be loaded. Please try again.'
  } finally {
    loading.value = false
  }
}

onMounted(loadDashboard)
</script>

<template>
  <main class="guide-dashboard">
    <header class="guide-hero">
      <div>
        <p class="guide-kicker">Student portal</p>
        <h1>{{ loading ? 'Welcome' : `Welcome, ${dashboard.identity.name || 'Student'}` }}</h1>
        <p>Use this page as your guide to the academic services available in CDM Portal.</p>
      </div>
      <dl v-if="!loading" class="identity-list" aria-label="Student identity">
        <div><dt>Student no.</dt><dd>{{ dashboard.identity.student_number || 'Not available' }}</dd></div>
        <div><dt>Program</dt><dd>{{ dashboard.identity.program_code || dashboard.identity.program || 'Not available' }}</dd></div>
        <div><dt>Year & section</dt><dd>{{ dashboard.identity.year_level ? `Year ${dashboard.identity.year_level}` : 'Year not set' }}<span v-if="dashboard.identity.section"> · {{ dashboard.identity.section }}</span></dd></div>
      </dl>
    </header>

    <div v-if="error" class="guide-error" role="alert">
      <span>{{ error }}</span><button type="button" @click="loadDashboard">Try again</button>
    </div>

    <section aria-labelledby="student-summary-title">
      <div class="guide-section-heading"><div><p class="guide-kicker">At a glance</p><h2 id="student-summary-title">Your current portal summary</h2></div></div>
      <div class="guide-summary-grid" :aria-busy="loading">
        <article v-for="card in cards" :key="card.label" class="guide-summary-card">
          <span>{{ card.label }}</span><strong>{{ loading ? '—' : card.value }}</strong>
        </article>
      </div>
      <p v-if="!loading && !dashboard.has_finalized_enrollment" class="guide-note">No finalized enrollment is available yet. Open Enrollment to review your application status.</p>
    </section>

    <section aria-labelledby="student-guide-title">
      <div class="guide-section-heading"><div><p class="guide-kicker">Start here</p><h2 id="student-guide-title">Your CDM Portal Guide</h2></div></div>
      <ol class="workflow-grid">
        <li v-for="item in guide" :key="item.step">
          <RouterLink :to="item.to" class="workflow-card">
            <span class="step-number" aria-hidden="true">{{ item.step }}</span>
            <span><strong>{{ item.title }}</strong><small>{{ item.text }}</small></span>
            <span class="guide-arrow" aria-hidden="true">→</span>
          </RouterLink>
        </li>
      </ol>
    </section>

  </main>
</template>
