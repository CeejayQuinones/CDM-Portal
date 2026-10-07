<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useAuthStore } from '../../../stores/authStore'
import { ROLES } from '../../../config/accessControl'
import { fetchStudentStudyPlan, fetchStudyPlans } from '../services/monitoringApi'

const props = defineProps({ studentId: { type: Number, default: null } })
const auth = useAuthStore()
const payload = ref(null)
const selectedId = ref(null)
const loading = ref(true)
const refreshing = ref(false)
const error = ref('')

const isStudent = computed(() => auth.currentRole === ROLES.STUDENT)
const plans = computed(() => payload.value?.plans || [])
const selected = computed(
  () => plans.value.find((plan) => plan.student_id === selectedId.value) || plans.value[0] || null,
)

const load = async () => {
  loading.value = true
  error.value = ''
  try {
    payload.value = await fetchStudyPlans()
    selectedId.value = props.studentId || plans.value[0]?.student_id || null
  } catch (requestError) {
    error.value = requestError.response?.data?.message || 'Unable to load suggested academic support plans.'
  } finally {
    loading.value = false
  }
}

const refreshSelected = async () => {
  if (!selected.value || refreshing.value) return
  refreshing.value = true
  error.value = ''
  try {
    const plan = await fetchStudentStudyPlan(selected.value.student_id)
    const index = plans.value.findIndex((item) => item.student_id === plan.student_id)
    if (index >= 0) payload.value.plans.splice(index, 1, plan)
    else payload.value.plans.unshift(plan)
  } catch (requestError) {
    error.value = requestError.response?.data?.message || 'Unable to refresh this support plan.'
  } finally {
    refreshing.value = false
  }
}

const grade = (value) => (value === null || value === undefined ? 'Not published' : Number(value).toFixed(2))
watch(() => props.studentId, (studentId) => { if (studentId) selectedId.value = studentId })
onMounted(load)
</script>

<template>
  <section class="study-plan-panel">
    <div v-if="error" class="monitor-state error" role="alert">
      <span>{{ error }}</span>
      <button type="button" @click="load">Retry</button>
    </div>
    <p v-if="loading" class="monitor-state" role="status">Preparing suggested academic support plans…</p>

    <template v-else-if="selected">
      <div class="plan-layout" :class="{ 'has-picker': !isStudent && plans.length > 1 }">
        <aside v-if="!isStudent && plans.length > 1" class="plan-picker" aria-label="Student support plans">
          <header><h2>Students</h2><span>{{ plans.length }}</span></header>
          <button
            v-for="plan in plans"
            :key="plan.student_id"
            type="button"
            :class="{ active: plan.student_id === selected.student_id }"
            @click="selectedId = plan.student_id"
          >
            <span><strong>{{ plan.student_name }}</strong><small>{{ plan.student_number }}</small></span>
            <em class="risk-pill" :data-level="plan.risk_level">{{ plan.risk_label }}</em>
          </button>
        </aside>

        <article class="plan-card">
          <header class="plan-header">
            <div>
              <p class="plan-kicker">{{ selected.plan_label }}</p>
              <h2>{{ isStudent ? 'My support plan' : selected.student_name }}</h2>
              <p>{{ selected.headline }}</p>
            </div>
            <div class="plan-actions">
              <span class="risk-pill large" :data-level="selected.risk_level">{{ selected.risk_label }}</span>
              <button type="button" :disabled="refreshing" @click="refreshSelected">
                {{ refreshing ? 'Refreshing…' : 'Refresh plan' }}
              </button>
            </div>
          </header>

          <div class="plan-metrics">
            <div><span>Plan</span><strong>{{ selected.plan_type }}</strong></div>
            <div><span>Weekly time</span><strong>{{ selected.total_hours }} hours</strong></div>
            <div><span>Sessions</span><strong>{{ selected.session_count }}</strong></div>
            <div><span>Published coverage</span><strong>{{ selected.data_completeness.published_subjects }} / {{ selected.data_completeness.expected_subjects }}</strong></div>
          </div>

          <section class="plan-objective">
            <h3>Objective</h3>
            <p>{{ selected.objective }}</p>
          </section>

          <section>
            <div class="plan-section-heading">
              <h3>Focus subjects</h3>
              <span>{{ selected.focus_subjects.length }}</span>
            </div>
            <div v-if="selected.focus_subjects.length" class="focus-grid">
              <article v-for="subject in selected.focus_subjects" :key="subject.subject_code">
                <header>
                  <span><strong>{{ subject.subject_code }}</strong><small>{{ subject.subject_name }}</small></span>
                  <em class="risk-pill" :data-level="subject.risk_level">{{ subject.risk_label }}</em>
                </header>
                <dl>
                  <div><dt>Midterm</dt><dd>{{ grade(subject.midterm_grade) }}</dd></div>
                  <div><dt>Finals</dt><dd>{{ grade(subject.finals_grade) }}</dd></div>
                  <div><dt>Official final</dt><dd>{{ grade(subject.final_grade) }}</dd></div>
                </dl>
                <p>{{ subject.reasons[0] }}</p>
              </article>
            </div>
            <p v-else class="plan-empty">More Published grade data is needed to build a detailed support plan.</p>
          </section>

          <section>
            <div class="plan-section-heading">
              <h3>Suggested weekly schedule</h3>
              <span>{{ selected.session_count }} sessions</span>
            </div>
            <div class="session-list">
              <article v-for="(session, index) in selected.week" :key="session.day + '-' + index">
                <div class="session-day"><strong>{{ session.day }}</strong><span>{{ session.time_slot }}</span></div>
                <div class="session-detail">
                  <header><strong>{{ session.session_type }}</strong><span>{{ session.duration_minutes }} min</span></header>
                  <p class="subject">{{ session.subject_code }} · {{ session.subject_name }}</p>
                  <p>{{ session.focus }}</p>
                </div>
                <span class="risk-pill" :data-level="session.priority">{{ session.priority }}</span>
              </article>
            </div>
          </section>
        </article>
      </div>
    </template>

    <div v-else class="plan-empty">
      <h2>No support plan available</h2>
      <p>More Published grade data is needed to build a detailed support plan.</p>
      <button type="button" @click="load">Retry</button>
    </div>
  </section>
