<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/authStore'
import { ROLES } from '../../config/accessControl'
import StudyPlansPanel from './components/StudyPlansPanel.vue'
import AdviserAlertsPanel from './components/AdviserAlertsPanel.vue'
import AiHelpChatbot from './components/AiHelpChatbot.vue'
import {
  fetchAiStatus,
  fetchEarlyWarnings,
  fetchMyRisk,
  generateSupportPlan,
} from './services/monitoringApi'

const authStore = useAuthStore()
const route = useRoute()
const router = useRouter()
const loading = ref(true)
const error = ref('')
const generating = ref(false)
const overview = ref(null)
const selectedId = ref(null)
const supportPlan = ref(null)
const aiStatus = ref({ live_ai_configured: false, provider: 'cdm-coach', model: null })

const isStudent = computed(() => authStore.currentRole === ROLES.STUDENT)
const canSeeAlerts = computed(() => !isStudent.value)
const tabs = computed(() => {
  const items = [
    { id: 'warnings', label: 'Early Warnings' },
    { id: 'study-plans', label: 'Study Plans' },
  ]
  if (canSeeAlerts.value) items.push({ id: 'alerts', label: 'Adviser Alerts' })
  return items
})
const activeTab = computed(() => {
  const tab = String(route.query.tab || 'warnings')
  if (tab === 'alerts' && !canSeeAlerts.value) return 'warnings'
  if (tabs.value.some((item) => item.id === tab)) return tab
  return 'warnings'
})
const students = computed(() => overview.value?.students || [])
const summary = computed(() => overview.value?.summary || { high: 0, moderate: 0, low: 0, total: 0 })
const selected = computed(() => students.value.find((item) => item.student_id === selectedId.value) || students.value[0] || null)
const aiBadgeLive = computed(() => Boolean(aiStatus.value.live_ai_configured))

const setTab = (tab) => {
  router.replace({ name: 'monitoring', query: tab === 'warnings' ? {} : { tab } })
}

const openStudentFromAlert = (studentId) => {
  selectedId.value = studentId
  supportPlan.value = students.value.find((item) => item.student_id === studentId)?.support_plan || null
  setTab('warnings')
}

watch(canSeeAlerts, (allowed) => {
  if (!allowed && route.query.tab === 'alerts') setTab('warnings')
})

const riskClass = (level) => ({
  high: 'risk-high',
  moderate: 'risk-moderate',
  low: 'risk-low',
}[level] || 'risk-low')

const load = async () => {
  loading.value = true
  error.value = ''
  supportPlan.value = null
  try {
    const [monitoringData, status] = await Promise.all([
      isStudent.value ? fetchMyRisk() : fetchEarlyWarnings(),
      fetchAiStatus().catch(() => ({ live_ai_configured: false, provider: 'cdm-coach' })),
    ])
    overview.value = monitoringData
    aiStatus.value = status
    selectedId.value = overview.value?.students?.[0]?.student_id ?? null
    if (selected.value?.support_plan) supportPlan.value = selected.value.support_plan
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to load AI monitoring data.'
  } finally {
    loading.value = false
  }
}

const selectStudent = (studentId) => {
  selectedId.value = studentId
  supportPlan.value = students.value.find((item) => item.student_id === studentId)?.support_plan || null
}

const onGenerate = async () => {
  if (!selected.value || generating.value) return
  generating.value = true
  error.value = ''
  try {
    const plan = await generateSupportPlan(selected.value.student_id)
    supportPlan.value = plan
    const target = students.value.find((item) => item.student_id === selected.value.student_id)
    if (target) target.support_plan = plan
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to generate support plan.'
  } finally {
    generating.value = false
  }
}

const onPlanFromChat = (plan) => {
  if (plan?.actions?.length) supportPlan.value = plan
}

onMounted(load)
</script>

