<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '../stores/authStore'
import { useStudentProfileStore } from '../stores/studentProfile'
import { fetchMyRisk, fetchMyRiskNotifications } from '../modules/monitoring/services/monitoringApi'

const authStore = useAuthStore()
const studentProfile = useStudentProfileStore()

const riskLoading = ref(true)
const riskError = ref('')
const assessment = ref(null)
const unreadNotifications = ref(0)

const displayName = computed(() => {
  if (studentProfile.preferredDisplayName) return studentProfile.preferredDisplayName
  const profile = authStore.currentUser?.profile
  const fullName = [profile?.first_name, profile?.last_name].filter(Boolean).join(' ')
  return fullName || authStore.currentUser?.username || 'Student'
})

const todayLabel = computed(() =>
  new Intl.DateTimeFormat('en-PH', {
    weekday: 'long',
    month: 'long',
    day: 'numeric',
    year: 'numeric',
  }).format(new Date()),
)

const riskLevel = computed(() => assessment.value?.risk_level || 'unknown')
const riskLabel = computed(() => {
  if (riskLoading.value) return 'Checking status…'
  if (!assessment.value) return 'No grade data yet'
  return assessment.value.risk_label || 'Status unavailable'
})

const riskHeadline = computed(() => {
  if (riskLoading.value) return 'Loading your academic monitoring summary.'
  if (riskError.value) return riskError.value
  if (!assessment.value) return 'Approved grades will appear here once they are released.'
  return assessment.value.headline || 'Review your academic standing and study plan.'
})

const averageGrade = computed(() => {
  const value = assessment.value?.average_grade
  return value == null ? '—' : Number(value).toFixed(1)
})

const subjectCount = computed(() => assessment.value?.subjects?.length || 0)

const attentionSubjects = computed(() =>
  (assessment.value?.subjects || []).filter((s) => ['high', 'moderate'].includes(s.risk_level)).length,
)

const services = [
  {
    name: 'monitoring',
    label: 'Academic Monitoring',
    description: 'Early warnings, AI study help, and weekly study plans',
    path: '/monitoring',
    icon: 'M3 12h4l3-8 4 16 3-8h4',
    featured: true,
  },
  {
    name: 'grading',
    label: 'Grading',
    description: 'View released grades by subject and period',
    path: '/grading',
    icon: 'M4 19h16M7 16V8m5 8V5m5 11v-6',
  },
  {
    name: 'document-requests',
    label: 'Document Requests',
    description: 'Request certificates and book registrar appointments',
    path: '/document-requests',
    icon: 'M7 3h7l4 4v14H7zM14 3v5h5M10 12h5M10 16h5',
  },
  {
    name: 'event-attendance',
    label: 'Event Attendance',
    description: 'Campus events and QR check-in',
    path: '/event-attendance',
    icon: 'M5 5h14v16H5zM8 3v4M16 3v4M8 11h8M8 15h5',
  },
  {
    name: 'settings',
    label: 'Settings',
    description: 'Profile, display name, and theme preferences',
    path: '/settings',
    icon: 'M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7zM4 12h2M18 12h2',
  },
]

async function loadAcademicSummary() {
  riskLoading.value = true
  riskError.value = ''
  try {
    const [risk, notifications] = await Promise.all([
      fetchMyRisk(),
      fetchMyRiskNotifications().catch(() => ({ unread: 0 })),
    ])
    assessment.value = risk?.students?.[0] || null
    unreadNotifications.value = notifications?.unread || 0
  } catch (err) {
    riskError.value = err.response?.data?.message || 'Unable to load academic monitoring.'
    assessment.value = null
  } finally {
    riskLoading.value = false
  }
}

onMounted(loadAcademicSummary)
</script>

