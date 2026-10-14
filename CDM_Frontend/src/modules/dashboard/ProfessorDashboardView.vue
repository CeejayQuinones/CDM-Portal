<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { getProfessorDashboard } from './dashboardService'
import './guideDashboard.css'

const loading = ref(true)
const error = ref('')
const dashboard = ref({ identity: {}, summary: {}, has_assignments: false })

const cards = computed(() => [
  { label: 'Assigned classes', value: dashboard.value.summary.assigned_classes ?? 0 },
  { label: 'Students', value: dashboard.value.summary.students ?? 0 },
  { label: 'Grade sheets needing work', value: dashboard.value.summary.grade_sheets_needing_work ?? 0 },
  { label: 'Submitted grade sheets', value: dashboard.value.summary.submitted_grade_sheets ?? 0 },
  { label: 'Monitoring alerts', value: dashboard.value.summary.monitoring_alerts ?? 0 },
])

const workflow = [
  { step: '1', title: 'Assigned Classes', text: 'Review the Sections and Subjects assigned to you.', to: { name: 'enrollment-teaching' } },
  { step: '2', title: 'Class Rosters', text: 'View enrolled Students for your assigned Subjects.', to: { name: 'enrollment-teaching' } },
  { step: '3', title: 'Grading', text: 'Create assessments, enter scores, and review Midterm and Finals results.', to: { name: 'grading' } },
  { step: '4', title: 'Submit Grades', text: 'Submit completed grade sheets after grading windows close.', to: { name: 'grading' } },
  { step: '5', title: 'Academic Monitoring', text: 'Review signals only for Students within your teaching scope.', to: { name: 'monitoring' } },
  { step: '6', title: 'Grade Messages', text: 'Use the Grade Messages tab after eligible grades are published.', to: { name: 'grading' } },
]

const links = [
  { label: 'My Classes', text: 'Assigned Sections and Subjects', to: { name: 'enrollment-teaching' } },
  { label: 'Enrollment / Rosters', text: 'Students in your assigned classes', to: { name: 'enrollment-teaching' } },
  { label: 'Grading', text: 'Assessments, scores, and submissions', to: { name: 'grading' } },
  { label: 'Grade Messages', text: 'Open the Grade Messages tab in Grading', to: { name: 'grading' } },
  { label: 'Academic Monitoring', text: 'Scoped academic signals and alerts', to: { name: 'monitoring' } },
]

async function loadDashboard() {
  loading.value = true
  error.value = ''
  try {
    dashboard.value = await getProfessorDashboard()
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
        <p class="guide-kicker">Professor portal</p>
        <h1>{{ loading ? 'Welcome' : `Welcome, ${dashboard.identity.name || 'Professor'}` }}</h1>
        <p>Follow your teaching workflow and open the academic tools already assigned to you.</p>
      </div>
      <dl v-if="!loading" class="identity-list" aria-label="Professor identity">
        <div><dt>Faculty ID</dt><dd>{{ dashboard.identity.faculty_id || 'Not available' }}</dd></div>
        <div><dt>Department</dt><dd>{{ dashboard.identity.department || 'Not available' }}</dd></div>
      </dl>
    </header>

    <div v-if="error" class="guide-error" role="alert">
      <span>{{ error }}</span><button type="button" @click="loadDashboard">Try again</button>
    </div>

    <section aria-labelledby="professor-summary-title">
      <div class="guide-section-heading"><div><p class="guide-kicker">At a glance</p><h2 id="professor-summary-title">Your teaching summary</h2></div></div>
      <div class="guide-summary-grid" :aria-busy="loading">
        <article v-for="card in cards" :key="card.label" class="guide-summary-card">
          <span>{{ card.label }}</span><strong>{{ loading ? '—' : card.value }}</strong>
        </article>
      </div>
      <p v-if="!loading && !dashboard.has_assignments" class="guide-note">No classes have been assigned yet.</p>
    </section>

    <section aria-labelledby="professor-guide-title">
      <div class="guide-section-heading"><div><p class="guide-kicker">Start here</p><h2 id="professor-guide-title">Your Teaching Workflow</h2></div></div>
      <ol class="workflow-grid">
        <li v-for="item in workflow" :key="item.step">
          <RouterLink :to="item.to" class="workflow-card">
            <span class="step-number" aria-hidden="true">{{ item.step }}</span>
            <span><strong>{{ item.title }}</strong><small>{{ item.text }}</small></span>
            <span class="guide-arrow" aria-hidden="true">→</span>
          </RouterLink>
        </li>
        <li>
          <article class="workflow-card workflow-card-static">
            <span class="step-number" aria-hidden="true">7</span>
            <span><strong>Events</strong><small>Use the Mobile app for Event participation or assigned Event responsibilities.</small></span>
          </article>
        </li>
      </ol>
    </section>

    <section aria-labelledby="professor-links-title">
      <div class="guide-section-heading"><div><p class="guide-kicker">Shortcuts</p><h2 id="professor-links-title">Quick links</h2></div></div>
      <nav class="quick-link-grid" aria-label="Professor academic services">
        <RouterLink v-for="link in links" :key="link.label" :to="link.to" class="guide-quick-link">
          <span><strong>{{ link.label }}</strong><small>{{ link.text }}</small></span><span class="guide-arrow" aria-hidden="true">→</span>
        </RouterLink>
        <article class="guide-quick-link guide-quick-link-static">
          <span><strong>Events</strong><small>View announcements on Web. Participation and assigned Event responsibilities are available in the Mobile app.</small></span>
        </article>
      </nav>
    </section>
  </main>
</template>

