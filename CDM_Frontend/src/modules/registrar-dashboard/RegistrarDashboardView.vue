<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { formatExactDateTime, formatRelativeTime } from '../document-request/documentRequestPresentation'
import { getRegistrarDashboard } from './registrarDashboardService'

const loading = ref(true)
const error = ref('')
const dashboard = ref({
  summary: {},
  recent_activity: [],
  todays_appointments: [],
})

const summaryCards = computed(() => [
  {
    key: 'total_students',
    label: 'Total Students',
    value: dashboard.value.summary.total_students ?? 0,
    tone: 'green',
    icon: 'M5 20v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2M12 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
  },
  {
    key: 'pending_document_requests',
    label: 'Pending Document Requests',
    value: dashboard.value.summary.pending_document_requests ?? 0,
    tone: 'yellow',
    icon: 'M7 3h7l4 4v14H7zM14 3v5h5M10 12h5M10 16h5',
  },
  {
    key: 'todays_appointments',
    label: "Today's Appointments",
    value: dashboard.value.summary.todays_appointments ?? 0,
    tone: 'teal',
    icon: 'M5 5h14v16H5zM8 3v4M16 3v4M8 11h8M8 15h5',
  },
  {
    key: 'students_without_record_location',
    label: 'No Record Location',
    value: dashboard.value.summary.students_without_record_location ?? 0,
    tone: 'amber',
    icon: 'M12 21s7-5.1 7-12A7 7 0 0 0 5 9c0 6.9 7 12 7 12zM12 11.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
  },
])

const quickActions = [
  {
    label: 'Student Records',
    description: 'Find and manage student profiles',
    to: { name: 'student-management' },
    icon: 'M4 20h16M6 20V7l6-4 6 4v13M9 11h.01M15 11h.01M10 20v-5h4v5',
  },
  {
    label: 'Physical Records',
    description: 'View cabinets and record locations',
    to: { name: 'physical-records' },
    icon: 'M4 5h16v15H4zM4 10h16M9 7.5h6M9 14h6',
  },
  {
    label: 'Document Requests',
    description: 'Review the active request queue',
    to: { name: 'registrar-document-requests' },
    icon: 'M7 3h7l4 4v14H7zM14 3v5h5M10 12h5M10 16h5',
  },
  {
    label: 'Appointments',
    description: 'Manage scheduled appointments',
    to: { name: 'registrar-document-appointments' },
    icon: 'M5 5h14v16H5zM8 3v4M16 3v4M8 11h8M8 15h5',
  },
]

const alerts = computed(() => [
  {
    count: dashboard.value.summary.students_with_missing_documents ?? 0,
    text: 'students have missing documents',
    to: { name: 'student-management' },
    tone: 'warning',
  },
  {
    count: dashboard.value.summary.students_without_record_location ?? 0,
    text: 'students have no physical record location',
    to: { name: 'physical-records' },
    tone: 'danger',
  },
])

function activityDestination(activity) {
  if (activity.type === 'appointment') return { name: 'registrar-document-appointments' }
  if (activity.type === 'record_location') return { name: 'physical-records' }

  return {
    name: 'registrar-document-requests',
    query: activity.request_id ? { request_id: activity.request_id } : undefined,
  }
}

function formatAppointmentTime(value) {
  if (!value) return 'Time not set'

  const date = new Date(`2000-01-01T${String(value).slice(0, 5)}:00`)
  return new Intl.DateTimeFormat('en-PH', { hour: 'numeric', minute: '2-digit' }).format(date)
}

function readableStatus(status) {
  return String(status || 'pending').replaceAll('_', ' ')
}

async function loadDashboard() {
  loading.value = true
  error.value = ''

  try {
    dashboard.value = await getRegistrarDashboard()
  } catch (err) {
    error.value = err.response?.data?.message || 'The dashboard could not be loaded. Please try again.'
  } finally {
    loading.value = false
  }
}

onMounted(loadDashboard)
</script>

