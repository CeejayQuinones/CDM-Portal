<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { fetchAdviserAlerts, sendRiskNotification } from '../services/monitoringApi'

const emit = defineEmits(['select-student'])
const payload = ref(null)
const loading = ref(true)
const error = ref('')
const composer = reactive({ studentId: null, message: '', sending: false, feedback: '', error: '' })

const alerts = computed(() => payload.value?.alerts || [])
const summary = computed(() => payload.value?.summary || {})

const load = async () => {
  loading.value = true
  error.value = ''
  try {
    payload.value = await fetchAdviserAlerts()
  } catch (requestError) {
    error.value = requestError.response?.data?.message || 'Unable to load adviser alerts.'
  } finally {
    loading.value = false
  }
}

const toggleComposer = (studentId) => {
  composer.studentId = composer.studentId === studentId ? null : studentId
  composer.message = ''
  composer.feedback = ''
  composer.error = ''
}

const sendNotice = async (alert) => {
  if (composer.sending) return
  composer.sending = true
  composer.feedback = ''
  composer.error = ''
  try {
    const notice = await sendRiskNotification(alert.student_id, composer.message)
    composer.feedback = notice.duplicate
      ? 'An identical recent notice already exists.'
      : 'Academic support notice sent.'
    composer.message = ''
  } catch (requestError) {
    composer.error = requestError.response?.data?.message || 'Unable to send this notice.'
  } finally {
    composer.sending = false
  }
}

onMounted(load)
</script>

<template>
  <section class="alerts-panel">
    <header class="alerts-header">
      <div>
        <p class="kicker">Computed from Published results</p>
        <h2>Adviser Alerts</h2>
        <p>Current academic-risk signals that may need staff review.</p>
      </div>
      <button type="button" :disabled="loading" @click="load">{{ loading ? 'Refreshing…' : 'Refresh' }}</button>
    </header>

    <div class="alert-summary" aria-label="Adviser alert summary">
      <article data-level="high"><span>Urgent</span><strong>{{ summary.urgent || 0 }}</strong></article>
      <article data-level="moderate"><span>Needs attention</span><strong>{{ summary.attention || 0 }}</strong></article>
      <article><span>Total alerts</span><strong>{{ summary.total || alerts.length }}</strong></article>
    </div>

    <div v-if="error" class="panel-state error" role="alert">
      <span>{{ error }}</span><button type="button" @click="load">Retry</button>
    </div>
    <p v-if="loading" class="panel-state" role="status">Reviewing current published academic signals…</p>

    <div v-else-if="alerts.length" class="alert-list">
      <article v-for="alert in alerts" :key="alert.student_id" class="alert-card">
        <header>
          <div>
            <span class="risk-pill" :data-level="alert.risk_level">{{ alert.risk_label }}</span>
            <h3>{{ alert.student_name }}</h3>
            <p>{{ alert.student_number }} · {{ alert.course_code || 'Course unavailable' }} · {{ alert.section || 'Section unavailable' }}</p>
          </div>
          <div class="alert-actions">
            <button type="button" @click="emit('select-student', alert.student_id)">Open Student</button>
            <button type="button" class="primary" @click="toggleComposer(alert.student_id)">
              {{ composer.studentId === alert.student_id ? 'Close Notice' : 'Send Notice' }}
            </button>
          </div>
        </header>

        <div class="alert-body">
          <div><span>Academic trend</span><strong>{{ alert.trend_label }}</strong></div>
          <div><span>Published Subjects</span><strong>{{ alert.published_subjects_analyzed }}</strong></div>
          <div><span>Subjects at risk</span><strong>{{ alert.at_risk_subjects }}</strong></div>
        </div>
        <h4>{{ alert.title }}</h4>
        <p class="alert-message">{{ alert.message }}</p>
        <ul><li v-for="reason in alert.risk_reasons" :key="reason">{{ reason }}</li></ul>

        <form v-if="composer.studentId === alert.student_id" class="notice-form" @submit.prevent="sendNotice(alert)">
          <label>
            Optional personal message
            <textarea v-model="composer.message" maxlength="1000" rows="3" placeholder="Leave blank to use the risk-appropriate support message."></textarea>
          </label>
          <div class="notice-footer">
            <p v-if="composer.feedback" class="success" role="status">{{ composer.feedback }}</p>
            <p v-else-if="composer.error" class="error-text" role="alert">{{ composer.error }}</p>
            <button class="primary" type="submit" :disabled="composer.sending">
              {{ composer.sending ? 'Sending…' : 'Send Academic Support Notice' }}
            </button>
          </div>
        </form>
      </article>
    </div>

    <div v-else-if="!error" class="panel-state empty">
      <h2>No current alerts</h2>
      <p>No current academic-risk alerts based on Published results.</p>
    </div>
  </section>