</template>

<style scoped>
.study-plan-panel { color: var(--text-primary); }
.plan-layout { display: grid; gap: 16px; }
.plan-layout.has-picker { grid-template-columns: minmax(250px, 310px) minmax(0, 1fr); align-items: start; }
.plan-picker, .plan-card { border: 1px solid var(--border-color); border-radius: 14px; background: var(--bg-surface); box-shadow: var(--shadow-soft); }
.plan-picker { position: sticky; top: 16px; overflow: hidden; }
.plan-picker > header { display: flex; justify-content: space-between; padding: 16px; border-bottom: 1px solid var(--border-color); }
.plan-picker h2 { margin: 0; }
.plan-picker button { display: flex; justify-content: space-between; gap: 10px; width: 100%; border: 0; border-bottom: 1px solid var(--border-color); padding: 13px 15px; background: transparent; color: var(--text-primary); text-align: left; }
.plan-picker button.active, .plan-picker button:hover { background: var(--bg-surface-alt); box-shadow: inset 3px 0 var(--accent); }
.plan-picker button span { display: grid; gap: 3px; }
.plan-picker small, .plan-header p, .focus-grid p, .session-detail p, .session-day span { color: var(--text-secondary); }
.plan-card { display: grid; gap: 22px; padding: clamp(18px, 2.4vw, 28px); }
.plan-header, .plan-actions, .plan-section-heading, .focus-grid header, .session-detail header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.plan-kicker { margin: 0; color: var(--accent) !important; font-size: .78rem; font-weight: 800; letter-spacing: .07em; text-transform: uppercase; }
.plan-header h2 { margin: 4px 0 6px; }
.plan-header p { margin: 0; }
.plan-actions { align-items: center; }
.plan-actions button, .monitor-state button, .plan-empty button { min-height: 40px; border: 1px solid var(--accent); border-radius: 8px; padding: 8px 13px; background: var(--bg-surface); color: var(--accent); font-weight: 700; }
.plan-metrics { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
.plan-metrics div, .plan-objective, .focus-grid > article { border: 1px solid var(--border-color); border-radius: 10px; padding: 13px; background: var(--bg-surface-alt); }
.plan-metrics span, .plan-section-heading span { color: var(--text-muted); font-size: .8rem; font-weight: 700; }
.plan-metrics strong { display: block; margin-top: 5px; }
.plan-objective h3, .plan-section-heading h3 { margin: 0; }
.plan-objective p { margin-bottom: 0; color: var(--text-secondary); }
.focus-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-top: 12px; }
.focus-grid header > span { display: grid; gap: 2px; }
.focus-grid dl { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin: 12px 0; }
.focus-grid dl div { padding: 8px; border-radius: 8px; background: var(--bg-surface); }
.focus-grid dt { color: var(--text-muted); font-size: .72rem; }
.focus-grid dd { margin: 3px 0 0; font-weight: 800; }
.session-list { display: grid; gap: 9px; margin-top: 12px; }
.session-list > article { display: grid; grid-template-columns: 155px minmax(0, 1fr) auto; gap: 14px; align-items: start; border: 1px solid var(--border-color); border-radius: 10px; padding: 13px; background: var(--bg-surface-alt); }
.session-day { display: grid; gap: 3px; }
.session-detail p { margin: 7px 0 0; }
.session-detail .subject { color: var(--text-primary); font-weight: 700; }
.risk-pill { display: inline-flex; width: max-content; border-radius: 999px; padding: 4px 9px; background: var(--bg-surface-alt); color: var(--text-secondary); font-size: .73rem; font-style: normal; font-weight: 800; text-transform: capitalize; }
.risk-pill.large { padding: 7px 11px; font-size: .82rem; }
.risk-pill[data-level='high'] { background: var(--danger-bg); color: var(--danger); }
.risk-pill[data-level='moderate'] { background: var(--warning-bg); color: var(--warning); }
.risk-pill[data-level='stable'] { background: var(--success-bg); color: var(--success); }
.monitor-state, .plan-empty { border: 1px solid var(--border-color); border-radius: 10px; padding: 18px; background: var(--bg-surface); color: var(--text-secondary); }
.monitor-state { display: flex; justify-content: space-between; gap: 12px; }
.monitor-state.error { border-color: var(--danger); background: var(--danger-bg); color: var(--danger); }
.plan-empty { text-align: center; }
.plan-empty h2 { color: var(--text-primary); }
@media (max-width: 1000px) { .plan-layout.has-picker { grid-template-columns: 1fr; } .plan-picker { position: static; } .plan-metrics { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 680px) { .plan-header, .plan-actions { display: grid; } .plan-metrics, .focus-grid { grid-template-columns: 1fr; } .session-list > article { grid-template-columns: 1fr; } .plan-actions button { width: 100%; } }
</style>