<template>
  <section class="dashboard-page">
    <header class="dashboard-header">
      <div>
        <p class="page-kicker">Registrar Staff</p>
        <h1 class="page-title">Registrar Dashboard</h1>
        <p class="page-description">A live overview of student records, document requests, and appointments.</p>
      </div>
      <button v-if="error" type="button" class="retry-button" :disabled="loading" @click="loadDashboard">
        Try again
      </button>
    </header>

    <p v-if="error" class="dashboard-error" role="alert">{{ error }}</p>

    <section class="summary-grid" aria-label="Registrar summary">
      <article v-for="card in summaryCards" :key="card.key" class="summary-card" :class="`tone-${card.tone}`">
        <span class="card-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path :d="card.icon" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </span>
        <div>
          <strong>{{ loading ? '—' : card.value.toLocaleString() }}</strong>
          <span>{{ card.label }}</span>
        </div>
      </article>
    </section>

    <div class="middle-grid">
      <section class="dashboard-panel quick-actions-panel">
        <div class="panel-heading">
          <div>
            <p class="section-kicker">Shortcuts</p>
            <h2>Quick Actions</h2>
          </div>
        </div>
        <div class="quick-actions">
          <RouterLink v-for="action in quickActions" :key="action.label" :to="action.to" class="quick-action">
            <span class="action-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path :d="action.icon" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
            </span>
            <span>
              <strong>{{ action.label }}</strong>
              <small>{{ action.description }}</small>
            </span>
            <span class="arrow" aria-hidden="true">→</span>
          </RouterLink>
        </div>
      </section>

      <section class="dashboard-panel alerts-panel">
        <div class="panel-heading">
          <div>
            <p class="section-kicker">Needs attention</p>
            <h2>Record Alerts</h2>
          </div>
        </div>
        <div class="alerts-list">
          <RouterLink v-for="alert in alerts" :key="alert.text" :to="alert.to" class="record-alert">
            <span class="alert-count" :class="alert.tone">{{ loading ? '—' : alert.count }}</span>
            <span>
              <strong>{{ alert.text }}</strong>
              <small>Open records</small>
            </span>
            <span class="arrow" aria-hidden="true">→</span>
          </RouterLink>
        </div>
      </section>
    </div>

    <div class="bottom-grid">
      <section class="dashboard-panel activity-panel">
        <div class="panel-heading">
          <div>
            <p class="section-kicker">Latest updates</p>
            <h2>Recent Activity</h2>
          </div>
        </div>
        <p v-if="loading" class="panel-state">Loading recent activity…</p>
        <p v-else-if="!dashboard.recent_activity.length" class="panel-state">No recent activity yet.</p>
        <div v-else class="activity-list">
          <div class="table-heading activity-table-heading" aria-hidden="true">
            <span>Activity</span>
            <span>Updated</span>
          </div>
          <RouterLink
            v-for="activity in dashboard.recent_activity"
            :key="activity.id"
            :to="activityDestination(activity)"
            class="activity-item"
          >
            <span class="activity-dot" :class="activity.type" aria-hidden="true"></span>
            <span class="activity-copy">
              <strong>{{ activity.student_name }}</strong>
              <span>{{ activity.action }}</span>
              <small v-if="activity.detail">{{ activity.detail }}</small>
            </span>
            <time :datetime="activity.occurred_at" :title="formatExactDateTime(activity.occurred_at)">
              {{ formatRelativeTime(activity.occurred_at) }}
            </time>
          </RouterLink>
        </div>
      </section>

      <section class="dashboard-panel appointments-panel">
        <div class="panel-heading appointment-heading">
          <div>
            <p class="section-kicker">Schedule</p>
            <h2>Today's Appointments</h2>
          </div>
          <RouterLink :to="{ name: 'registrar-document-appointments', query: { group: 'today' } }" class="view-link">
            View Appointments
          </RouterLink>
        </div>
        <p v-if="loading" class="panel-state">Loading today's schedule…</p>
        <div v-else-if="!dashboard.todays_appointments.length" class="empty-appointments">
          <span aria-hidden="true">✓</span>
          <strong>No appointments today</strong>
          <p>The schedule is clear for the day.</p>
        </div>
        <div v-else class="appointment-list">
          <div class="table-heading appointment-table-heading" aria-hidden="true">
            <span>Time</span>
            <span>Student / Document</span>
            <span>Status</span>
          </div>
          <RouterLink
            v-for="appointment in dashboard.todays_appointments"
            :key="appointment.id"
            :to="{ name: 'registrar-document-appointments', query: { appointment_id: appointment.id } }"
            class="appointment-item"
          >
            <time>{{ formatAppointmentTime(appointment.appointment_time) }}</time>
            <span class="appointment-copy">
              <strong>{{ appointment.student_name }}</strong>
              <small>{{ appointment.student_number }} · {{ appointment.document }}</small>
            </span>
            <span class="status-badge" :class="appointment.status">{{ readableStatus(appointment.status) }}</span>
          </RouterLink>
        </div>
      </section>
    </div>
  </section>
