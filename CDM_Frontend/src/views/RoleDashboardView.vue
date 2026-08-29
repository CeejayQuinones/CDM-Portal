<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { ROLES, ROUTE_ROLES, canAccess } from '../config/accessControl'

const props = defineProps({
  role: { type: String, required: true },
})

const roles = ROLES
const canOpenMonitoring = computed(() => canAccess(props.role, ROUTE_ROLES.monitoring))

const highlights = computed(() => {
  if (props.role === ROLES.STUDENT) {
    return [
      'Check your grade risk signals early.',
      'Follow a professional weekly study plan for weak subjects.',
      'Ask AI Help before finals to prevent failing.',
    ]
  }

  return [
    'Review students flagged for risk of failing.',
    'Open adviser alerts inside AI Monitoring.',
    'Generate professional study plans for at-risk learners.',
  ]
})
</script>

<template>
  <section class="page-header">
    <p class="page-kicker">{{ role }} Portal</p>
    <h1 class="page-title">{{ role }} Dashboard</h1>
    <p class="page-description">
      Start with AI Monitoring for early warnings, study plans, and adviser alerts in one workspace.
    </p>
  </section>

  <section class="dashboard-panel">
    <h2>Quick actions</h2>
    <ul>
      <li v-for="item in highlights" :key="item">{{ item }}</li>
    </ul>
    <div class="cta-row">
      <RouterLink v-if="canOpenMonitoring" class="cta" :to="{ name: 'monitoring' }">
        Open AI Monitoring
      </RouterLink>
      <RouterLink
        v-if="canOpenMonitoring"
        class="cta secondary"
        :to="{ name: 'monitoring', query: { tab: 'study-plans' } }"
      >
        Study Plans
      </RouterLink>
      <RouterLink
        v-if="canOpenMonitoring && role !== roles.STUDENT"
        class="cta secondary"
        :to="{ name: 'monitoring', query: { tab: 'alerts' } }"
      >
        Adviser Alerts
      </RouterLink>
    </div>
  </section>
</template>

<style scoped>
.dashboard-panel {
  border: 1px solid var(--color-border);
  border-radius: 16px;
  background: var(--color-surface);
  padding: 22px;
  box-shadow: var(--shadow-soft);
  max-width: 720px;
}

.dashboard-panel h2 {
  margin: 0 0 12px;
  font-family: var(--font-display);
  font-size: 1.25rem;
}

.dashboard-panel ul {
  margin: 0 0 18px;
  padding-left: 18px;
  color: var(--color-muted);
  line-height: 1.6;
}

.cta-row {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.cta {
  display: inline-flex;
  align-items: center;
  min-height: 42px;
  border-radius: 8px;
  background: var(--color-dartmouth-green);
  color: #fff;
  font-weight: 700;
  padding: 0 16px;
}

.cta.secondary {
  background: transparent;
  border: 1px solid rgba(16, 106, 46, 0.28);
  color: var(--color-dark-spring-green);
}
</style>
