<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import QRCode from 'qrcode'
import { isStepUpCancelled, useStepUpAuth } from '../../composables/useStepUpAuth'
import { formatEventDateTime, formatEventTime } from './eventDateTime'
import { eventErrorMessage, eventService } from './eventService'

const props = defineProps({
  event: { type: Object, required: true },
  capabilities: { type: Object, required: true },
  showSession: { type: Boolean, default: true },
  showRecords: { type: Boolean, default: true },
  actionMode: { type: String, default: 'all' },
})
const { runWithStepUp } = useStepUpAuth()
const state = ref(null), loading = ref(true), busy = ref(false), error = ref(''), qrImage = ref(''), seconds = ref(0), search = ref('')
const qrMode = ref('dynamic')
const verificationReasons = ref({})
const page = ref(1)
const editor = ref(null), editStatus = ref('present'), reason = ref('')
let pollTimer, refreshTimer, countdownTimer, expiresAt = 0

const clearQrTimers = () => { clearTimeout(refreshTimer); clearInterval(countdownTimer); refreshTimer = null; countdownTimer = null }
const methods = computed(() => state.value?.policy?.methods || props.event.attendance_policy?.methods || [])
const supports = (method) => methods.value.includes(method)
const availability = computed(() => state.value?.availability)
const canStart = computed(() => availability.value?.can_open !== false)
async function load() {
  try {
    state.value = await eventService.attendance(props.event.id, { search: search.value || undefined, per_page: 25, page: page.value })
    if (!supports('dynamic_event') && supports('static_event')) qrMode.value = 'static'
    if (state.value.session?.status !== 'open') clearQrTimers()
  } catch (e) { error.value = eventErrorMessage(e) } finally { loading.value = false }
}
async function refreshQr() {
  if (!props.capabilities.can_manage_attendance_session || state.value?.session?.status !== 'open') return
  try {
    const data = qrMode.value === 'static' ? await eventService.staticAttendanceToken(props.event.id) : await eventService.attendanceToken(props.event.id)
    qrImage.value = await QRCode.toDataURL(data.token, { width: 300, margin: 2, color: { dark: '#061b10', light: '#ffffff' }, errorCorrectionLevel: 'M' })
    clearQrTimers()
    if (data.expires_at) {
      expiresAt = new Date(data.expires_at).getTime(); seconds.value = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000))
      countdownTimer = setInterval(() => { seconds.value = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000)) }, 1000)
      refreshTimer = setTimeout(refreshQr, data.refresh_after_seconds * 1000)
    }
  } catch (e) { clearQrTimers(); error.value = `QR refresh failed. ${eventErrorMessage(e)}`; qrImage.value = ''; if (state.value?.session?.status === 'open') refreshTimer = setTimeout(refreshQr, 10000) }
}
async function selectQrMode(mode) { qrMode.value = mode; qrImage.value = ''; await refreshQr() }
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
async function applySearch() { page.value = 1; loading.value = true; await load() }
async function changePage(value) { page.value = value; loading.value = true; await load() }
async function checkout(row) {
  if (busy.value || !row.attendance) return
  busy.value = true; error.value = ''
  try { await eventService.checkoutAttendance(props.event.id, row.attendance.id); await load() }
  catch (e) { error.value = eventErrorMessage(e) } finally { busy.value = false }
}
async function reviewVerification(item, decision) {
  if (busy.value) return
  const reasonValue = verificationReasons.value[item.id]?.trim() || null
  if (decision === 'reject' && (!reasonValue || reasonValue.length < 5)) { error.value = 'Enter a rejection reason of at least 5 characters.'; return }
  busy.value = true; error.value = ''
  try { await eventService.reviewAttendanceVerification(props.event.id, item.id, decision, reasonValue); await load() }
  catch (e) { error.value = eventErrorMessage(e) } finally { busy.value = false }
}
onMounted(async () => { await load(); if (props.capabilities.can_manage_attendance_session && state.value?.session?.status === 'open') await refreshQr(); pollTimer = setInterval(() => { if (state.value?.session?.status === 'open') load() }, 7000) })
onBeforeUnmount(() => { clearInterval(pollTimer); clearQrTimers() })
</script>

