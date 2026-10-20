<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { getAdminDashboard } from './dashboardService'
import './adminDashboard.css'

const loading = ref(true)
const error = ref('')
const dashboard = ref({
  summary: {},
  admissions: { by_status: {}, total: 0 },
  enrollment: { current_term: null, by_status: {}, activity: {} },
  events: { items: [], pending_moderator_requests: 0 },
  administrative: { pending_reviews: [], recent_activity: [] },
})

const summaryCards = computed(() => [
  ['active_students', 'Active Students'],
  ['active_professors', 'Active Professors'],
  ['registrar_staff_accounts', 'Registrar Staff'],
  ['guest_accounts', 'Guest Accounts'],
  ['pending_admission_applications', 'Pending Admissions'],
  ['current_term_enrollments', 'Current Enrollments'],
  ['pending_administrative_actions', 'Pending Actions'],
  ['published_or_ongoing_events', 'Active Events'],
].map(([key, label]) => ({ key, label, value: dashboard.value.summary[key] ?? 0 })))

const quickLinks = [
  { label: 'Account Management', detail: 'Registrar account provisioning', to: { name: 'registrar-management' } },
  { label: 'Admissions', detail: 'Applicants, decisions, and results', to: { name: 'registrar-admissions' } },
  { label: 'Enrollment', detail: 'Applications and academic terms', to: { name: 'enrollment-applications' } },
  { label: 'Academic Monitoring', detail: 'Risk signals and interventions', to: { name: 'monitoring' } },
  { label: 'Student Management', detail: 'Academic identities and records', to: { name: 'student-management' } },
  { label: 'Events', detail: 'Operations, attendance, and reports', to: { name: 'event-attendance' } },
]

const statusLabel = (status) => String(status || '').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
const statusEntries = (counts) => Object.entries(counts || {}).map(([status, count]) => ({ status, count }))
const admissionStatuses = computed(() => statusEntries(dashboard.value.admissions.by_status))
const enrollmentStatuses = computed(() => statusEntries(dashboard.value.enrollment.by_status))
const admissionMaximum = computed(() => Math.max(1, ...admissionStatuses.value.map(({ count }) => count)))
const enrollmentMaximum = computed(() => Math.max(1, ...enrollmentStatuses.value.map(({ count }) => count)))
const barWidth = (count, maximum) => `${Math.max(count ? 8 : 0, Math.round((count / maximum) * 100))}%`

function formatDateTime(value) {
  if (!value) return 'Not available'
  return new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short',
  }).format(new Date(value))
}

function eventSession(event) {
  if (!event.attendance_session) return 'No attendance session'
  return event.attendance_session.status === 'open' ? 'Attendance open' : 'Attendance closed'
}

async function loadDashboard() {
  loading.value = true
  error.value = ''
  try {
    dashboard.value = await getAdminDashboard()
  } catch (reason) {
    error.value = reason.response?.data?.message || 'The Admin Dashboard could not be loaded. Please try again.'
  } finally {
    loading.value = false
  }
}

onMounted(loadDashboard)
</script>

