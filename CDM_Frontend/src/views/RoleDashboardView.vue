<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '../stores/authStore'
import { useNavigationStore } from '../stores/navigation'
import { useStudentProfileStore } from '../stores/studentProfile'

const props = defineProps({
  role: { type: String, required: true },
})

const authStore = useAuthStore()
const navigationStore = useNavigationStore()
const studentProfile = useStudentProfileStore()

const ROLE_COPY = {
  Student: {
    kicker: 'Student workspace',
    title: 'Student Dashboard',
    description: 'Track academics, documents, and campus activity from one place.',
    highlights: [
      {
        label: 'Academics',
        detail: 'Grades & monitoring',
        icon: 'M4 19h16M7 16V8m5 8V5m5 11v-6',
      },
      {
        label: 'Documents',
        detail: 'Requests & appointments',
        icon: 'M7 3h7l4 4v14H7zM14 3v5h5M10 12h5M10 16h5',
      },
      {
        label: 'Events',
        detail: 'Attendance & QR check-in',
        icon: 'M5 5h14v16H5zM8 3v4M16 3v4M8 11h8M8 15h5',
      },
    ],
    tips: [
      'Use Document Requests to schedule registrar appointments.',
      'Check Monitoring for academic risk alerts and study plans.',
      'Update your profile and theme anytime in Settings.',
    ],
  },
  Professor: {
    kicker: 'Faculty workspace',
    title: 'Professor Dashboard',
    description: 'Manage grading, academic monitoring, and event attendance.',
    highlights: [
      {
        label: 'Grading',
        detail: 'Submit and review grades',
        icon: 'M4 19h16M7 16V8m5 8V5m5 11v-6',
      },
      {
        label: 'Monitoring',
        detail: 'Advise at-risk students',
        icon: 'M3 12h4l3-8 4 16 3-8h4',
      },
      {
        label: 'Events',
        detail: 'Attendance tracking',
        icon: 'M5 5h14v16H5zM8 3v4M16 3v4M8 11h8M8 15h5',
      },
    ],
    tips: [
      'Open Grading to encode class results by period.',
      'Use Monitoring to review adviser alerts.',
      'Scan or manage attendance under Event Attendance.',
    ],
  },
  Admin: {
    kicker: 'Administration',
    title: 'Admin Dashboard',
    description: 'Oversee campus modules, enrollment workflows, and academic monitoring.',
    highlights: [
      {
        label: 'Enrollment',
        detail: 'Programs & sections',
        icon: 'M4 6h16M4 12h16M4 18h10',
      },
      {
        label: 'Monitoring',
        detail: 'AI early warnings',
        icon: 'M3 12h4l3-8 4 16 3-8h4',
      },
      {
        label: 'Records',
        detail: 'Student management',
        icon: 'M5 20v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2M12 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
      },
    ],
    tips: [
      'Start with Admission and Enrollment for intake operations.',
      'Open Monitoring for AI early warnings and study plans.',
      'Use Student Management for records and document review.',
    ],
  },
  Guest: {
    kicker: 'Applicant portal',
    title: 'Guest Dashboard',
    description: 'Complete your profile and prepare for student account activation.',
    highlights: [
      {
        label: 'Profile',
        detail: 'Keep your details current',
        icon: 'M5 20v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2M12 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
      },
      {
        label: 'Activation',
        detail: 'Ready your student account',
        icon: 'M12 3l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V7l8-4z',
      },
      {
        label: 'Support',
        detail: 'Registrar assistance',
        icon: 'M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z',
      },
    ],
    tips: [
      'Review My Profile to confirm personal information.',
      'Prepare required documents before activation.',
      'Contact the registrar if account activation is delayed.',
    ],
  },
}

const MODULE_META = {
  grading: {
    description: 'View and manage academic grades',
    icon: 'M4 19h16M7 16V8m5 8V5m5 11v-6',
  },
  monitoring: {
    description: 'Academic risk alerts and study plans',
    icon: 'M3 12h4l3-8 4 16 3-8h4',
  },
  'event-attendance': {
    description: 'Campus events and QR attendance',
    icon: 'M5 5h14v16H5zM8 3v4M16 3v4M8 11h8M8 15h5',
  },
  'student-document-requests': {
    description: 'Request certificates and credentials',
    icon: 'M7 3h7l4 4v14H7zM14 3v5h5M10 12h5M10 16h5',
  },
  settings: {
    description: 'Profile, display name, and preferences',
    icon: 'M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7zM4 12h2M18 12h2M6.3 6.3l1.4 1.4M16.3 16.3l1.4 1.4M6.3 17.7l1.4-1.4M16.3 7.7l1.4-1.4',
  },
  admission: {
    description: 'Applicant cycles and intake review',
    icon: 'M12 3l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V7l8-4z',
  },
  enrollment: {
    description: 'Enrollment periods and sectioning',
    icon: 'M4 6h16M4 12h16M4 18h10',
  },
  'student-management': {
    description: 'Student profiles and digital records',
    icon: 'M5 20v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2M12 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
  },
  'physical-records': {
    description: 'Cabinets and physical file locations',
    icon: 'M4 5h16v15H4zM4 10h16M9 7.5h6M9 14h6',
  },
  'guest-profile': {
    description: 'Update your applicant information',
    icon: 'M5 20v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2M12 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
  },
  'activate-student-account': {
    description: 'Begin student account activation',
    icon: 'M12 3l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V7l8-4z',
  },
}