<template>
  <section class="student-dashboard">
    <header class="dash-hero">
      <div class="dash-hero-copy">
        <p class="dash-kicker">Student workspace</p>
        <h1>Welcome back, {{ displayName }}</h1>
        <p class="dash-lead">
          Track your academic standing, documents, and campus activity from one place.
        </p>
        <div class="dash-meta">
          <span class="meta-pill">{{ todayLabel }}</span>
          <span v-if="unreadNotifications" class="meta-alert">
            {{ unreadNotifications }} unread risk notice{{ unreadNotifications === 1 ? '' : 's' }}
          </span>
        </div>
      </div>
    </header>

    <section class="monitor-card" :class="`risk-${riskLevel}`" aria-label="Academic monitoring summary">
      <div class="monitor-main">
        <div class="monitor-head">
          <p class="dash-kicker">Academic Monitoring</p>
          <span class="risk-badge" :class="`tone-${riskLevel}`">{{ riskLabel }}</span>
        </div>
        <h2>{{ riskHeadline }}</h2>
        <div class="monitor-stats">
          <article>
            <span>Average grade</span>
            <strong>{{ riskLoading ? '—' : averageGrade }}</strong>
          </article>
          <article>
            <span>Subjects tracked</span>
            <strong>{{ riskLoading ? '—' : subjectCount }}</strong>
          </article>
          <article>
            <span>Needs attention</span>
            <strong>{{ riskLoading ? '—' : attentionSubjects }}</strong>
          </article>
        </div>
        <div class="monitor-actions">
          <RouterLink class="btn-primary" to="/monitoring">Open AI Monitoring</RouterLink>
          <RouterLink class="btn-secondary" to="/monitoring">View study plan</RouterLink>
        </div>
      </div>
      <aside class="monitor-aside">
        <p class="aside-title">What you can do</p>
        <ul>
          <li>Review early-warning signals by subject</li>
          <li>Ask AI Help for study guidance</li>
          <li>Follow a weekly focus plan</li>
        </ul>
      </aside>
    </section>

    <section class="services" aria-label="Student services">
      <div class="section-head">
        <div>
          <p class="dash-kicker">Services</p>
          <h2>Quick access</h2>
        </div>
        <p>Jump into the modules available on your student account.</p>
      </div>

      <div class="service-grid">
        <RouterLink
          v-for="service in services"
          :key="service.name"
          :to="service.path"
          class="service-card"
          :class="{ featured: service.featured }"
        >
          <span class="service-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
              <path :d="service.icon" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </span>
          <span class="service-copy">
            <strong>{{ service.label }}</strong>
            <small>{{ service.description }}</small>
          </span>
          <span class="service-arrow" aria-hidden="true">→</span>
        </RouterLink>
      </div>
    </section>
  </section>
</template>

