<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import QRCode from 'qrcode'
import { isStepUpCancelled, useStepUpAuth } from '../../composables/useStepUpAuth'
import { eventErrorMessage, eventService } from './eventService'

const props = defineProps({ event: { type: Object, required: true } })
const { runWithStepUp } = useStepUpAuth()
const state = ref(null), loading = ref(true), busy = ref(false), error = ref(''), qrImage = ref(''), seconds = ref(0), search = ref('')
const editor = ref(null), editStatus = ref('present'), reason = ref('')
let pollTimer, refreshTimer, countdownTimer, expiresAt = 0

const clearQrTimers = () => { clearTimeout(refreshTimer); clearInterval(countdownTimer); refreshTimer = null; countdownTimer = null }
async function load() { try { state.value = await eventService.attendance(props.event.id, { search: search.value || undefined, per_page: 100 }); if (state.value.session?.status !== 'open') clearQrTimers() } catch (e) { error.value = eventErrorMessage(e) } finally { loading.value = false } }
async function refreshQr() {
  if (state.value?.session?.status !== 'open') return
  try {
    const data = await eventService.attendanceToken(props.event.id)
    qrImage.value = await QRCode.toDataURL(data.token, { width: 300, margin: 2, color: { dark: '#061b10', light: '#ffffff' }, errorCorrectionLevel: 'M' })
    expiresAt = new Date(data.expires_at).getTime(); seconds.value = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000))
    clearQrTimers()
    countdownTimer = setInterval(() => { seconds.value = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000)) }, 1000)
    refreshTimer = setTimeout(refreshQr, data.refresh_after_seconds * 1000)
  } catch (e) { clearQrTimers(); error.value = `QR refresh failed. ${eventErrorMessage(e)}`; qrImage.value = ''; if (state.value?.session?.status === 'open') refreshTimer = setTimeout(refreshQr, 10000) }
}
async function open() { if (busy.value) return; busy.value = true; error.value = ''; try { await eventService.openAttendance(props.event.id); await load(); await refreshQr() } catch (e) { error.value = eventErrorMessage(e) } finally { busy.value = false } }
async function close() { if (busy.value) return; busy.value = true; error.value = ''; try { await eventService.closeAttendance(props.event.id, state.value.session.version); clearQrTimers(); qrImage.value = ''; await load() } catch (e) { error.value = eventErrorMessage(e) } finally { busy.value = false } }
function edit(row) { editor.value = row; editStatus.value = row.attendance?.status || 'present'; reason.value = '' }
async function saveManual() {
  if (busy.value || !editor.value) return
  busy.value = true; error.value = ''
  try {
    if (editor.value.attendance) {
      await runWithStepUp(() => eventService.correctAttendance(props.event.id, editor.value.attendance.id, { status: editStatus.value, reason: reason.value, version: editor.value.attendance.version }))
    } else {
      await eventService.manualAttendance(props.event.id, { student_id: editor.value.student_id, status: editStatus.value, reason: reason.value })
    }
    editor.value = null; await load()
  } catch (e) { if (!isStepUpCancelled(e)) error.value = eventErrorMessage(e) } finally { busy.value = false }
}
async function applySearch() { loading.value = true; await load() }
onMounted(async () => { await load(); if (state.value?.session?.status === 'open') await refreshQr(); pollTimer = setInterval(() => { if (state.value?.session?.status === 'open') load() }, 7000) })
onBeforeUnmount(() => { clearInterval(pollTimer); clearQrTimers() })
</script>

<template>
  <section class="attendance-panel" aria-labelledby="attendance-title">
    <header><div><p class="event-eyebrow">Live attendance</p><h3 id="attendance-title">Attendance</h3></div><span class="event-badge" :data-status="state?.session?.status || 'closed'">{{ state?.session?.status || 'Not started' }}</span></header>
    <p v-if="error" class="event-alert error" role="alert">{{ error }}</p>
    <div v-if="loading" class="attendance-loading">Loading attendance…</div>
    <template v-else>
      <button v-if="!state.session" class="event-button primary" type="button" :disabled="busy" @click="open">Start Attendance</button>
      <div v-else class="attendance-layout">
        <section class="qr-panel">
          <template v-if="state.session.status === 'open'"><div v-if="qrImage" class="qr-surface"><img :src="qrImage" alt="Rotating Event attendance QR code" /></div><div v-else class="qr-error">QR unavailable. Retrying on the next refresh.</div><strong>Attendance Open</strong><span>Refreshes in {{ seconds }} seconds</span><button class="event-button danger" type="button" :disabled="busy" @click="close">Close Attendance</button></template>
          <template v-else><strong>Attendance Closed</strong><span>Scans are no longer accepted.</span></template>
        </section>
        <section class="attendance-monitor">
          <div class="attendance-summary"><div><strong>{{ state.summary.eligible }}</strong><span>Eligible</span></div><div><strong>{{ state.summary.present }}</strong><span>Present</span></div><div><strong>{{ state.summary.late }}</strong><span>Late</span></div><div><strong>{{ state.summary.not_checked_in }}</strong><span>Not checked in</span></div></div>
          <form class="attendance-search" @submit.prevent="applySearch"><input v-model.trim="search" type="search" placeholder="Student name or number" aria-label="Search eligible Students" /><button class="event-button secondary" type="submit">Search</button></form>
          <div class="attendance-table"><table><thead><tr><th>Student</th><th>Course / Section</th><th>Check-in</th><th>Status</th><th>Source</th><th></th></tr></thead><tbody><tr v-for="row in state.students.data" :key="row.student_id"><td><strong>{{ row.name }}</strong><small>{{ row.student_number }}</small></td><td>{{ row.course }} · {{ row.section || `Year ${row.year_level}` }}</td><td>{{ row.attendance?.checked_in_at ? new Date(row.attendance.checked_in_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—' }}</td><td>{{ row.attendance?.status || 'Not checked in' }}</td><td>{{ row.attendance?.source || '—' }}</td><td><button type="button" @click="edit(row)">{{ row.attendance ? 'Correct' : 'Mark' }}</button></td></tr></tbody></table></div>
        </section>
      </div>
    </template>
    <div v-if="editor" class="attendance-editor"><h4>{{ editor.attendance ? 'Correct Attendance' : 'Mark Attendance' }}</h4><p>{{ editor.name }} · {{ editor.student_number }}</p><p v-if="editor.attendance">Current status: <strong>{{ editor.attendance.status }}</strong></p><label><span>{{ editor.attendance ? 'New status' : 'Status' }}</span><select v-model="editStatus"><option v-for="item in ['present','late','excused','absent']" :key="item" :value="item">{{ item }}</option></select></label><label><span>Reason</span><textarea v-model.trim="reason" required minlength="5" rows="3" placeholder="Required audit reason"></textarea></label><div><button class="event-button secondary" type="button" @click="editor = null">Cancel</button><button class="event-button primary" type="button" :disabled="busy || reason.length < 5" @click="saveManual">Save</button></div></div>
  </section>
</template>