const DASHBOARD_ROUTE_NAMES = new Set([
  'guest-dashboard',
  'student-dashboard',
  'professor-dashboard',
  'registrar-dashboard',
  'admin-dashboard',
])

const copy = computed(() => ROLE_COPY[props.role] || ROLE_COPY.Student)

const displayName = computed(() => {
  if (props.role === 'Student' && studentProfile.preferredDisplayName) {
    return studentProfile.preferredDisplayName
  }

  const profile = authStore.currentUser?.profile
  const fullName = [profile?.first_name, profile?.last_name].filter(Boolean).join(' ')

  return fullName || authStore.currentUser?.username || 'Portal User'
})

const todayLabel = computed(() =>
  new Intl.DateTimeFormat('en-PH', {
    weekday: 'long',
    month: 'long',
    day: 'numeric',
    year: 'numeric',
  }).format(new Date()),
)

const quickLinks = computed(() => {
  const items = navigationStore.menuItemsForRole(props.role)
  const links = []

  for (const item of items) {
    if (DASHBOARD_ROUTE_NAMES.has(item.name)) continue

    if (item.children?.length) {
      for (const child of item.children) {
        if (!child.path || DASHBOARD_ROUTE_NAMES.has(child.name)) continue
        const meta = MODULE_META[child.name] || {}
        links.push({
          name: child.name,
          label: child.label,
          path: child.path,
          description: meta.description || `Open ${child.label}`,
          icon: meta.icon || 'M5 12h14M13 6l6 6-6 6',
          comingSoon: Boolean(child.comingSoon),
        })
      }
      continue
    }

    if (!item.path) continue
    const meta = MODULE_META[item.name] || {}
    links.push({
      name: item.name,
      label: item.label,
      path: item.path,
      description: meta.description || `Open ${item.label}`,
      icon: meta.icon || 'M5 12h14M13 6l6 6-6 6',
      comingSoon: Boolean(item.comingSoon),
    })
  }

  return links
})
</script>

<template>
  <section class="role-dashboard">
    <header class="dash-hero">
      <div class="dash-hero-copy">
        <p class="dash-hero-kicker">{{ copy.kicker }}</p>
        <h1 class="dash-hero-title">{{ copy.title }}</h1>
        <p class="dash-hero-description">{{ copy.description }}</p>
        <div class="dash-hero-meta">
          <span class="dash-meta-pill">
            <strong>{{ displayName }}</strong>
            <small>{{ role }}</small>
          </span>
          <span class="dash-meta-date">{{ todayLabel }}</span>
        </div>
      </div>
      <div class="dash-hero-aside" aria-hidden="true">
        <div class="dash-hero-mark">
          <span>CDM</span>
          <small>Portal</small>
        </div>
      </div>
    </header>

    <section class="dash-highlight-grid" aria-label="Workspace overview">
      <article v-for="item in copy.highlights" :key="item.label" class="dash-highlight">
        <span class="dash-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path :d="item.icon" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </span>
        <div>
          <strong>{{ item.label }}</strong>
          <span>{{ item.detail }}</span>
        </div>
      </article>
    </section>

    <div class="dash-content-grid">
      <section class="dash-panel" aria-label="Quick access modules">
        <div class="dash-panel-heading">
          <div>
            <p class="dash-section-kicker">Shortcuts</p>
            <h2>Quick Access</h2>
          </div>
          <p class="dash-panel-note">Jump into the modules available for your role.</p>
        </div>

        <div v-if="quickLinks.length" class="dash-link-grid">
          <component
            :is="link.comingSoon ? 'div' : RouterLink"
            v-for="link in quickLinks"
            :key="link.name"
            :to="link.comingSoon ? undefined : link.path"
            class="dash-link"
            :class="{ 'is-soon': link.comingSoon }"
          >
            <span class="dash-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path :d="link.icon" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
            </span>
            <span class="dash-link-copy">
              <strong>
                {{ link.label }}
                <em v-if="link.comingSoon">Soon</em>
              </strong>
              <small>{{ link.description }}</small>
            </span>
            <span class="dash-link-arrow" aria-hidden="true">→</span>
          </component>
        </div>
        <p v-else class="dash-empty">No modules are available for this role yet.</p>
      </section>

      <aside class="dash-panel dash-tips">
        <div class="dash-panel-heading">
          <div>
            <p class="dash-section-kicker">Guidance</p>
            <h2>Getting started</h2>
          </div>
        </div>
        <ol class="dash-tips-list">
          <li v-for="(tip, index) in copy.tips" :key="tip">
            <span class="dash-tip-index">{{ index + 1 }}</span>
            <p>{{ tip }}</p>
          </li>
        </ol>
      </aside>
    </div>
  </section>
