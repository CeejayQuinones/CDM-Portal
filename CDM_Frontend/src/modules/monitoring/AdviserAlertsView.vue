<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { fetchAdviserAlerts } from './services/monitoringApi'

const READ_KEY = 'cdm_adviser_alert_reads'
const router = useRouter()
const loading = ref(true)
const error = ref('')
const payload = ref(null)
const selectedId = ref(null)
const readIds = ref(new Set(JSON.parse(localStorage.getItem(READ_KEY) || '[]')))

const alerts = computed(() => payload.value?.alerts || [])
const summary = computed(() => payload.value?.summary || { urgent: 0, attention: 0, total: 0 })
const selected = computed(() => alerts.value.find((alert) => alert.id === selectedId.value) || alerts.value[0] || null)
const unreadCount = computed(() => alerts.value.filter((alert) => !readIds.value.has(alert.id)).length)

const load = async () => {
  loading.value = true
  error.value = ''
  try {
    payload.value = await fetchAdviserAlerts()
    selectedId.value = payload.value?.alerts?.[0]?.id ?? null
    if (selected.value) markRead(selected.value.id)
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to load adviser alerts.'
  } finally {
    loading.value = false
  }
}

const persistReads = () => {
  localStorage.setItem(READ_KEY, JSON.stringify([...readIds.value]))
}

const markRead = (id) => {
  readIds.value = new Set([...readIds.value, id])
  persistReads()
}

const selectAlert = (id) => {
  selectedId.value = id
  markRead(id)
}

const openMonitoring = () => {
  router.push({ name: 'monitoring' })
}

onMounted(load)
</script>

<template>
  <section class="page-header">
    <p class="page-kicker">AI Monitoring</p>
    <h1 class="page-title">Adviser Alert Inbox</h1>
    <p class="page-description">
      Early-warning notices for professors and registrar advisers — prioritized by risk of failing.
    </p>
  </section>

  <div v-if="error" class="banner-error" role="alert">
    <span>{{ error }}</span>
    <button type="button" class="retry-btn" @click="load">Retry</button>
  </div>
  <p v-if="loading" class="banner-muted">Collecting adviser alerts…</p>

  <template v-else-if="payload">
    <section class="summary-row">
      <article><span>Urgent</span><strong>{{ summary.urgent }}</strong></article>
      <article><span>Attention</span><strong>{{ summary.attention }}</strong></article>
      <article><span>Unread</span><strong>{{ unreadCount }}</strong></article>
      <article><span>Total</span><strong>{{ summary.total }}</strong></article>
    </section>

    <div class="workspace">
      <section class="list-panel">
        <h2>Inbox</h2>
        <button
          v-for="alert in alerts"
          :key="alert.id"
          type="button"
          class="card-btn"
          :class="{ active: selected?.id === alert.id, unread: !readIds.has(alert.id) }"
          @click="selectAlert(alert.id)"
        >
          <div>
            <strong>{{ alert.title }}</strong>
            <small>{{ alert.student_number }} · {{ alert.course_code || 'Course' }}</small>
          </div>
          <span class="sev" :class="alert.severity">{{ alert.severity }}</span>
        </button>
        <p v-if="!alerts.length" class="empty">No adviser alerts right now. Students look stable.</p>
      </section>

      <section v-if="selected" class="detail-panel">
        <header>
          <p class="page-kicker">{{ selected.severity }} alert</p>
          <h2>{{ selected.student_name }}</h2>
          <p>{{ selected.message }}</p>
        </header>

        <div class="metrics">
          <div><span>Risk</span><strong>{{ selected.risk_label }}</strong></div>
          <div><span>Average</span><strong>{{ selected.average_grade ?? '—' }}</strong></div>
          <div><span>Trend</span><strong>{{ selected.trend_label }}</strong></div>
        </div>

        <h3>Warnings</h3>
        <ul>
          <li v-for="(warning, index) in selected.warnings" :key="index">{{ warning }}</li>
        </ul>

        <button type="button" class="cta" @click="openMonitoring">Open in AI Monitoring</button>
      </section>
    </div>
  </template>
</template>

<style scoped>
.banner-error, .banner-muted { margin:0 0 18px; padding:12px 14px; border-radius:10px; }
.banner-error { display:flex; justify-content:space-between; gap:12px; background:#fef3f2; color:#b42318; }
.banner-muted { background: var(--color-green-tint); color: var(--color-dark-spring-green); }
.retry-btn { border:0; border-radius:8px; background:#b42318; color:#fff; font-weight:700; min-height:34px; padding:0 12px; cursor:pointer; }
.summary-row { display:grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap:12px; margin-bottom:22px; }
.summary-row article, .list-panel, .detail-panel { border:1px solid var(--color-border); border-radius:16px; background:var(--color-surface); padding:18px; box-shadow:var(--shadow-soft); }
.summary-row span { color:var(--color-muted); font-size:.82rem; font-weight:700; }
.summary-row strong { display:block; margin-top:6px; font-family:var(--font-display); font-size:1.8rem; }
.workspace { display:grid; grid-template-columns: minmax(240px,320px) 1fr; gap:18px; }
.list-panel h2, .detail-panel h2, .detail-panel h3 { margin:0 0 12px; font-family:var(--font-display); }
.card-btn { display:flex; justify-content:space-between; gap:10px; width:100%; margin-bottom:8px; border:1px solid var(--color-border); border-radius:12px; background:#fff; padding:12px; text-align:left; cursor:pointer; }
.card-btn.active { border-color: rgba(16,106,46,.45); background: var(--color-green-tint); }
.card-btn.unread strong { font-weight:800; }
.card-btn small, .empty { color: var(--color-muted); }
.sev { border-radius:999px; padding:4px 10px; font-size:.72rem; font-weight:800; text-transform:uppercase; }
.sev.urgent { background:#fee4e2; color:#b42318; }
.sev.attention { background:#fef0c7; color:#b54708; }
.metrics { display:grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap:10px; margin:16px 0; }
.metrics div { border-radius:12px; background:var(--color-anti-flash-white); padding:12px; }
.metrics span { color:var(--color-muted); font-size:.8rem; font-weight:700; }
.metrics strong { display:block; margin-top:4px; }
ul { margin:0 0 18px; padding-left:18px; color:var(--color-muted); line-height:1.55; }
.cta { border:0; border-radius:8px; background:var(--color-dartmouth-green); color:#fff; font-weight:700; min-height:42px; padding:0 16px; cursor:pointer; }
@media (max-width: 960px) { .summary-row, .workspace, .metrics { grid-template-columns:1fr; } }
</style>
