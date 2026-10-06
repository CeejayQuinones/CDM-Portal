<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { fetchMyRiskNotifications, markRiskNotificationRead } from '../services/monitoringApi'

const emit = defineEmits(['open-monitoring'])
const router = useRouter()
const notices = ref([])
const unread = ref(0)
const expandedId = ref(null)
const loading = ref(true)
const error = ref('')
const readingId = ref(null)

const load = async () => {
  loading.value = true
  error.value = ''
  try {
    const data = await fetchMyRiskNotifications()
    notices.value = data.notifications || []
    unread.value = data.unread || 0
  } catch (requestError) {
    error.value = requestError.response?.data?.message || 'Unable to load academic support notices.'
  } finally {
    loading.value = false
  }
}

const openNotice = async (notice) => {
  expandedId.value = expandedId.value === notice.id ? null : notice.id
  if (notice.is_read || readingId.value === notice.id) return
  readingId.value = notice.id
  try {
    const updated = await markRiskNotificationRead(notice.id)
    Object.assign(notice, updated)
    unread.value = Math.max(0, unread.value - 1)
  } catch (requestError) {
    error.value = requestError.response?.data?.message || 'Unable to mark this notice as read.'
  } finally {
    readingId.value = null
  }
}

const formatDate = (value) => value
  ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
  : 'Date unavailable'

onMounted(load)
</script>

<template>
  <section class="notices-panel">
    <header class="notices-header">
      <div>
        <p class="kicker">Academic support</p>
        <h2>My Notices <span v-if="unread" class="unread-count">{{ unread }} unread</span></h2>
        <p>Messages sent by your Professor or academic staff based on official Published results.</p>
      </div>
      <button type="button" :disabled="loading" @click="load">{{ loading ? 'Refreshing…' : 'Refresh' }}</button>
    </header>

    <div v-if="error" class="notice-state error" role="alert"><span>{{ error }}</span><button type="button" @click="load">Retry</button></div>
    <p v-if="loading" class="notice-state" role="status">Loading your academic support notices…</p>

    <div v-else-if="notices.length" class="notice-list">
      <article v-for="notice in notices" :key="notice.id" :class="['notice-card', { unread: !notice.is_read }]">
        <button class="notice-toggle" type="button" :aria-expanded="expandedId === notice.id" @click="openNotice(notice)">
          <span class="notice-copy">
            <span class="notice-meta">
              <em class="risk-pill" :data-level="notice.risk_level">{{ notice.risk_level }}</em>
              <strong v-if="!notice.is_read" class="new-label">Unread</strong>
              <span>{{ formatDate(notice.created_at) }}</span>
            </span>
            <strong class="notice-title">{{ notice.title }}</strong>
            <span>From {{ notice.sender?.name || 'CDM Staff' }} · {{ notice.sender?.role || 'Staff' }}</span>
          </span>
          <span>{{ readingId === notice.id ? 'Opening…' : expandedId === notice.id ? 'Close' : 'Open' }}</span>
        </button>

        <div v-if="expandedId === notice.id" class="notice-detail">
          <p>{{ notice.message }}</p>
          <div class="notice-actions">
            <button type="button" @click="emit('open-monitoring')">Open Monitoring</button>
            <button type="button" @click="router.push(notice.links?.grades || '/grading')">View Published Grades</button>
          </div>
        </div>
      </article>
    </div>

    <div v-else-if="!error" class="notice-state empty">
      <h2>No notices</h2>
      <p>You have no academic support notices.</p>
    </div>
  </section>
</template>

<style scoped>
.notices-panel { color: var(--text-primary); }
.notices-header, .notice-state, .notice-meta, .notice-actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.notices-header { align-items: flex-start; margin-bottom: 18px; }
.notices-header h2 { margin: 4px 0 6px; }
.notices-header p, .notice-copy > span:last-child, .notice-detail p { margin: 0; color: var(--text-secondary); }
.kicker { color: var(--accent) !important; font-size: .78rem; font-weight: 800; letter-spacing: .07em; text-transform: uppercase; }
.unread-count { display: inline-flex; vertical-align: middle; border-radius: 999px; padding: 4px 9px; background: var(--accent-soft); color: var(--accent); font-size: .72rem; }
button { min-height: 40px; border: 1px solid var(--accent); border-radius: 8px; padding: 8px 13px; background: var(--bg-surface); color: var(--accent); font: inherit; font-weight: 700; cursor: pointer; }
button:disabled { cursor: wait; opacity: .62; }
.notice-list { display: grid; gap: 10px; }
.notice-card { overflow: hidden; border: 1px solid var(--border-color); border-radius: 12px; background: var(--bg-surface); box-shadow: var(--shadow-soft); }
.notice-card.unread { border-left: 4px solid var(--accent); }
.notice-toggle { display: flex; align-items: center; justify-content: space-between; gap: 18px; width: 100%; min-height: 0; border: 0; border-radius: 0; padding: 16px; color: var(--text-primary); text-align: left; }
.notice-toggle:hover { background: var(--bg-surface-alt); }
.notice-copy { display: grid; gap: 5px; }
.notice-meta { justify-content: flex-start; flex-wrap: wrap; color: var(--text-muted); font-size: .78rem; }
.notice-title { font-size: 1.04rem; }
.new-label { color: var(--accent); }
.risk-pill { border-radius: 999px; padding: 3px 8px; background: var(--bg-surface-alt); color: var(--text-secondary); font-size: .7rem; font-style: normal; font-weight: 800; text-transform: capitalize; }
.risk-pill[data-level='high'] { background: var(--danger-bg); color: var(--danger); }
.risk-pill[data-level='moderate'] { background: var(--warning-bg); color: var(--warning); }
.risk-pill[data-level='stable'] { background: var(--success-bg); color: var(--success); }
.notice-detail { border-top: 1px solid var(--border-color); padding: 16px; background: var(--bg-surface-alt); }
.notice-detail p { white-space: pre-wrap; }
.notice-actions { justify-content: flex-start; margin-top: 14px; }
.notice-state { border: 1px solid var(--border-color); border-radius: 10px; padding: 18px; background: var(--bg-surface); color: var(--text-secondary); }
.notice-state.error { border-color: var(--danger); background: var(--danger-bg); color: var(--danger); }
.notice-state.empty { display: block; padding: 36px 20px; text-align: center; }
.notice-state.empty h2 { margin-top: 0; color: var(--text-primary); }
@media (max-width: 680px) { .notices-header, .notice-toggle, .notice-actions { display: grid; } .notices-header > button, .notice-actions button { width: 100%; } }
</style>
