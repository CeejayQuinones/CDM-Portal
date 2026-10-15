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
    <header class="page-head">
      <div>
        <p class="kicker">Student portal</p>
        <h1>{{ displayName }}</h1>
        <p class="sub">{{ todayLabel }}</p>
      </div>
      <RouterLink v-if="unreadNotifications" class="notice" to="/monitoring">
        {{ unreadNotifications }} unread notice{{ unreadNotifications === 1 ? '' : 's' }}
      </RouterLink>
    </header>

    <section class="standing" :class="`risk-${riskLevel}`" aria-label="Academic monitoring summary">
      <div class="standing-main">
        <div class="standing-head">
          <p class="kicker">Academic standing</p>
          <span class="risk-badge" :class="`tone-${riskLevel}`">{{ riskLabel }}</span>
        </div>
        <h2>{{ riskHeadline }}</h2>
        <dl class="metrics">
          <div>
            <dt>Average grade</dt>
            <dd>{{ riskLoading ? '—' : averageGrade }}</dd>
          </div>
          <div>
            <dt>Subjects</dt>
            <dd>{{ riskLoading ? '—' : subjectCount }}</dd>
          </div>
          <div>
            <dt>Needs attention</dt>
            <dd>{{ riskLoading ? '—' : attentionSubjects }}</dd>
          </div>
        </dl>
        <div class="actions">
          <RouterLink class="btn-primary" to="/monitoring">Open monitoring</RouterLink>
        </div>
      </div>
      <aside class="standing-aside">
        <p class="aside-title">On this account</p>
        <ul>
          <li>Early warning from approved grades and graded scores</li>
          <li>Study files, guides, and practice quizzes</li>
        </ul>
      </aside>
    </section>

    <section class="services" aria-label="Student services">
      <div class="section-head">
        <h2>Services</h2>
        <p>Monitoring and account settings for your account.</p>
      </div>
      <div class="service-grid">
        <RouterLink
          v-for="service in services"
          :key="service.name"
          :to="service.path"
          class="service-card"
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
        </RouterLink>
      </div>
    </section>
  </section>
</template>

<style scoped>
.student-dashboard {
  --ink: var(--student-heading, #173d29);
  --muted: var(--student-muted, #5c6b62);
  --surface: var(--student-surface, #fff);
  --surface-soft: var(--student-surface-soft, #f4f8f5);
  --border: var(--student-border, #d5e1d8);
  --accent: var(--student-accent, #0d6940);
  --shadow: var(--student-card-shadow, 0 10px 24px rgba(15, 75, 41, 0.06));

  display: grid;
  gap: 18px;
  min-width: 0;
}

.page-head {
  align-items: flex-end;
  display: flex;
  gap: 16px;
  justify-content: space-between;
}

.kicker {
  color: var(--accent);
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.12em;
  margin: 0 0 6px;
  text-transform: uppercase;
}

.page-head h1 {
  color: var(--ink);
  font-size: clamp(1.6rem, 2.6vw, 2rem);
  letter-spacing: -0.03em;
  line-height: 1.15;
  margin: 0;
}

.sub {
  color: var(--muted);
  margin: 6px 0 0;
}

.notice {
  background: #fff8db;
  border: 1px solid #ead48a;
  border-radius: 999px;
  color: #854d0e;
  font-size: 0.86rem;
  font-weight: 700;
  padding: 8px 12px;
  white-space: nowrap;
}

.standing {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 16px;
  box-shadow: var(--shadow);
  display: grid;
  grid-template-columns: minmax(0, 1.7fr) minmax(220px, 0.8fr);
  overflow: hidden;
}

.standing.risk-high { border-color: color-mix(in srgb, #c2410c 32%, var(--border)); }
.standing.risk-moderate { border-color: color-mix(in srgb, #ca8a04 32%, var(--border)); }

.standing-main {
  display: grid;
  gap: 14px;
  padding: 22px 24px;
}

.standing-head {
  align-items: center;
  display: flex;
  gap: 12px;
  justify-content: space-between;
}

.standing-main h2 {
  color: var(--ink);
  font-size: clamp(1.15rem, 2vw, 1.35rem);
  font-weight: 700;
  letter-spacing: -0.02em;
  line-height: 1.4;
  margin: 0;
  max-width: 42ch;
}

.risk-badge {
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 800;
  letter-spacing: 0.04em;
  padding: 6px 10px;
  text-transform: uppercase;
}

.risk-badge.tone-high { background: #fff1e8; color: #9a3412; }
.risk-badge.tone-moderate { background: #fff8db; color: #854d0e; }
.risk-badge.tone-low { background: #e8f7ee; color: #166534; }
.risk-badge.tone-unknown { background: var(--surface-soft); color: var(--muted); }

.metrics {
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  margin: 0;
}

.metrics div {
  background: #fff;
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 12px 14px;
}

.metrics dt {
  color: var(--muted);
  font-size: 0.78rem;
  font-weight: 600;
}

.metrics dd {
  color: var(--ink);
  font-size: 1.45rem;
  font-weight: 750;
  letter-spacing: -0.03em;
  margin: 4px 0 0;
}

.actions {
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
  font-weight: 700;
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

.standing-aside {
  background: #fff;
  border-left: 1px solid var(--border);
  display: grid;
  align-content: start;
  gap: 12px;
  padding: 22px 20px;
}

.aside-title {
  color: var(--ink);
  font-weight: 750;
  margin: 0;
}

.standing-aside ul {
  color: var(--muted);
  display: grid;
  gap: 10px;
  list-style: none;
  margin: 0;
  padding: 0;
}

.standing-aside li {
  padding-left: 14px;
  position: relative;
}

.standing-aside li::before {
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
  display: grid;
  gap: 12px;
}

.section-head h2 {
  color: var(--ink);
  font-size: 1.05rem;
  margin: 0;
}

.section-head p {
  color: var(--muted);
  margin: 4px 0 0;
}

.service-grid {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.service-card {
  align-items: center;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 14px;
  box-shadow: var(--shadow);
  display: grid;
  gap: 14px;
  grid-template-columns: auto minmax(0, 1fr);
  padding: 16px;
  transition: border-color 160ms ease, box-shadow 160ms ease;
}

.service-card:hover {
  border-color: color-mix(in srgb, var(--accent) 45%, var(--border));
  box-shadow: var(--student-card-shadow-hover, 0 14px 28px rgba(13, 105, 64, 0.1));
}

.service-icon {
  align-items: center;
  background: color-mix(in srgb, var(--accent) 10%, var(--surface));
  border-radius: 10px;
  color: var(--accent);
  display: flex;
  height: 42px;
  justify-content: center;
  width: 42px;
}

.service-icon svg {
  height: 20px;
  width: 20px;
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
  margin-top: 3px;
}

@media (max-width: 900px) {
  .standing,
  .service-grid {
    grid-template-columns: 1fr;
  }

  .standing-aside {
    border-left: 0;
    border-top: 1px solid var(--border);
  }
}

@media (max-width: 640px) {
  .page-head {
    align-items: flex-start;
    flex-direction: column;
  }

  .standing-main {
    padding: 18px;
  }

  .metrics {
    grid-template-columns: 1fr;
  }
}
</style>