<template>
  <main class="admin-dashboard">
    <header class="admin-dashboard__header">
      <div>
        <p class="admin-dashboard__kicker">System administration</p>
        <h1>Admin Dashboard</h1>
        <p>A live overview of accounts, academic workflows, Events, and work awaiting review.</p>
      </div>
      <button v-if="error" type="button" :disabled="loading" @click="loadDashboard">Try again</button>
    </header>

    <p v-if="error" class="admin-dashboard__error" role="alert">{{ error }}</p>
    <p v-if="loading" class="admin-dashboard__loading" role="status">Loading current administrative data…</p>

    <section aria-labelledby="admin-overview-title">
      <div class="admin-dashboard__heading">
        <div><p class="admin-dashboard__kicker">At a glance</p><h2 id="admin-overview-title">System overview</h2></div>
        <small v-if="!loading">Updated {{ formatDateTime(dashboard.generated_at) }}</small>
      </div>
      <div class="admin-summary" :aria-busy="loading">
        <article v-for="card in summaryCards" :key="card.key" class="admin-summary__card">
          <strong>{{ loading ? '—' : Number(card.value).toLocaleString() }}</strong>
          <span>{{ card.label }}</span>
        </article>
      </div>
    </section>

    <div class="admin-dashboard__split">
      <section class="admin-panel" aria-labelledby="admission-summary-title">
        <div class="admin-dashboard__heading">
          <div><p class="admin-dashboard__kicker">Admissions</p><h2 id="admission-summary-title">Application status</h2></div>
          <RouterLink :to="{ name: 'registrar-admissions' }">Open Applicants</RouterLink>
        </div>
        <p v-if="!loading && admissionStatuses.length === 0" class="admin-empty">No Admission applications are recorded.</p>
        <div v-else class="status-chart" role="img" aria-label="Admission application counts by status">
          <div v-for="item in admissionStatuses" :key="item.status" class="status-chart__row">
            <span>{{ statusLabel(item.status) }}</span>
            <div><i :style="{ width: barWidth(item.count, admissionMaximum) }"></i></div>
            <strong>{{ item.count }}</strong>
          </div>
        </div>
      </section>

      <section class="admin-panel" aria-labelledby="enrollment-summary-title">
        <div class="admin-dashboard__heading">
          <div><p class="admin-dashboard__kicker">Enrollment</p><h2 id="enrollment-summary-title">Current term</h2></div>
          <RouterLink :to="{ name: 'enrollment-applications' }">Open Applications</RouterLink>
        </div>
        <p v-if="dashboard.enrollment.current_term" class="admin-term">
          <strong>{{ dashboard.enrollment.current_term.academic_year }} · {{ dashboard.enrollment.current_term.semester }}</strong>
          <span>{{ statusLabel(dashboard.enrollment.current_term.state) }}</span>
        </p>
        <p v-else-if="!loading" class="admin-empty">No current Enrollment period is configured.</p>
        <dl v-if="dashboard.enrollment.current_term" class="admin-mini-metrics" aria-label="Current-term Enrollment activity">
          <div><dt>Applications</dt><dd>{{ dashboard.enrollment.activity.applications || 0 }}</dd></div>
          <div><dt>Awaiting review</dt><dd>{{ dashboard.enrollment.activity.awaiting_review || 0 }}</dd></div>
          <div><dt>Approved</dt><dd>{{ dashboard.enrollment.activity.approved || 0 }}</dd></div>
          <div><dt>Academic enrollments</dt><dd>{{ dashboard.enrollment.activity.academic_enrollments || 0 }}</dd></div>
        </dl>
        <div v-if="enrollmentStatuses.length" class="status-chart" role="img" aria-label="Current-term Enrollment application counts by status">
          <div v-for="item in enrollmentStatuses" :key="item.status" class="status-chart__row">
            <span>{{ statusLabel(item.status) }}</span>
            <div><i :style="{ width: barWidth(item.count, enrollmentMaximum) }"></i></div>
            <strong>{{ item.count }}</strong>
          </div>
        </div>
      </section>
    </div>

    <div class="admin-dashboard__split">
      <section class="admin-panel" aria-labelledby="event-operations-title">
        <div class="admin-dashboard__heading">
          <div><p class="admin-dashboard__kicker">Event operations</p><h2 id="event-operations-title">Upcoming and ongoing</h2></div>
          <RouterLink :to="{ name: 'event-attendance' }">Open Events</RouterLink>
        </div>
        <p class="admin-panel__note">{{ dashboard.events.pending_moderator_requests || 0 }} Moderator request(s) awaiting review</p>
        <p v-if="!loading && !dashboard.events.items.length" class="admin-empty">No upcoming or ongoing published Events.</p>
        <ul v-else class="admin-list">
          <li v-for="event in dashboard.events.items" :key="event.id">
            <div><strong>{{ event.title }}</strong><span>{{ formatDateTime(event.starts_at) }} · {{ event.venue }}</span></div>
            <span class="admin-badge" :class="{ 'is-open': event.attendance_session?.status === 'open' }">{{ eventSession(event) }}</span>
          </li>
        </ul>
      </section>

      <section class="admin-panel" aria-labelledby="pending-reviews-title">
        <div class="admin-dashboard__heading"><div><p class="admin-dashboard__kicker">Administrative activity</p><h2 id="pending-reviews-title">Pending reviews</h2></div></div>
        <ul class="admin-list admin-list--reviews">
          <li v-for="review in dashboard.administrative.pending_reviews" :key="review.type">
            <RouterLink :to="{ name: review.route_name }"><span>{{ review.label }}</span><strong>{{ review.count }}</strong></RouterLink>
          </li>
        </ul>
        <p v-if="!loading && !dashboard.administrative.pending_reviews.length" class="admin-empty">No pending review categories are available.</p>
      </section>
    </div>

    <section class="admin-panel" aria-labelledby="recent-activity-title">
      <div class="admin-dashboard__heading"><div><p class="admin-dashboard__kicker">Audit trail</p><h2 id="recent-activity-title">Recent meaningful activity</h2></div></div>
      <p v-if="!loading && !dashboard.administrative.recent_activity.length" class="admin-empty">No recent administrative activity is recorded.</p>
      <ul v-else class="activity-list">
        <li v-for="activity in dashboard.administrative.recent_activity" :key="activity.id">
          <span class="activity-list__module">{{ statusLabel(activity.module) }}</span>
          <strong>{{ statusLabel(activity.action) }}</strong>
          <time :datetime="activity.occurred_at">{{ formatDateTime(activity.occurred_at) }}</time>
        </li>
      </ul>
    </section>

    <section aria-labelledby="admin-navigation-title">
      <div class="admin-dashboard__heading"><div><p class="admin-dashboard__kicker">Workspace</p><h2 id="admin-navigation-title">Quick navigation</h2></div></div>
      <nav class="admin-links" aria-label="Admin management modules">
        <RouterLink v-for="link in quickLinks" :key="link.label" :to="link.to">
          <span><strong>{{ link.label }}</strong><small>{{ link.detail }}</small></span><i aria-hidden="true">→</i>
        </RouterLink>
      </nav>
    </section>
  </main>
</template>
