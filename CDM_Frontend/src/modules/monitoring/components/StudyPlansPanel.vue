<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useAuthStore } from '../../../stores/authStore'
import { ROLES } from '../../../config/accessControl'
import { fetchStudentStudyPlan, fetchStudyPlans } from '../services/monitoringApi'

const props = defineProps({
  studentId: { type: Number, default: null },
})

const authStore = useAuthStore()
const loading = ref(true)
const generating = ref(false)
const error = ref('')
const payload = ref(null)
const selectedId = ref(null)

const isStudent = computed(() => authStore.currentRole === ROLES.STUDENT)
const plans = computed(() => payload.value?.plans || [])
const selected = computed(() => {
  if (props.studentId) {
    return plans.value.find((plan) => plan.student_id === props.studentId) || plans.value[0] || null
  }
  return plans.value.find((plan) => plan.student_id === selectedId.value) || plans.value[0] || null
})
const showLearnerPicker = computed(() => !isStudent.value && plans.value.length > 1)

const riskClass = (level) => ({
  high: 'risk-high',
  moderate: 'risk-moderate',
  low: 'risk-low',
}[level] || 'risk-low')

const dayShort = (day) => ({
  Monday: 'Mon',
  Tuesday: 'Tue',
  Wednesday: 'Wed',
  Thursday: 'Thu',
  Friday: 'Fri',
  Saturday: 'Sat',
  Sunday: 'Sun',
}[day] || day)

const load = async () => {
  loading.value = true
  error.value = ''
  try {
    payload.value = await fetchStudyPlans()
    selectedId.value = props.studentId || payload.value?.plans?.[0]?.student_id || null
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to load guided study plans.'
  } finally {
    loading.value = false
  }
}

const regenerate = async () => {
  if (!selected.value || generating.value) return
  generating.value = true
  error.value = ''
  try {
    const plan = await fetchStudentStudyPlan(selected.value.student_id)
    const index = plans.value.findIndex((item) => item.student_id === plan.student_id)
    if (index >= 0) plans.value[index] = plan
    else plans.value.unshift(plan)
    selectedId.value = plan.student_id
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to regenerate study plan.'
  } finally {
    generating.value = false
  }
}

watch(() => props.studentId, (id) => {
  if (id) selectedId.value = id
})

onMounted(load)
</script>

