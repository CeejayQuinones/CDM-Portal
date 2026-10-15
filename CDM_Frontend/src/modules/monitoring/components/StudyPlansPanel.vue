<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { fetchStudyPlans, fetchStudentStudyPlan } from '../services/monitoringApi'

const props = defineProps({ studentId: { type: Number, default: null } })
const plans = ref([])
const error = ref('')
const loading = ref(true)
const refreshing = ref(false)

const selected = computed(() => plans.value.find((plan) => plan.student_id === props.studentId) || plans.value[0])

const load = async () => {
  loading.value = true
  error.value = ''
  try {
    const response = await fetchStudyPlans()
    plans.value = response.plans || response || []
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to load study plans.'
  } finally {
    loading.value = false
  }
}

const refresh = async () => {
  if (!selected.value || refreshing.value) return
  refreshing.value = true
  error.value = ''
  try {
    const plan = await fetchStudentStudyPlan(selected.value.student_id)
    plans.value = [plan, ...plans.value.filter((item) => item.student_id !== plan.student_id)]
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to refresh this study plan.'
  } finally {
    refreshing.value = false
  }
}

watch(
  () => props.studentId,
  () => {
    error.value = ''
  },
)

onMounted(load)
</script>

<template>
  <section class="study-plan" aria-label="Study plan">
    <header>
      <div>
        <p class="kicker">Weekly focus</p>
        <h2>Study Plan</h2>
      </div>
      <button type="button" class="refresh-btn" :disabled="!selected || refreshing" @click="refresh">
        {{ refreshing ? 'Updating…' : 'Refresh plan' }}
      </button>
    </header>

    <p v-if="loading" class="state">Preparing study plan…</p>
    <p v-else-if="error" class="error" role="alert">{{ error }}</p>

    <template v-else-if="selected">
      <div class="plan-summary">
        <span class="risk-pill" :class="`tone-${selected.risk_level}`">{{ selected.risk_level }} risk</span>
        <p v-if="selected.focus_subjects?.length">
          Focus:
          <strong>
            {{ selected.focus_subjects.map((s) => s.subject_code).join(', ') }}
          </strong>
        </p>
      </div>

      <div class="session-list">
        <article v-for="session in selected.week" :key="`${session.day}-${session.subject_code}`" class="session">
          <div class="session-day">
            <strong>{{ session.day }}</strong>
            <span>{{ session.duration_minutes }} min</span>
          </div>
          <div class="session-copy">
            <strong>{{ session.subject_code }}</strong>
            <span>{{ session.focus }}</span>
          </div>
        </article>
      </div>
    </template>

    <p v-else class="state">No study plan available yet.</p>
  </section>
</template>

<style scoped>
.study-plan {
  --ink: var(--student-heading, #173d29);
  --muted: var(--student-muted, #52605a);
  --surface: var(--student-surface, #fff);
  --surface-soft: var(--student-surface-soft, #f4f8f5);
  --border: var(--student-border, #d7dfd6);
  --accent: var(--student-accent, #106a2e);

  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 14px;
  display: grid;
  gap: 14px;
  padding: 16px;
}

header {
  align-items: flex-start;
  display: flex;
  gap: 12px;
  justify-content: space-between;
}

.kicker {
  color: var(--accent);
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  margin: 0 0 4px;
  text-transform: uppercase;
}

h2 {
  color: var(--ink);
  font-size: 1.05rem;
  margin: 0;
}

.refresh-btn {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 999px;
  color: var(--accent);
  cursor: pointer;
  font-weight: 750;
  min-height: 36px;
  padding: 0 12px;
}

.refresh-btn:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.plan-summary {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.plan-summary p {
  color: var(--muted);
  margin: 0;
}

.plan-summary strong {
  color: var(--ink);
}

.risk-pill {
  border-radius: 999px;
  font-size: 0.74rem;
  font-weight: 800;
  letter-spacing: 0.03em;
  padding: 5px 10px;
  text-transform: uppercase;
}

.risk-pill.tone-high {
  background: #fff1e8;
  color: #9a3412;
}

.risk-pill.tone-moderate {
  background: #fff8db;
  color: #854d0e;
}

.risk-pill.tone-low {
  background: #e8f7ee;
  color: #166534;
}

.session-list {
  display: grid;
  gap: 8px;
}

.session {
  align-items: start;
  background: var(--surface-soft);
  border: 1px solid var(--border);
  border-radius: 12px;
  display: grid;
  gap: 12px;
  grid-template-columns: 96px minmax(0, 1fr);
  padding: 12px;
}

.session-day,
.session-copy {
  display: grid;
  gap: 2px;
}

.session-day strong,
.session-copy strong {
  color: var(--ink);
}

.session-day span,
.session-copy span,
.state {
  color: var(--muted);
}

.state,
.error {
  margin: 0;
}

.error {
  color: var(--student-danger, #a8291f);
}

@media (max-width: 520px) {
  .session {
    grid-template-columns: 1fr;
  }
}
</style>
