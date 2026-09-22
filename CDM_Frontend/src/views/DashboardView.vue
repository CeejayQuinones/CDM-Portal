<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useNavigationStore } from '../stores/navigation'
import { useAuthStore } from '../stores/authStore'

const navigationStore = useNavigationStore()
const authStore = useAuthStore()

const MODULE_META = {
  grading: { description: 'View and manage academic grades', icon: 'M4 19h16M7 16V8m5 8V5m5 11v-6' },
  monitoring: { description: 'Academic risk alerts and study plans', icon: 'M3 12h4l3-8 4 16 3-8h4' },
  'event-attendance': { description: 'Campus events and QR attendance', icon: 'M5 5h14v16H5zM8 3v4M16 3v4M8 11h8M8 15h5' },
  admission: { description: 'Applicant cycles and intake review', icon: 'M12 3l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V7l8-4z' },
  enrollment: { description: 'Enrollment periods and sectioning', icon: 'M4 6h16M4 12h16M4 18h10' },
  'student-management': { description: 'Student profiles and digital records', icon: 'M5 20v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2M12 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8z' },
  settings: { description: 'Profile and preferences', icon: 'M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7zM4 12h2M18 12h2' },
}

const DASHBOARD_NAMES = new Set([
  'dashboard',
  'guest-dashboard',
  'student-dashboard',
  'professor-dashboard',
  'registrar-dashboard',
  'admin-dashboard',
])

const moduleCards = computed(() => {
  const items = navigationStore.menuItemsForRole(authStore.currentRole)
  const cards = []

  for (const item of items) {
    if (DASHBOARD_NAMES.has(item.name)) continue

    if (item.children?.length) {
      for (const child of item.children) {
        if (!child.path || DASHBOARD_NAMES.has(child.name)) continue
        const meta = MODULE_META[child.name] || {}
        cards.push({
          name: child.name,
          label: child.label,
          path: child.path,
          description: meta.description || `Open ${child.label}`,
          icon: meta.icon || 'M5 12h14M13 6l6 6-6 6',
        })
      }
      continue
    }

    if (!item.path) continue
    const meta = MODULE_META[item.name] || {}
    cards.push({
      name: item.name,
      label: item.label,
      path: item.path,
      description: meta.description || item.description || `Open ${item.label}`,
      icon: meta.icon || 'M5 12h14M13 6l6 6-6 6',
    })
  }

  return cards
})
</script>

<template>
  <section class="home-dashboard">
    <header class="page-header">
      <p class="page-kicker">Overview</p>
      <h1 class="page-title">CDM Portal Dashboard</h1>
      <p class="page-description">
        Your centralized campus workspace for academics, records, and student services.
      </p>
    </header>

    <section class="dashboard-grid" aria-label="Campus management modules">
      <RouterLink v-for="module in moduleCards" :key="module.name" :to="module.path" class="module-card">
        <span class="module-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path :d="module.icon" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </span>
        <h2>{{ module.label }}</h2>
        <p>{{ module.description }}</p>
      </RouterLink>
    </section>
  </section>
</template>

<style scoped>
.home-dashboard {
  animation: page-enter 260ms ease both;
}

.dashboard-grid {
  display: grid;
  gap: 16px;
  grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
}

.module-card {
  align-content: start;
  background: var(--student-surface, var(--color-surface));
  border: 1px solid var(--student-border, #c5d5ca);
  border-radius: 16px;
  box-shadow: var(--student-card-shadow, 0 10px 24px rgba(15, 75, 41, 0.05));
  display: grid;
  gap: 12px;
  min-height: 180px;
  padding: 22px;
  transition:
    border-color 160ms ease,
    transform 160ms ease,
    box-shadow 160ms ease;
}

.module-card:hover {
  border-color: var(--student-accent, var(--color-dark-spring-green));
  box-shadow: var(--student-card-shadow-hover, 0 18px 36px rgba(13, 120, 86, 0.12));
  transform: translateY(-3px);
}

.module-icon {
  align-items: center;
  background: color-mix(in srgb, var(--student-accent, var(--color-dark-spring-green)) 14%, var(--student-surface, #fff));
  border: 1px solid color-mix(in srgb, var(--student-accent, var(--color-dark-spring-green)) 28%, var(--student-border, #c9dfcf));
  border-radius: 12px;
  color: var(--student-accent, #0d6940);
  display: flex;
  height: 46px;
  justify-content: center;
  width: 46px;
}

.module-icon svg {
  height: 22px;
  width: 22px;
}

.module-card h2 {
  color: var(--student-heading, #173d29);
  font-size: 1.05rem;
  line-height: 1.35;
  margin: 0;
}

.module-card p {
  color: var(--student-muted, var(--color-muted));
  line-height: 1.55;
  margin: 0;
}
</style>
