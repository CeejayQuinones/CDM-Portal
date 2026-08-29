<script setup>
import { computed, onMounted, ref } from 'vue'
import { useAuthStore } from '../../stores/authStore'
import { ROLES } from '../../config/accessControl'
import { fetchStudyPlans } from './services/monitoringApi'

const authStore = useAuthStore()
const loading = ref(true)
const error = ref('')
const payload = ref(null)
const selectedId = ref(null)

const isStudent = computed(() => authStore.currentRole === ROLES.STUDENT)
const plans = computed(() => payload.value?.plans || [])
const summary = computed(() => payload.value?.summary || { total: 0, with_focus_subjects: 0 })
const selected = computed(() => plans.value.find((plan) => plan.student_id === selectedId.value) || plans.value[0] || null)

const riskClass = (level) => ({
  high: 'risk-high',
  moderate: 'risk-moderate',
  low: 'risk-low',
}[level] || 'risk-low')

const load = async () => {
  loading.value = true
  error.value = ''
  try {
    payload.value = await fetchStudyPlans()
    selectedId.value = payload.value?.plans?.[0]?.student_id ?? null
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to load guided study plans.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="page-header">
    <p class="page-kicker">AI Monitoring</p>
    <h1 class="page-title">Guided Study Plans</h1>
    <p class="page-description">
      Auto-built weekly study plans linked to subjects with declining grades or elevated failing risk.
    </p>
  </section>

  <div v-if="error" class="banner-error" role="alert">
    <span>{{ error }}</span>
    <button type="button" class="retry-btn" @click="load">Retry</button>
  </div>
  <p v-if="loading" class="banner-muted">Building weekly study plans…</p>

  <template v-else-if="payload">
    <section class="summary-row">
      <article><span>Plans</span><strong>{{ summary.total }}</strong></article>
      <article><span>With focus subjects</span><strong>{{ summary.with_focus_subjects }}</strong></article>
    </section>

    <div class="workspace">
      <section v-if="!isStudent" class="list-panel">
        <h2>Students</h2>
        <button
          v-for="plan in plans"
          :key="plan.student_id"
          type="button"
          class="card-btn"
          :class="{ active: selected?.student_id === plan.student_id }"
          @click="selectedId = plan.student_id"
        >
          <div>
            <strong>{{ plan.student_name }}</strong>
            <small>{{ plan.student_number }}</small>
          </div>
          <span class="risk-badge" :class="riskClass(plan.risk_level)">{{ plan.risk_level }}</span>
        </button>
        <p v-if="!plans.length" class="empty">No study plans available yet.</p>
      </section>

      <section v-if="selected" class="detail-panel">
        <header>
          <div>
            <p class="page-kicker">Weekly plan</p>
            <h2>{{ selected.student_name }}</h2>
            <p>{{ selected.headline }}</p>
          </div>
        </header>

        <h3>Focus subjects</h3>
        <ul class="focus-list">
          <li v-for="subject in selected.focus_subjects" :key="subject.subject_code">
            <strong>{{ subject.subject_code }}</strong> — {{ subject.subject_name }}
            <span class="risk-badge" :class="riskClass(subject.risk_level)">avg {{ subject.average_grade ?? '—' }}</span>
          </li>
          <li v-if="!selected.focus_subjects?.length" class="empty">No declining subjects — maintenance schedule applied.</li>
        </ul>

        <h3>This week</h3>
        <div class="week-grid">
          <article v-for="day in selected.week" :key="day.day">
            <strong>{{ day.day }}</strong>
            <small>{{ day.subject_code }} · {{ day.duration_minutes }} min</small>
            <p>{{ day.focus }}</p>
          </article>
        </div>
      </section>
    </div>
  </template>
</template>

<style scoped>
.banner-error, .banner-muted { margin: 0 0 18px; padding: 12px 14px; border-radius: 10px; }
.banner-error { display:flex; justify-content:space-between; gap:12px; background:#fef3f2; color:#b42318; }
.banner-muted { background: var(--color-green-tint); color: var(--color-dark-spring-green); }
.retry-btn { border:0; border-radius:8px; background:#b42318; color:#fff; font-weight:700; min-height:34px; padding:0 12px; cursor:pointer; }
.summary-row { display:grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap:12px; margin-bottom:22px; }
.summary-row article, .list-panel, .detail-panel { border:1px solid var(--color-border); border-radius:16px; background:var(--color-surface); padding:18px; box-shadow:var(--shadow-soft); }
.summary-row span { color:var(--color-muted); font-size:.82rem; font-weight:700; }
.summary-row strong { display:block; margin-top:6px; font-family:var(--font-display); font-size:1.8rem; }
.workspace { display:grid; grid-template-columns: minmax(220px,300px) 1fr; gap:18px; }
.list-panel h2, .detail-panel h2, .detail-panel h3 { margin:0 0 12px; font-family:var(--font-display); }
.card-btn { display:flex; justify-content:space-between; gap:10px; width:100%; margin-bottom:8px; border:1px solid var(--color-border); border-radius:12px; background:#fff; padding:12px; text-align:left; cursor:pointer; }
.card-btn.active { border-color: rgba(16,106,46,.45); background: var(--color-green-tint); }
.card-btn small, .week-grid small, .empty { color: var(--color-muted); }
.risk-badge { display:inline-flex; border-radius:999px; padding:4px 10px; font-size:.72rem; font-weight:800; text-transform:uppercase; }
.risk-high { background:#fee4e2; color:#b42318; }
.risk-moderate { background:#fef0c7; color:#b54708; }
.risk-low { background:#dcf5e5; color:#176434; }
.focus-list { margin:0 0 18px; padding-left:18px; line-height:1.7; }
.week-grid { display:grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap:10px; }
.week-grid article { border-radius:12px; background: var(--color-anti-flash-white); padding:12px; }
.week-grid strong { display:block; }
.week-grid p { margin:8px 0 0; color:var(--color-muted); line-height:1.45; }
@media (max-width: 900px) { .workspace, .week-grid, .summary-row { grid-template-columns:1fr; } }
</style>