</template>

<style scoped>
.dashboard-page {
  min-width: 0;
}

.dashboard-header {
  align-items: flex-end;
  display: flex;
  gap: 16px;
  justify-content: space-between;
  margin-bottom: 18px;
}

.dashboard-header .page-description {
  margin-top: 6px;
}

.dashboard-header .page-title {
  color: #173f2b;
}

.retry-button {
  background: #176c42;
  border: 1px solid #0f5935;
  border-radius: 5px;
  color: #fff;
  cursor: pointer;
  font-weight: 750;
  min-height: 36px;
  padding: 0 13px;
}

.dashboard-error {
  background: #fff1f0;
  border: 1px solid #ffd1cc;
  border-radius: 5px;
  color: #9f2d25;
  margin: 0 0 18px;
  padding: 10px 12px;
}

.summary-grid {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.summary-card {
  align-items: center;
  background: #fff;
  border: 1px solid #c5d5ca;
  border-radius: 5px;
  box-shadow: 0 4px 10px rgba(15, 75, 41, 0.07);
  display: flex;
  gap: 15px;
  height: 112px;
  min-width: 0;
  overflow: hidden;
  padding: 18px;
  position: relative;
}

.summary-card::after {
  background: linear-gradient(90deg, #0d6940 0%, #65a51e 100%);
  bottom: 0;
  content: '';
  height: 3px;
  left: 0;
  position: absolute;
  width: 58px;
}

.summary-card:first-child {
  background: linear-gradient(145deg, #063c26 0%, #0a6938 62%, #6da51d 100%);
  border-color: #075631;
  box-shadow: 0 5px 12px rgba(5, 71, 36, 0.16);
}

.summary-card:first-child::after {
  background: rgba(244, 211, 94, 0.72);
  width: 74px;
}

.summary-card:first-child .card-icon {
  background: rgba(255, 255, 255, 0.14);
  border-color: rgba(255, 255, 255, 0.3);
  color: #fff;
}

.summary-card:first-child strong,
.summary-card:first-child div > span {
  color: #fff;
}

.card-icon,
.action-icon {
  align-items: center;
  background: linear-gradient(145deg, #edf8f0 0%, #dceee2 100%);
  border: 1px solid #c9dfcf;
  border-radius: 4px;
  display: flex;
  flex: 0 0 auto;
  height: 46px;
  justify-content: center;
  width: 46px;
}

.card-icon svg,
.action-icon svg {
  color: currentColor;
  height: 23px;
  width: 23px;
}

.summary-card strong,
.summary-card span {
  display: block;
}

.summary-card strong {
  color: #173d29;
  font-size: clamp(1.85rem, 2.4vw, 2.2rem);
  font-weight: 800;
  line-height: 1;
}

.summary-card div > span {
  color: #52645a;
  font-size: 0.75rem;
  font-weight: 700;
  line-height: 1.35;
  margin-top: 7px;
}

.tone-green { color: #147448; }
.tone-yellow { color: #4e8f61; }
.tone-teal { color: #287951; }
.tone-amber { color: #6e9845; }

.middle-grid,
.bottom-grid {
  display: grid;
  gap: 12px;
  margin-top: 12px;
  min-width: 0;
}

.middle-grid {
  grid-template-columns: minmax(0, 1.65fr) minmax(270px, 0.85fr);
}

.bottom-grid {
  grid-template-columns: minmax(0, 0.9fr) minmax(0, 1.35fr);
}

.dashboard-panel {
  background: #fff;
  border: 1px solid #b9ccbf;
  border-radius: 6px;
  box-shadow: 0 3px 8px rgba(20, 65, 40, 0.055);
  min-width: 0;
  overflow: hidden;
  padding: 0 13px 13px;
}

.panel-heading {
  align-items: center;
  background: linear-gradient(90deg, #e8f4eb 0%, #f7faf8 74%);
  border-bottom: 1px solid #c8d9cc;
  display: flex;
  gap: 12px;
  justify-content: space-between;
  margin: 0 -13px 11px;
  min-height: 47px;
  padding: 8px 12px;
  position: relative;
}

.panel-heading::after {
  background: linear-gradient(90deg, #0b6b3d 0%, #77a923 100%);
  bottom: -1px;
  content: '';
  height: 2px;
  left: 0;
  position: absolute;
  width: 72px;
}

.section-kicker {
  color: #4d725b;
  font-size: 0.6rem;
  font-weight: 800;
  letter-spacing: 0.1em;
  margin: 0 0 2px;
  text-transform: uppercase;
}

.panel-heading h2 {
  color: #174b2d;
  font-size: 0.92rem;
  font-weight: 800;
  margin: 0;
}

.quick-actions {
  display: grid;
  gap: 7px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.quick-action,
.record-alert,
.activity-item,
.appointment-item {
  min-width: 0;
}

.quick-action {
  align-items: center;
  background: #fbfcfb;
  border: 1px solid #d8e2db;
  border-radius: 5px;
  display: flex;
  gap: 9px;
  min-height: 58px;
  padding: 10px;
}

.quick-action:hover {
  background: #f1f7f2;
  border-color: #a9c5b1;
}

.action-icon {
  color: #176c42;
  height: 36px;
  width: 36px;
}

.action-icon svg {
  color: currentColor;
  height: 19px;
  width: 19px;
}

.quick-action strong,
.quick-action small,
.record-alert strong,
.record-alert small {
  display: block;
}

.quick-action strong,
.record-alert strong {
  color: #253b2d;
  font-size: 0.81rem;
}

.quick-action small,
.record-alert small {
  color: #6c786f;
  font-size: 0.66rem;
  line-height: 1.35;
  margin-top: 2px;
}

.arrow {
  color: #37764f;
  flex: 0 0 auto;
  font-weight: 800;
  margin-left: auto;
}

.alerts-list {
  display: grid;
  gap: 7px;
}

.record-alert {
  align-items: center;
  background: #fbfcfb;
  border: 1px solid #d8e2db;
  border-radius: 5px;
  display: flex;
  gap: 9px;
  min-height: 58px;
  padding: 10px;
}

.record-alert:hover {
  background: #f1f7f2;
  border-color: #a9c5b1;
}

.alert-count {
  align-items: center;
  background: linear-gradient(145deg, #edf7e8 0%, #dcebcf 100%);
  border: 1px solid #cfdfbf;
  border-radius: 4px;
  color: #527621;
  display: flex;
  flex: 0 0 auto;
  font-size: 0.9rem;
  font-weight: 850;
  height: 36px;
  justify-content: center;
  min-width: 36px;
  padding: 0 7px;
}

.alert-count.danger {
  background: linear-gradient(145deg, #edf7ef 0%, #dceee1 100%);
  border-color: #c6ddcb;
  color: #246b3e;
}

.panel-state {
  color: #78847d;
  margin: 0;
  padding: 22px 4px;
  text-align: center;
}

.activity-list,
.appointment-list {
  border: 1px solid #c5d5ca;
  border-radius: 4px;
  display: grid;
  overflow: hidden;
}

.table-heading {
  align-items: center;
  background: #dcefe1;
  border-bottom: 1px solid #b6cfbd;
  color: #194d2e;
  display: grid;
  font-size: 0.62rem;
  font-weight: 800;
  gap: 10px;
  letter-spacing: 0.035em;
  min-height: 30px;
  padding: 6px 8px;
  text-transform: uppercase;
}

.activity-table-heading {
  grid-template-columns: minmax(0, 1fr) auto;
}

.activity-table-heading span:first-child {
  padding-left: 23px;
}

.appointment-table-heading {
  grid-template-columns: 70px minmax(0, 1fr) auto;
}

.activity-item {
  align-items: flex-start;
  background: #fff;
  border-top: 1px solid #d9e3dc;
  display: grid;
  gap: 8px;
  grid-template-columns: auto minmax(0, 1fr) auto;
  padding: 9px 8px;
}

.table-heading + .activity-item,
.table-heading + .appointment-item {
  border-top: 0;
}

.activity-item:hover,
.appointment-item:hover {
  background: #f4f9f5;
}

.activity-dot {
  background: #2c8051;
  border: 3px solid #dceee1;
  border-radius: 50%;
  height: 12px;
  margin-top: 4px;
  width: 12px;
}

.activity-dot.appointment {
  background: #6f963d;
  border-color: #e8f0dc;
}

.activity-dot.record_location {
  background: #39775a;
  border-color: #dfede4;
}

.activity-copy strong,
.activity-copy span,
.activity-copy small {
  display: block;
}

.activity-copy strong {
  color: #28382f;
  font-size: 0.76rem;
}

.activity-copy span {
  color: #4e5d54;
  font-size: 0.71rem;
  margin-top: 2px;
}

.activity-copy small {
  color: #8a948e;
  font-size: 0.64rem;
  margin-top: 3px;
}

.activity-item time {
  color: #7b8780;
  font-size: 0.63rem;
  white-space: nowrap;
}

.view-link {
  color: #176c42;
  font-size: 0.7rem;
  font-weight: 800;
  white-space: nowrap;
}

.view-link:hover {
  color: #0d4f2d;
}

.appointment-item {
  align-items: center;
  background: #fff;
  border-top: 1px solid #d9e3dc;
  display: grid;
  gap: 10px;
  grid-template-columns: 70px minmax(0, 1fr) auto;
  padding: 9px 8px;
}

.appointment-item > time {
  color: #176c42;
  font-size: 0.71rem;
  font-weight: 800;
}

.appointment-copy strong,
.appointment-copy small {
  display: block;
}

.appointment-copy strong {
  color: #26362d;
  font-size: 0.76rem;
}

.appointment-copy small {
  color: #748078;
  font-size: 0.66rem;
  margin-top: 2px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.status-badge {
  background: #deefe2;
  border: 1px solid #b7d3be;
  border-radius: 4px;
  color: #24683d;
  font-size: 0.61rem;
  font-weight: 800;
  padding: 3px 6px;
  text-transform: capitalize;
  white-space: nowrap;
}

.status-badge.confirmed,
.status-badge.completed {
  background: #cfe9d6;
  border-color: #a9cdb2;
  color: #0e5d33;
}

.status-badge.cancelled,
.status-badge.no_show {
  background: #fceae7;
  border-color: #ebc9c3;
  color: #a33d2b;
}

.empty-appointments {
  align-items: center;
  color: #6f7c74;
  display: flex;
  flex-direction: column;
  padding: 22px 10px 18px;
  text-align: center;
}

.empty-appointments > span {
  align-items: center;
  background: #e7f5ec;
  border-radius: 50%;
  color: #176c42;
  display: flex;
  font-size: 1.1rem;
  font-weight: 900;
  height: 36px;
  justify-content: center;
  margin-bottom: 10px;
  width: 36px;
}

.empty-appointments strong {
  color: #304038;
  font-size: 0.9rem;
}

.empty-appointments p {
  font-size: 0.76rem;
  margin: 5px 0 0;
}

@media (max-width: 1100px) {
  .summary-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .middle-grid,
  .bottom-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}

@media (max-width: 620px) {
  .dashboard-header {
    align-items: flex-start;
    flex-direction: column;
  }

  .summary-grid,
  .quick-actions {
    grid-template-columns: minmax(0, 1fr);
  }

  .summary-card {
    height: 104px;
  }

  .appointment-heading {
    align-items: flex-start;
  }

  .appointment-item {
    align-items: start;
    grid-template-columns: 62px minmax(0, 1fr);
  }

  .appointment-item .status-badge {
    grid-column: 2;
    justify-self: start;
  }

  .activity-item {
    grid-template-columns: auto minmax(0, 1fr);
  }

  .activity-item time {
    grid-column: 2;
  }

  .table-heading {
    display: none;
  }
}
</style>