<template>
  <div class="panel">
    <div v-if="error" class="banner banner-error" role="alert">
      <span>{{ error }}</span>
      <button type="button" class="btn danger" @click="load">Retry</button>
    </div>
    <p v-if="loading" class="banner banner-muted">Preparing professional study schedule…</p>

    <template v-else-if="selected">
      <div class="plan-shell" :class="{ 'with-sidebar': showLearnerPicker }">
        <aside v-if="showLearnerPicker" class="plan-list" aria-label="Learners">
          <div class="list-head">
            <h3>Learners</h3>
            <span>{{ plans.length }}</span>
          </div>
          <div class="learner-scroll">
            <button
              v-for="plan in plans"
              :key="plan.student_id"
              type="button"
              class="learner"
              :class="{ active: selected.student_id === plan.student_id }"
              @click="selectedId = plan.student_id"
            >
              <strong>{{ plan.student_name }}</strong>
              <small>{{ plan.student_number }}</small>
              <em class="risk-dot" :class="riskClass(plan.risk_level)" />
            </button>
          </div>
        </aside>

        <section class="plan-main">
          <header class="plan-hero">
            <div class="hero-copy">
              <p class="eyebrow">Guided Study Plan</p>
              <h2>{{ selected.student_name }}</h2>
              <p class="week-label">{{ selected.week_label }}</p>
              <p class="headline">{{ selected.headline }}</p>
            </div>
            <div class="hero-meta">
              <span class="risk-badge" :class="riskClass(selected.risk_level)">
                {{ selected.risk_label || selected.risk_level }}
              </span>
              <button type="button" class="btn primary" :disabled="generating" @click="regenerate">
                {{ generating ? 'Generating…' : 'Regenerate plan' }}
              </button>
            </div>
          </header>

          <div class="plan-body">
            <div class="stat-row">
              <article>
                <span>Study load</span>
                <strong>{{ selected.total_hours ?? '—' }} <small>hrs</small></strong>
              </article>
              <article>
                <span>Sessions</span>
                <strong>{{ selected.session_count ?? selected.week?.length ?? 0 }}</strong>
              </article>
              <article>
                <span>Focus subjects</span>
                <strong>{{ selected.focus_subjects?.length || 0 }}</strong>
              </article>
              <article>
                <span>Course</span>
                <strong class="truncate">{{ selected.course_code || '—' }}</strong>
              </article>
            </div>

            <p v-if="selected.objective" class="objective">
              <span>Objective</span>
              {{ selected.objective }}
            </p>

            <div class="focus-block">
              <h3>Priority subjects</h3>
              <div v-if="selected.focus_subjects?.length" class="focus-row">
                <article
                  v-for="subject in selected.focus_subjects"
                  :key="subject.subject_code"
                  class="focus-chip"
                >
                  <div class="focus-copy">
                    <strong>{{ subject.subject_code }}</strong>
                    <small>{{ subject.subject_name }}</small>
                  </div>
                  <span class="risk-badge" :class="riskClass(subject.risk_level)">
                    avg {{ subject.average_grade ?? '—' }}
                  </span>
                </article>
              </div>
              <p v-else class="empty">Maintenance schedule — no critical subject decline detected.</p>
            </div>

            <div class="schedule-block">
              <div class="schedule-head">
                <h3>Weekly schedule</h3>
                <span>{{ selected.week?.length || 0 }} sessions</span>
              </div>

              <div class="timeline">
                <article
                  v-for="(day, index) in selected.week"
                  :key="`${day.day}-${index}`"
                  class="session"
                  :class="riskClass(day.priority)"
                >
                  <div class="session-day">
                    <span class="day-short">{{ dayShort(day.day) }}</span>
                    <span class="day-full">{{ day.day }}</span>
                    <span class="day-num">{{ String(index + 1).padStart(2, '0') }}</span>
                  </div>
                  <div class="session-body">
                    <div class="session-top">
                      <div>
                        <strong>{{ day.session_type || 'Study block' }}</strong>
                        <p class="subject-line">{{ day.subject_code }} · {{ day.subject_name }}</p>
                      </div>
                      <div class="meta-chips">
                        <span class="chip">{{ day.time_slot || 'Flexible' }}</span>
                        <span class="chip">{{ day.duration_minutes }} min</span>
                      </div>
                    </div>
                    <p class="focus">{{ day.focus }}</p>
                    <p v-if="day.objective" class="goal">{{ day.objective }}</p>
                  </div>
                </article>
              </div>
            </div>
          </div>
        </section>
      </div>
    </template>
    <p v-else-if="!loading" class="empty">No study plan available for the selected learner.</p>
  </div>
</template>

<style scoped>
.panel {
  width: 100%;
  min-width: 0;
}

.banner {
  margin: 0 0 16px;
  padding: 12px 14px;
  border-radius: 12px;
  line-height: 1.45;
}

.banner-error {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  background: #fef3f2;
  color: #b42318;
}

.banner-muted {
  background: var(--color-green-tint);
  color: var(--color-dark-spring-green);
}

.btn {
  border: 0;
  border-radius: 10px;
  font-weight: 700;
  min-height: 40px;
  padding: 0 14px;
  cursor: pointer;
  white-space: nowrap;
}

.btn:disabled {
  opacity: 0.7;
  cursor: wait;
}

.btn.primary {
  background: #fff;
  color: var(--color-dartmouth-green);
}

.btn.danger {
  background: #b42318;
  color: #fff;
}

.plan-shell {
  display: grid;
  grid-template-columns: 1fr;
  gap: 14px;
  min-width: 0;
}