</template>

<style scoped>
.role-dashboard {
  --dash-ink: var(--student-heading, var(--color-eerie-black));
  --dash-muted: var(--student-muted, var(--color-muted));
  --dash-surface: var(--student-surface, var(--color-surface));
  --dash-surface-soft: var(--student-surface-soft, #f7fbf8);
  --dash-border: var(--student-border, #c5d5ca);
  --dash-accent: var(--student-accent, var(--color-dark-spring-green));
  --dash-shadow: var(--student-card-shadow, 0 10px 24px rgba(15, 75, 41, 0.05));
  --dash-shadow-hover: var(--student-card-shadow-hover, 0 12px 24px rgba(13, 120, 86, 0.1));

  animation: page-enter 260ms ease both;
  display: grid;
  gap: 20px;
  min-width: 0;
}

.dash-hero {
  align-items: stretch;
  background:
    radial-gradient(circle at 88% 18%, rgba(244, 211, 94, 0.18), transparent 28%),
    linear-gradient(135deg, #063c26 0%, #0a6938 58%, #147a45 100%);
  border: 1px solid #075631;
  border-radius: 18px;
  box-shadow: 0 18px 40px rgba(6, 60, 38, 0.18);
  color: #fff;
  display: grid;
  gap: 24px;
  grid-template-columns: minmax(0, 1fr) auto;
  overflow: hidden;
  padding: 28px 30px;
  position: relative;
}

.dash-hero::after {
  background: linear-gradient(90deg, rgba(244, 211, 94, 0.85), transparent 55%);
  bottom: 0;
  content: '';
  height: 3px;
  left: 0;
  position: absolute;
  width: 42%;
}

.dash-hero-kicker {
  color: rgba(244, 211, 94, 0.95);
  font-size: 0.78rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  margin: 0 0 8px;
  text-transform: uppercase;
}

.dash-hero-title {
  color: #fff;
  font-size: clamp(1.7rem, 3vw, 2.35rem);
  line-height: 1.1;
  margin: 0;
}

.dash-hero-description {
  color: rgba(255, 255, 255, 0.92);
  line-height: 1.6;
  margin: 10px 0 0;
  max-width: 46ch;
}

.dash-hero-meta {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 18px;
}

.dash-meta-pill {
  align-items: baseline;
  background: rgba(255, 255, 255, 0.12);
  border: 1px solid rgba(255, 255, 255, 0.22);
  border-radius: 999px;
  display: inline-flex;
  gap: 10px;
  padding: 8px 14px;
}

.dash-meta-pill strong {
  font-size: 0.95rem;
}

.dash-meta-pill small {
  color: rgba(255, 255, 255, 0.78);
  font-size: 0.78rem;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.dash-meta-date {
  color: rgba(255, 255, 255, 0.78);
  font-size: 0.9rem;
}

.dash-hero-aside {
  align-items: center;
  display: grid;
  place-items: center;
}

.dash-hero-mark {
  align-content: center;
  background: rgba(255, 255, 255, 0.1);
  border: 1px solid rgba(255, 255, 255, 0.22);
  border-radius: 20px;
  display: grid;
  height: 108px;
  justify-items: center;
  padding: 18px;
  width: 108px;
}

.dash-hero-mark span {
  font-size: 1.45rem;
  font-weight: 800;
  letter-spacing: 0.08em;
}

.dash-hero-mark small {
  color: rgba(255, 255, 255, 0.78);
  font-size: 0.78rem;
  letter-spacing: 0.16em;
  text-transform: uppercase;
}

.dash-highlight-grid {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.dash-highlight,
.dash-panel,
.dash-link {
  background: var(--dash-surface);
  border: 1px solid var(--dash-border);
}

.dash-highlight {
  align-items: center;
  border-radius: 14px;
  box-shadow: var(--dash-shadow);
  display: flex;
  gap: 14px;
  min-width: 0;
  padding: 16px 18px;
}

.dash-icon {
  align-items: center;
  background: color-mix(in srgb, var(--dash-accent) 14%, var(--dash-surface));
  border: 1px solid color-mix(in srgb, var(--dash-accent) 28%, var(--dash-border));
  border-radius: 12px;
  color: var(--dash-accent);
  display: flex;
  flex: 0 0 auto;
  height: 44px;
  justify-content: center;
  width: 44px;
}

.dash-icon svg {
  height: 22px;
  width: 22px;
}

.dash-highlight strong,
.dash-link-copy strong,
.dash-panel-heading h2 {
  color: var(--dash-ink);
  display: block;
  line-height: 1.25;
}

.dash-highlight strong,
.dash-link-copy strong {
  font-size: 0.98rem;
}

.dash-highlight span:last-child,
.dash-link-copy small,
.dash-panel-note,
.dash-empty,
.dash-tips-list p {
  color: var(--dash-muted);
}

.dash-highlight span:last-child,
.dash-link-copy small {
  display: block;
  font-size: 0.86rem;
  line-height: 1.45;
  margin-top: 2px;
}

.dash-content-grid {
  display: grid;
  gap: 16px;
  grid-template-columns: minmax(0, 1.7fr) minmax(260px, 0.9fr);
}

.dash-panel {
  border-radius: 16px;
  box-shadow: var(--dash-shadow);
  min-width: 0;
  padding: 20px;
}

.dash-panel-heading {
  display: grid;
  gap: 6px;
  margin-bottom: 16px;
}

.dash-section-kicker {
  color: var(--dash-accent);
  font-size: 0.78rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  margin: 0 0 4px;
  text-transform: uppercase;
}

.dash-panel-heading h2 {
  font-size: 1.15rem;
  margin: 0;
}

.dash-panel-note {
  font-size: 0.9rem;
  margin: 0;
}

.dash-link-grid {
  display: grid;
  gap: 10px;
}

.dash-link {
  align-items: center;
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

.dash-link:hover {
  background: var(--dash-surface-soft);
  border-color: var(--dash-accent);
  box-shadow: var(--dash-shadow-hover);
  transform: translateY(-2px);
}

.dash-link.is-soon {
  cursor: default;
  opacity: 0.72;
}

.dash-link.is-soon:hover {
  background: var(--dash-surface);
  border-color: var(--dash-border);
  box-shadow: none;
  transform: none;
}

.dash-link-copy strong {
  align-items: center;
  display: inline-flex;
  gap: 8px;
}

.dash-link-copy em {
  background: color-mix(in srgb, var(--color-naples-yellow) 35%, transparent);
  border-radius: 999px;
  color: #7a5d00;
  font-size: 0.68rem;
  font-style: normal;
  font-weight: 700;
  letter-spacing: 0.04em;
  padding: 2px 7px;
  text-transform: uppercase;
}

.dash-link-arrow {
  color: var(--dash-accent);
  font-size: 1.05rem;
  font-weight: 700;
}

.dash-tips-list {
  display: grid;
  gap: 12px;
  list-style: none;
  margin: 0;
  padding: 0;
}

.dash-tips-list li {
  align-items: flex-start;
  display: grid;
  gap: 12px;
  grid-template-columns: auto minmax(0, 1fr);
}

.dash-tip-index {
  align-items: center;
  background: var(--color-dartmouth-green);
  border-radius: 999px;
  color: #fff;
  display: inline-flex;
  flex: 0 0 auto;
  font-size: 0.78rem;
  font-weight: 700;
  height: 28px;
  justify-content: center;
  width: 28px;
}

.dash-tips-list p {
  font-size: 0.92rem;
  line-height: 1.55;
  margin: 0;
}

.dash-empty {
  margin: 0;
  padding: 12px 0 4px;
}

@media (max-width: 980px) {
  .dash-content-grid,
  .dash-highlight-grid,
  .dash-hero {
    grid-template-columns: 1fr;
  }

  .dash-hero-aside {
    justify-content: start;
  }
}

@media (max-width: 640px) {
  .dash-hero {
    padding: 22px 20px;
  }

  .dash-hero-aside {
    display: none;
  }

  .dash-highlight-grid {
    grid-template-columns: 1fr;
  }
}

@media (prefers-reduced-motion: reduce) {
  .role-dashboard,
  .dash-link {
    animation: none;
    transition: none;
  }
}
</style>