</template>

<style scoped>
.alerts-panel { color: var(--text-primary); }
.alerts-header, .alert-card > header, .alert-actions, .notice-footer { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.alerts-header { margin-bottom: 18px; }
.alerts-header h2 { margin: 4px 0 6px; }
.alerts-header p, .alert-card header p, .alert-message { margin: 0; color: var(--text-secondary); }
.kicker { color: var(--accent) !important; font-size: .78rem; font-weight: 800; letter-spacing: .07em; text-transform: uppercase; }
button { min-height: 40px; border: 1px solid var(--accent); border-radius: 8px; padding: 8px 13px; background: var(--bg-surface); color: var(--accent); font: inherit; font-weight: 700; cursor: pointer; }
button.primary { background: var(--accent); color: var(--text-on-accent); }
button:disabled { cursor: wait; opacity: .62; }
.alert-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-bottom: 16px; }
.alert-summary article { border: 1px solid var(--border-color); border-left: 4px solid var(--text-muted); border-radius: 10px; padding: 13px 15px; background: var(--bg-surface); box-shadow: var(--shadow-soft); }
.alert-summary article[data-level='high'] { border-left-color: var(--danger); }
.alert-summary article[data-level='moderate'] { border-left-color: var(--warning); }
.alert-summary span { color: var(--text-secondary); font-size: .8rem; font-weight: 700; }
.alert-summary strong { display: block; margin-top: 4px; font-size: 1.55rem; }
.alert-list { display: grid; gap: 12px; }
.alert-card { border: 1px solid var(--border-color); border-radius: 14px; padding: clamp(16px, 2vw, 22px); background: var(--bg-surface); box-shadow: var(--shadow-soft); }
.alert-card h3 { margin: 8px 0 4px; }
.alert-actions { align-items: center; }
.alert-body { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 9px; margin: 16px 0; }
.alert-body div { border: 1px solid var(--border-color); border-radius: 9px; padding: 11px; background: var(--bg-surface-alt); }
.alert-body span { color: var(--text-muted); font-size: .78rem; }
.alert-body strong { display: block; margin-top: 4px; }
.alert-card h4 { margin: 14px 0 5px; }
.alert-card ul { margin: 12px 0 0; padding-left: 20px; color: var(--text-secondary); }
.alert-card li + li { margin-top: 5px; }
.risk-pill { display: inline-flex; border-radius: 999px; padding: 4px 9px; background: var(--bg-surface-alt); color: var(--text-secondary); font-size: .74rem; font-weight: 800; text-transform: capitalize; }
.risk-pill[data-level='high'] { background: var(--danger-bg); color: var(--danger); }
.risk-pill[data-level='moderate'] { background: var(--warning-bg); color: var(--warning); }
.notice-form { margin-top: 18px; border-top: 1px solid var(--border-color); padding-top: 16px; }
.notice-form label { display: grid; gap: 7px; color: var(--text-secondary); font-size: .82rem; font-weight: 700; }
.notice-form textarea { width: 100%; resize: vertical; border: 1px solid var(--border-color); border-radius: 8px; padding: 10px; background: var(--bg-input); color: var(--text-primary); font: inherit; }
.notice-footer { align-items: center; margin-top: 10px; }
.notice-footer p { margin: 0; }
.success { color: var(--success); }
.error-text { color: var(--danger); }
.panel-state { display: flex; justify-content: space-between; gap: 12px; border: 1px solid var(--border-color); border-radius: 10px; padding: 18px; background: var(--bg-surface); color: var(--text-secondary); }
.panel-state.error { border-color: var(--danger); background: var(--danger-bg); color: var(--danger); }
.panel-state.empty { display: block; padding: 36px 20px; text-align: center; }
.panel-state.empty h2 { margin-top: 0; color: var(--text-primary); }
@media (max-width: 760px) { .alerts-header, .alert-card > header, .alert-actions, .notice-footer { display: grid; } .alert-summary, .alert-body { grid-template-columns: 1fr; } .alert-actions, .alert-actions button, .notice-footer button, .alerts-header > button { width: 100%; } }
</style>