<template>
  <section class="attendance-panel" aria-labelledby="attendance-title">
    <header><div><p class="event-eyebrow">Live attendance</p><h3 id="attendance-title">Attendance</h3></div><span class="event-badge" :data-status="state?.session?.status || 'closed'">{{ state?.session?.status || 'Not started' }}</span></header>
    <p v-if="error" class="event-alert error" role="alert">{{ error }}</p>
    <div v-if="loading" class="attendance-loading">Loading attendance…</div>
    <template v-else>
      <section v-if="showSession" class="attendance-availability" :data-status="availability?.code || 'loading'">
        <strong>Event: {{ event.managed_status === 'published' ? 'Published' : event.managed_status }}</strong>
        <span>Schedule: {{ formatEventDateTime(event.starts_at) }} – {{ formatEventTime(event.ends_at) }} (Philippine time)</span>
        <span>{{ availability?.message || 'Checking attendance availability…' }}</span>
      </section>
      <button v-if="showSession && !state.session && capabilities.can_manage_attendance_session" class="event-button primary" type="button" :disabled="busy || !canStart" @click="open">Start Attendance</button>
      <p v-else-if="showSession && !state.session" class="event-state compact">Attendance has not been opened by an Event Coordinator.</p>
      <div v-if="state.session || !showSession" class="attendance-layout" :class="{ 'monitor-only': !showSession || !capabilities.can_manage_attendance_session || !showRecords }">
        <section v-if="showSession && capabilities.can_manage_attendance_session" class="qr-panel">
          <template v-if="state.session.status === 'open'">
            <div v-if="supports('dynamic_event') && supports('static_event')" class="view-switch" aria-label="Event QR mode"><button type="button" :class="{ active: qrMode === 'dynamic' }" @click="selectQrMode('dynamic')">Dynamic QR</button><button type="button" :class="{ active: qrMode === 'static' }" @click="selectQrMode('static')">Static QR</button></div>
            <div v-if="qrImage" class="qr-surface"><img :src="qrImage" :alt="qrMode === 'static' ? 'Static Event attendance QR code' : 'Rotating Event attendance QR code'" /></div><div v-else class="qr-error">QR unavailable. Retrying on the next refresh.</div>
            <strong>Attendance Open · {{ qrMode === 'static' ? 'Static QR' : 'Dynamic QR' }}</strong>
            <span v-if="qrMode === 'dynamic'">Refreshes in {{ seconds }} seconds</span><span v-else>Student scans require confirmation by assigned Event personnel.</span>
            <button class="event-button danger" type="button" :disabled="busy" @click="close">Close Attendance</button>
          </template>
          <template v-else><strong>Attendance Closed</strong><span>{{ availability?.message || 'Scans are no longer accepted.' }}</span><small v-if="state.session.closed_at">Closed {{ formatEventDateTime(state.session.closed_at) }}</small></template>
        </section>
        <section v-if="state.pending_verifications?.length && capabilities.can_verify_attendance" class="attendance-verifications">
          <header><div><p class="event-eyebrow">Physical presence check</p><h4>Pending confirmations</h4></div><span class="event-badge" data-status="pending">{{ state.pending_verifications.length }}</span></header>
          <article v-for="item in state.pending_verifications" :key="item.id">
            <div><strong>{{ item.student_name }}</strong><span>{{ item.student_number }} · {{ item.action === 'check_out' ? 'Check-out' : 'Check-in' }} requested {{ new Date(item.requested_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}</span></div>
            <input v-model="verificationReasons[item.id]" type="text" maxlength="1000" placeholder="Reason required only to reject" :aria-label="`Review reason for ${item.student_name}`" />
            <div><button class="event-button secondary" type="button" :disabled="busy" @click="reviewVerification(item, 'reject')">Reject</button><button class="event-button primary" type="button" :disabled="busy" @click="reviewVerification(item, 'approve')">Confirm {{ item.action === 'check_out' ? 'check-out' : 'check-in' }}</button></div>
          </article>
        </section>
        <section v-if="showRecords" class="attendance-monitor">
          <div class="attendance-summary"><div><strong>{{ state.summary.eligible }}</strong><span>Eligible</span></div><div><strong>{{ state.summary.present }}</strong><span>Present</span></div><div><strong>{{ state.summary.late }}</strong><span>Late</span></div><div><strong>{{ state.summary.not_checked_in }}</strong><span>Not checked in</span></div></div>
          <form class="attendance-search" @submit.prevent="applySearch"><input v-model.trim="search" type="search" placeholder="Student name or number" aria-label="Search eligible Students" /><button class="event-button secondary" type="submit">Search</button></form>
          <div class="attendance-table"><table><thead><tr><th>Student</th><th>Course / Section</th><th>Check-in</th><th v-if="state.policy.mode === 'check_in_and_out'">Check-out / duration</th><th>Status</th><th>Verification</th><th></th></tr></thead><tbody><tr v-for="row in state.students.data" :key="row.student_id"><td><strong>{{ row.name }}</strong><small>{{ row.student_number }}</small></td><td>{{ row.course }} · {{ row.section || `Year ${row.year_level}` }}</td><td>{{ row.attendance?.checked_in_at ? new Date(row.attendance.checked_in_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—' }}</td><td v-if="state.policy.mode === 'check_in_and_out'"><template v-if="row.attendance?.checked_out_at">{{ new Date(row.attendance.checked_out_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}<small>{{ row.attendance.participation_minutes }} minutes · {{ row.attendance.completion_status }}</small></template><span v-else>—</span></td><td>{{ row.attendance?.status || 'Not checked in' }}</td><td>{{ row.attendance?.source || '—' }}</td><td><button v-if="row.attendance?.completion_status === 'in_progress' && capabilities.can_verify_attendance && actionMode === 'all'" type="button" :disabled="busy" @click="checkout(row)">Check out</button><button v-else-if="(!row.attendance && capabilities.can_verify_attendance && actionMode === 'all') || (row.attendance && capabilities.can_correct_attendance && ['all', 'corrections'].includes(actionMode))" type="button" @click="edit(row)">{{ row.attendance ? 'Correct' : 'Mark' }}</button></td></tr></tbody></table></div><nav v-if="state.students.last_page > 1" class="report-pagination" aria-label="Attendance pages"><button type="button" :disabled="state.students.current_page <= 1" @click="changePage(state.students.current_page - 1)">Previous</button><span>Page {{ state.students.current_page }} of {{ state.students.last_page }}</span><button type="button" :disabled="state.students.current_page >= state.students.last_page" @click="changePage(state.students.current_page + 1)">Next</button></nav>
        </section>
      </div>
    </template>
    <div v-if="editor" class="attendance-editor"><h4>{{ editor.attendance ? 'Correct Attendance' : 'Mark Attendance' }}</h4><p>{{ editor.name }} · {{ editor.student_number }}</p><p v-if="editor.attendance">Current status: <strong>{{ editor.attendance.status }}</strong></p><label><span>{{ editor.attendance ? 'New status' : 'Status' }}</span><select v-model="editStatus"><option v-for="item in ['present','late','excused','absent']" :key="item" :value="item">{{ item }}</option></select></label><label><span>Reason</span><textarea v-model.trim="reason" required minlength="5" rows="3" placeholder="Required audit reason"></textarea></label><div><button class="event-button secondary" type="button" @click="editor = null">Cancel</button><button class="event-button primary" type="button" :disabled="busy || reason.length < 5" @click="saveManual">Save</button></div></div>
  </section>
</template>