.plan-shell.with-sidebar {
  grid-template-columns: 1fr;
}

.plan-list,
.plan-main {
  min-width: 0;
  border: 1px solid var(--color-border);
  border-radius: 18px;
  background: var(--color-surface);
  box-shadow: var(--shadow-soft);
}

.plan-list {
  padding: 14px;
}

.list-head,
.schedule-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 12px;
}

.list-head h3,
.focus-block h3,
.schedule-head h3 {
  margin: 0;
  font-family: var(--font-display);
  font-size: 1.05rem;
}

.list-head span,
.schedule-head span {
  color: var(--color-muted);
  font-size: 0.8rem;
  font-weight: 700;
}

.learner-scroll {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  padding-bottom: 4px;
  scroll-snap-type: x proximity;
  -webkit-overflow-scrolling: touch;
}

.learner {
  position: relative;
  flex: 0 0 auto;
  width: min(220px, 78vw);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  background: #fff;
  padding: 12px 14px;
  text-align: left;
  cursor: pointer;
  scroll-snap-align: start;
}

.learner.active {
  border-color: rgba(16, 106, 46, 0.45);
  background: var(--color-green-tint);
}

.learner strong {
  display: block;
  font-size: 0.95rem;
  line-height: 1.3;
  padding-right: 14px;
}

.learner small {
  display: block;
  margin-top: 4px;
  color: var(--color-muted);
}

.risk-dot {
  position: absolute;
  top: 12px;
  right: 12px;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  font-style: normal;
}