<template>
  <section class="page-header">
    <p class="page-kicker">AI Monitoring</p>
    <h1 class="page-title">Academic Risk Center</h1>
    <p class="page-description">
      Early warnings, professional study plans, and adviser alerts in one place — catch failing risk early and guide recovery.
    </p>
  </section>

  <nav class="monitor-tabs" aria-label="AI Monitoring sections">
    <button
      v-for="tab in tabs"
      :key="tab.id"
      type="button"
      class="tab-btn"
      :class="{ active: activeTab === tab.id }"
      @click="setTab(tab.id)"
    >
      {{ tab.label }}
    </button>
  </nav>

  <div v-show="activeTab === 'warnings'">
    <div v-if="error" class="banner-error" role="alert">
      <span>{{ error }}</span>
      <button type="button" class="retry-btn" @click="load">Retry</button>
    </div>
    <p v-if="loading" class="banner-muted">Analyzing grade signals…</p>

    <template v-else-if="overview">
      <section class="summary-row" aria-label="Risk summary">
        <article>
          <span>High risk</span>
          <strong>{{ summary.high }}</strong>
        </article>
        <article>
          <span>Moderate</span>
          <strong>{{ summary.moderate }}</strong>
        </article>
        <article>
          <span>Stable</span>
          <strong>{{ summary.low }}</strong>
        </article>
        <article>
          <span>Monitored</span>
          <strong>{{ summary.total }}</strong>
        </article>
      </section>

      <div class="workspace">
        <section class="student-list" aria-label="Monitored students">
          <h2>{{ isStudent ? 'Your academic risk' : 'Students needing attention' }}</h2>
          <button
            v-for="student in students"
            :key="student.student_id"
            type="button"
            class="student-card"
            :class="{ active: selected?.student_id === student.student_id }"
            @click="selectStudent(student.student_id)"
          >
            <div>
              <strong>{{ student.student_name }}</strong>
              <small>{{ student.student_number }} · {{ student.course_code || 'Course' }}</small>
            </div>
            <span class="risk-badge" :class="riskClass(student.risk_level)">{{ student.risk_label }}</span>
          </button>
          <p v-if="!students.length" class="empty">No enrolled grade records to monitor yet.</p>
        </section>

        <section v-if="selected" class="detail-panel" aria-live="polite">
          <header class="detail-head">
            <div>
              <p class="page-kicker">Student signal</p>
              <h2>{{ selected.student_name }}</h2>
              <p>{{ selected.headline }}</p>
            </div>
            <span class="risk-badge large" :class="riskClass(selected.risk_level)">{{ selected.risk_label }}</span>
          </header>

          <div class="metrics">
            <div>
              <span>Average grade</span>
              <strong>{{ selected.average_grade ?? '—' }}</strong>
            </div>
            <div>
              <span>Subjects at risk</span>
              <strong>{{ selected.at_risk_subjects }}</strong>
            </div>
            <div>
              <span>Trend</span>
              <strong>{{ selected.trend_label }}</strong>
            </div>
          </div>

          <h3>Early warnings</h3>
          <ul class="warning-list">
            <li v-for="(warning, index) in selected.warnings" :key="index">{{ warning }}</li>
          </ul>

          <h3>Subject grade monitor</h3>
          <div class="subject-table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Subject</th>
                  <th>Prelim</th>
                  <th>Midterm</th>
                  <th>Final</th>
                  <th>Risk</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="subject in selected.subjects" :key="subject.subject_code">
                  <td>
                    <strong>{{ subject.subject_code }}</strong>
                    <small>{{ subject.subject_name }}</small>
                  </td>
                  <td>{{ subject.periods.Prelim ?? '—' }}</td>
                  <td>{{ subject.periods.Midterm ?? '—' }}</td>
                  <td>{{ subject.periods.Final ?? '—' }}</td>
                  <td><span class="risk-badge" :class="riskClass(subject.risk_level)">{{ subject.risk_label }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="support-block">
            <div class="support-head">
              <h3>Help support plan</h3>
              <button type="button" class="generate-btn" :disabled="generating" @click="onGenerate">
                {{ generating ? 'Generating…' : supportPlan ? 'Refresh help support' : 'Generate help support' }}
              </button>
            </div>
            <template v-if="supportPlan">
              <p class="support-summary">{{ supportPlan.summary }}</p>
              <ol>
                <li v-for="(step, index) in supportPlan.actions" :key="index">{{ step }}</li>
              </ol>
              <p class="support-note">{{ supportPlan.prevention_note }}</p>
            </template>
            <p v-else class="empty">Generate a personalized plan to prevent failing risk.</p>
          </div>
        </section>

        <section v-else class="detail-panel">
          <h2>No student selected</h2>
          <p class="empty">Seed grades or enroll students to start AI Monitoring.</p>
          <button type="button" class="generate-btn" @click="load">Reload monitoring</button>
        </section>
      </div>
    </template>
  </div>

  <StudyPlansPanel
    v-if="activeTab === 'study-plans'"
    :student-id="isStudent ? selectedId : null"
  />

  <AdviserAlertsPanel
    v-if="activeTab === 'alerts' && canSeeAlerts"
    @open-student="openStudentFromAlert"
  />

  <AiHelpChatbot
    :student-id="selected?.student_id || null"
    :student-name="selected?.student_name || 'Student'"
    :risk-label="selected?.risk_label || ''"
    :live-configured="aiBadgeLive"
    :provider="aiStatus.provider || 'gemini'"
    @plan-from-chat="onPlanFromChat"
  />
</template>

<style scoped>
.monitor-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: 0 0 18px;
  padding: 6px;
  border: 1px solid var(--color-border);
  border-radius: 14px;
  background: var(--color-surface);
  box-shadow: var(--shadow-soft);
}