<style scoped>
.student-dashboard {
  --ink: var(--student-heading, #173d29);
  --muted: var(--student-muted, var(--color-muted));
  --surface: var(--student-surface, #fff);
  --surface-soft: var(--student-surface-soft, #f4f8f5);
  --border: var(--student-border, #c9d8ce);
  --accent: var(--student-accent, #0d7856);
  --shadow: var(--student-card-shadow, 0 12px 28px rgba(15, 75, 41, 0.07));

  animation: page-enter 260ms ease both;
  display: grid;
  gap: 20px;
  min-width: 0;
}

.dash-hero {
  background:
    radial-gradient(circle at 90% 15%, rgba(244, 211, 94, 0.2), transparent 30%),
    linear-gradient(135deg, #063c26 0%, #0a6938 58%, #147a45 100%);
  border: 1px solid #075631;
  border-radius: 18px;
  box-shadow: 0 18px 40px rgba(6, 60, 38, 0.16);
  color: #fff;
  overflow: hidden;
  padding: 28px 30px;
  position: relative;
}

.dash-hero::after {
  background: linear-gradient(90deg, rgba(244, 211, 94, 0.9), transparent 60%);
  bottom: 0;
  content: '';
  height: 3px;
  left: 0;
  position: absolute;
  width: 42%;
}

.dash-kicker {
  color: var(--accent);
  font-size: 0.76rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  margin: 0 0 8px;
  text-transform: uppercase;
}

.dash-hero .dash-kicker {
  color: rgba(244, 211, 94, 0.95);
}

.dash-hero h1 {
  font-size: clamp(1.7rem, 3vw, 2.35rem);
  line-height: 1.12;
  margin: 0;
}

.dash-lead {
  color: rgba(255, 255, 255, 0.9);
  line-height: 1.6;
  margin: 10px 0 0;
  max-width: 48ch;
}

.dash-meta {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 18px;
}

.meta-pill,
.meta-alert {
  border-radius: 999px;
  font-size: 0.86rem;
  padding: 7px 12px;
}

.meta-pill {
  background: rgba(255, 255, 255, 0.12);
  border: 1px solid rgba(255, 255, 255, 0.2);
}

.meta-alert {
  background: rgba(244, 211, 94, 0.2);
  border: 1px solid rgba(244, 211, 94, 0.45);
  color: #fff8d9;
  font-weight: 700;
}

.monitor-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 18px;
  box-shadow: var(--shadow);
  display: grid;
  gap: 0;
  grid-template-columns: minmax(0, 1.7fr) minmax(220px, 0.85fr);
  overflow: hidden;
}

.monitor-card.risk-high {
  border-color: color-mix(in srgb, #c2410c 35%, var(--border));
}

.monitor-card.risk-moderate {
  border-color: color-mix(in srgb, #ca8a04 35%, var(--border));
}

.monitor-main {
  display: grid;
  gap: 16px;
  padding: 22px 24px;
}

.monitor-head {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  justify-content: space-between;
}

.monitor-main h2 {
  color: var(--ink);
  font-size: clamp(1.15rem, 2vw, 1.4rem);
  line-height: 1.35;
  margin: 0;
}

.risk-badge {
  border-radius: 999px;
  font-size: 0.78rem;
  font-weight: 800;
  letter-spacing: 0.03em;
  padding: 6px 11px;
  text-transform: uppercase;
}

.risk-badge.tone-high {
  background: #fff1e8;
  color: #9a3412;
}

.risk-badge.tone-moderate {
  background: #fff8db;
  color: #854d0e;
}

.risk-badge.tone-low {
  background: #e8f7ee;
  color: #166534;
}

.risk-badge.tone-unknown {
  background: var(--surface-soft);
  color: var(--muted);
}

.monitor-stats {
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.monitor-stats article {
  background: var(--surface-soft);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 12px 14px;
}

.monitor-stats span {
  color: var(--muted);
  display: block;
  font-size: 0.8rem;
}

.monitor-stats strong {
  color: var(--ink);
  display: block;
  font-size: 1.45rem;
  margin-top: 4px;
}

.monitor-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.btn-primary,
.btn-secondary {
  align-items: center;
  border-radius: 10px;
  display: inline-flex;
  font-size: 0.92rem;
  font-weight: 750;
  min-height: 40px;
  padding: 0 14px;
}

.btn-primary {
  background: #0d6940;
  border: 1px solid #0a5734;
  color: #fff;
}

.btn-secondary {
  background: var(--surface);
  border: 1px solid var(--border);
  color: var(--accent);
}

.monitor-aside {
  background:
    linear-gradient(160deg, rgba(13, 105, 64, 0.08), transparent 50%),
    var(--surface-soft);
  border-left: 1px solid var(--border);
  display: grid;
  gap: 12px;
  padding: 22px 20px;
}

.aside-title {
  color: var(--ink);
  font-weight: 800;
  margin: 0;
}

.monitor-aside ul {
  color: var(--muted);
  display: grid;
  gap: 10px;
  list-style: none;
  margin: 0;
  padding: 0;
}

.monitor-aside li {
  padding-left: 16px;
  position: relative;
}

.monitor-aside li::before {
  background: var(--accent);
  border-radius: 50%;
  content: '';
  height: 6px;
  left: 0;
  position: absolute;
  top: 0.55em;
  width: 6px;
}

.services {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 18px;
  box-shadow: var(--shadow);
  padding: 22px;
}

.section-head {
  display: grid;
  gap: 4px;
  margin-bottom: 16px;
}

.section-head h2 {
  color: var(--ink);
  font-size: 1.2rem;
  margin: 0;
}

.section-head > p {
  color: var(--muted);
  margin: 0;
}

.service-grid {
  display: grid;
  gap: 10px;
}

.service-card {
  align-items: center;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 14px;
  display: grid;
  gap: 14px;
  grid-template-columns: auto minmax(0, 1fr) auto;
  padding: 14px 16px;
  transition:
    border-color 160ms ease,
    box-shadow 160ms ease,
    transform 160ms ease,
    background-color 160ms ease;
}

.service-card.featured {
  background: color-mix(in srgb, var(--accent) 8%, var(--surface));
  border-color: color-mix(in srgb, var(--accent) 35%, var(--border));
}

.service-card:hover {
  border-color: var(--accent);
  box-shadow: var(--student-card-shadow-hover, 0 14px 28px rgba(13, 120, 86, 0.12));
  transform: translateY(-2px);
}

.service-icon {
  align-items: center;
  background: color-mix(in srgb, var(--accent) 14%, var(--surface));
  border: 1px solid color-mix(in srgb, var(--accent) 28%, var(--border));
  border-radius: 12px;
  color: var(--accent);
  display: flex;
  height: 44px;
  justify-content: center;
  width: 44px;
}

.service-icon svg {
  height: 22px;
  width: 22px;
}

.service-copy strong {
  color: var(--ink);
  display: block;
  font-size: 0.98rem;
}

.service-copy small {
  color: var(--muted);
  display: block;
  font-size: 0.86rem;
  line-height: 1.45;
  margin-top: 2px;
}

.service-arrow {
  color: var(--accent);
  font-weight: 700;
}

@media (max-width: 900px) {
  .monitor-card {
    grid-template-columns: 1fr;
  }

  .monitor-aside {
    border-left: 0;
    border-top: 1px solid var(--border);
  }
}

@media (max-width: 640px) {
  .dash-hero,
  .monitor-main,
  .services {
    padding: 20px;
  }

  .monitor-stats {
    grid-template-columns: 1fr;
  }
}

@media (prefers-reduced-motion: reduce) {
  .student-dashboard,
  .service-card {
    animation: none;
    transition: none;
  }
}
</style>