.risk-dot.risk-high { background: #b42318; }
.risk-dot.risk-moderate { background: #b54708; }
.risk-dot.risk-low { background: #176434; }

.plan-main {
  overflow: hidden;
}

.plan-hero {
  display: grid;
  gap: 16px;
  padding: 20px;
  background:
    radial-gradient(circle at top right, rgba(244, 211, 94, 0.22), transparent 42%),
    linear-gradient(135deg, #0f5f2a 0%, #106a2e 45%, #0d7856 100%);
  color: #fff;
}

.hero-copy {
  min-width: 0;
}

.eyebrow {
  margin: 0 0 6px;
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  opacity: 0.9;
}

.plan-hero h2 {
  margin: 0;
  font-family: var(--font-display);
  font-size: clamp(1.35rem, 4vw, 1.85rem);
  line-height: 1.2;
  word-break: break-word;
}

.week-label {
  margin: 8px 0 0;
  font-weight: 700;
  color: rgba(255, 255, 255, 0.9);
  font-size: 0.92rem;
}

.headline {
  margin: 8px 0 0;
  color: rgba(255, 255, 255, 0.84);
  line-height: 1.5;
  font-size: 0.95rem;
}

.hero-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}

.plan-body {
  padding: 16px;
}

.stat-row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.stat-row article {
  border: 1px solid var(--color-border);
  border-radius: 14px;
  padding: 12px;
  background: var(--color-anti-flash-white);
  min-width: 0;
}

.stat-row span {
  display: block;
  color: var(--color-muted);
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.stat-row strong {
  display: block;
  margin-top: 6px;
  font-family: var(--font-display);
  font-size: 1.2rem;
  line-height: 1.2;
}

.stat-row strong small {
  font-family: var(--font-body);
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--color-muted);
}

.truncate {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.objective {
  margin: 14px 0 0;
  padding: 14px;
  border-radius: 14px;
  background: rgba(16, 106, 46, 0.06);
  border: 1px solid rgba(16, 106, 46, 0.12);
  color: var(--color-eerie-black);
  line-height: 1.55;
}

.objective span {
  display: block;
  margin-bottom: 4px;
  color: var(--color-dartmouth-green);
  font-size: 0.75rem;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.focus-block,
.schedule-block {
  margin-top: 18px;
}

.focus-row {
  display: grid;
  grid-template-columns: 1fr;
  gap: 10px;
  margin-top: 10px;
}

.focus-chip {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  border: 1px solid var(--color-border);
  border-radius: 14px;
  padding: 12px 14px;
  background: #fff;
  min-width: 0;
}

.focus-copy {
  min-width: 0;
}

.focus-copy strong {
  display: block;
}

.focus-copy small,
.empty,
.focus,
.goal,
.subject-line {
  color: var(--color-muted);
}

.focus-copy small,
.subject-line {
  display: block;
  margin-top: 3px;
  line-height: 1.4;
  overflow-wrap: anywhere;
}

.timeline {
  display: grid;
  gap: 10px;
  margin-top: 12px;
}

.session {
  display: grid;
  grid-template-columns: 64px 1fr;
  gap: 12px;
  border: 1px solid var(--color-border);
  border-left-width: 4px;
  border-radius: 16px;
  padding: 12px;
  background: #fff;
  min-width: 0;
}

.session.risk-high { border-left-color: #b42318; }
.session.risk-moderate { border-left-color: #b54708; }
.session.risk-low { border-left-color: #176434; }

.session-day {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  border-radius: 12px;
  background: var(--color-green-tint);
  color: var(--color-dartmouth-green);
  padding: 10px 6px;
  text-align: center;
}

.day-short {
  font-weight: 800;
  font-size: 0.95rem;
}

.day-full {
  display: none;
}

.day-num {
  font-size: 0.72rem;
  font-weight: 700;
  opacity: 0.75;
}

.session-body {
  min-width: 0;
}

.session-top {
  display: grid;
  gap: 10px;
}

.session-top strong {
  display: block;
  font-size: 0.98rem;
  line-height: 1.3;
}

.meta-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.chip {
  display: inline-flex;
  align-items: center;
  border-radius: 999px;
  background: var(--color-anti-flash-white);
  border: 1px solid var(--color-border);
  color: var(--color-muted);
  font-size: 0.72rem;
  font-weight: 700;
  padding: 4px 10px;
}

.focus,
.goal {
  margin: 8px 0 0;
  line-height: 1.5;
  font-size: 0.92rem;
  overflow-wrap: anywhere;
}

.goal {
  font-style: italic;
}

.risk-badge {
  display: inline-flex;
  align-items: center;
  flex-shrink: 0;
  border-radius: 999px;
  padding: 4px 10px;
  font-size: 0.7rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.02em;
}

.risk-high { background: #fee4e2; color: #b42318; }
.risk-moderate { background: #fef0c7; color: #b54708; }
.risk-low { background: #dcf5e5; color: #176434; }

.plan-hero .risk-badge {
  background: rgba(255, 255, 255, 0.18);
  color: #fff;
  border: 1px solid rgba(255, 255, 255, 0.28);
}

.empty {
  margin: 10px 0 0;
  line-height: 1.5;
}

@media (min-width: 640px) {
  .plan-body {
    padding: 18px 20px 22px;
  }

  .plan-hero {
    padding: 24px;
  }

  .stat-row {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }

  .focus-row {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .session {
    grid-template-columns: 84px 1fr;
    padding: 14px;
  }

  .day-short { display: none; }
  .day-full {
    display: block;
    font-weight: 800;
    font-size: 0.88rem;
  }

  .session-top {
    grid-template-columns: 1fr auto;
    align-items: start;
  }
}

@media (min-width: 960px) {
  .plan-shell.with-sidebar {
    grid-template-columns: 240px minmax(0, 1fr);
    align-items: start;
  }

  .learner-scroll {
    display: grid;
    gap: 8px;
    overflow: visible;
    padding-bottom: 0;
  }

  .learner {
    width: 100%;
  }

  .plan-hero {
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: start;
  }

  .hero-meta {
    flex-direction: column;
    align-items: flex-end;
  }

  .btn.primary {
    background: #fff;
  }
}

@media (min-width: 1200px) {
  .focus-row {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
</style>