.tab-btn {
  border: 0;
  border-radius: 10px;
  background: transparent;
  color: var(--color-muted);
  font-weight: 700;
  min-height: 40px;
  padding: 0 16px;
  cursor: pointer;
}

.tab-btn.active {
  background: var(--color-dartmouth-green);
  color: #fff;
}

.banner-error,
.banner-muted {
  margin: 0 0 18px;
  padding: 12px 14px;
  border-radius: 10px;
}

.banner-error {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  background: #fef3f2;
  color: #b42318;
}

.retry-btn {
  border: 0;
  border-radius: 8px;
  background: #b42318;
  color: #fff;
  font-weight: 700;
  min-height: 34px;
  padding: 0 12px;
  cursor: pointer;
}

.banner-muted {
  background: var(--color-green-tint);
  color: var(--color-dark-spring-green);
}

.summary-row {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 22px;
}

.summary-row article {
  border: 1px solid var(--color-border);
  border-radius: 14px;
  background: var(--color-surface);
  padding: 16px;
  box-shadow: var(--shadow-soft);
}

.summary-row span {
  display: block;
  color: var(--color-muted);
  font-size: 0.82rem;
  font-weight: 700;
}

.summary-row strong {
  display: block;
  margin-top: 6px;
  font-family: var(--font-display);
  font-size: 1.8rem;
}

.workspace {
  display: grid;
  grid-template-columns: minmax(240px, 320px) 1fr;
  gap: 18px;
  align-items: start;
}

.student-list,
.detail-panel {
  border: 1px solid var(--color-border);
  border-radius: 16px;
  background: var(--color-surface);
  padding: 18px;
  box-shadow: var(--shadow-soft);
}

.student-list h2,
.detail-panel h2,
.detail-panel h3,
.support-head h3 {
  margin: 0 0 12px;
  font-family: var(--font-display);
}

.student-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  width: 100%;
  margin-bottom: 8px;
  border: 1px solid var(--color-border);
  border-radius: 12px;
  background: #fff;
  padding: 12px;
  text-align: left;
  cursor: pointer;
}

.student-card.active {
  border-color: rgba(16, 106, 46, 0.45);
  background: var(--color-green-tint);
}

.student-card strong,
.subject-table-wrap strong {
  display: block;
}

.student-card small,
.subject-table-wrap small {
  display: block;
  color: var(--color-muted);
  margin-top: 2px;
}

.risk-badge {
  display: inline-flex;
  align-items: center;
  border-radius: 999px;
  padding: 4px 10px;
  font-size: 0.75rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.risk-badge.large {
  font-size: 0.82rem;
  padding: 6px 12px;
}

.risk-high { background: #fee4e2; color: #b42318; }
.risk-moderate { background: #fef0c7; color: #b54708; }
.risk-low { background: #dcf5e5; color: #176434; }

.detail-head {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 18px;
}

.detail-head h2 {
  margin: 0 0 6px;
}

.detail-head p:last-child {
  margin: 0;
  color: var(--color-muted);
  line-height: 1.5;
}

.metrics {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
  margin-bottom: 22px;
}

.metrics div {
  border-radius: 12px;
  background: var(--color-anti-flash-white);
  padding: 12px;
}

.metrics span {
  color: var(--color-muted);
  font-size: 0.8rem;
  font-weight: 700;
}

.metrics strong {
  display: block;
  margin-top: 4px;
  font-size: 1.2rem;
}

.warning-list {
  margin: 0 0 22px;
  padding-left: 18px;
  color: var(--color-muted);
  line-height: 1.55;
}

.subject-table-wrap {
  overflow-x: auto;
  margin-bottom: 22px;
}

table {
  width: 100%;
  border-collapse: collapse;
}

th,
td {
  padding: 10px 8px;
  border-bottom: 1px solid var(--color-border);
  text-align: left;
  vertical-align: top;
}

th {
  color: var(--color-muted);
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.support-block {
  border-top: 1px solid var(--color-border);
  padding-top: 16px;
}

.support-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 10px;
}

.generate-btn {
  border: 0;
  border-radius: 8px;
  background: var(--color-dartmouth-green);
  color: #fff;
  font-weight: 700;
  min-height: 40px;
  padding: 0 14px;
  cursor: pointer;
}

.generate-btn:disabled {
  opacity: 0.7;
  cursor: wait;
}

.support-summary,
.support-note,
.empty {
  color: var(--color-muted);
  line-height: 1.55;
}

.support-block ol {
  margin: 10px 0;
  padding-left: 18px;
  line-height: 1.55;
}

@media (max-width: 960px) {
  .summary-row,
  .workspace,
  .metrics {
    grid-template-columns: 1fr;
  }

  .detail-head,
  .support-head {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>
