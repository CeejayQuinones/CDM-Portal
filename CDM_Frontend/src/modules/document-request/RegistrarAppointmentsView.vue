<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useAppointmentAvailabilityState } from './appointmentAvailabilityState'
import { documentRequestService as api } from './documentRequestService'

const appointments = ref([]), loading = ref(false), error = ref(''), message = ref('')
const code = ref(''), verifying = ref(false), verifiedRequest = ref(null), finalizing = ref(false), cancellationReason = ref('')
const resendingId = ref(null)
const { isOpen: availabilityOpen, close: closeAvailability } = useAppointmentAvailabilityState()
const month = ref(new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', timeZone: 'Asia/Manila' }).format(new Date()))
const calendar = ref(null), calendarLoading = ref(false), capacityDraft = ref({})
const today = () => new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: 'Asia/Manila' }).format(new Date())
const requestError = (err) => Object.values(err.response?.data?.errors || {}).flat()[0] || err.response?.data?.message || 'The request could not be completed.'
const formatDate = (value) => value ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'long', timeZone: 'Asia/Manila' }).format(new Date(`${String(value).slice(0, 10)}T00:00:00+08:00`)) : 'Not assigned'
const formatStatus = (value) => String(value || '').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
const profileOf = (item) => item?.student?.user?.profile || item?.student?.user_profile
const studentName = (item) => [profileOf(item)?.first_name, profileOf(item)?.last_name].filter(Boolean).join(' ') || 'Student'
const documentName = (item) => item?.document_request?.document_type?.document_name || item?.document_type?.document_name || 'Document'
const calendarDays = computed(() => calendar.value?.days || [])

async function refresh() {
  loading.value = true; error.value = ''
  try { const result = await api.registrarAppointments({ group: 'today', date: today(), page: 1 }); appointments.value = result.data || result }
  catch (err) { error.value = requestError(err) } finally { loading.value = false }
}
async function verify() {
  if (!/^\d{6}$/.test(code.value)) { error.value = 'Enter exactly 6 digits.'; return }
  verifying.value = true; error.value = ''
  try { verifiedRequest.value = await api.verifyCode(code.value); message.value = 'Code verified. Complete or cancel this request below.'; code.value = '' }
  catch (err) { verifiedRequest.value = null; error.value = requestError(err) } finally { verifying.value = false }
}
async function finalize(action) {
  if (action === 'cancel' && !cancellationReason.value.trim()) { error.value = 'Enter a cancellation reason.'; return }
  finalizing.value = true; error.value = ''
  try {
    await api.updateRequest(verifiedRequest.value.id, { action, reason: action === 'cancel' ? cancellationReason.value : undefined })
    appointments.value = appointments.value.filter((item) => item.document_request_id !== verifiedRequest.value.id)
    message.value = action === 'complete' ? 'Request completed and moved to History.' : 'Request cancelled and moved to History.'
    verifiedRequest.value = null; cancellationReason.value = ''
  } catch (err) { error.value = requestError(err) } finally { finalizing.value = false }
}
async function resend(appointment) {
  const request = appointment.document_request
  if (!request || resendingId.value) return
  resendingId.value = request.id; error.value = ''
  try {
    const updated = await api.resendClaimCode(request.id)
    message.value = updated._demo_claim_code
      ? `DEMO ONLY — new claim code: ${updated._demo_claim_code}. No email is sent in offline demo mode.`
      : 'A new claim code was sent to the student.'
  } catch (err) { error.value = requestError(err) } finally { resendingId.value = null }
}
async function loadCalendar() {
  calendarLoading.value = true
  try { calendar.value = await api.registrarCalendar(month.value); capacityDraft.value = Object.fromEntries(calendarDays.value.map((day) => [day.date, day.capacity])) }
  catch (err) { error.value = requestError(err) } finally { calendarLoading.value = false }
}
async function saveCapacity(day) {
  try { await api.updateDateCapacity(day.date, Number(capacityDraft.value[day.date])); message.value = `Capacity for ${formatDate(day.date)} updated.`; await loadCalendar() }
  catch (err) { error.value = requestError(err) }
}
function changeMonth(offset) {
  const [year, monthNumber] = month.value.split('-').map(Number), next = new Date(year, monthNumber - 1 + offset, 1)
  month.value = `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`
}
watch(availabilityOpen, async (open) => { if (open) { await nextTick(); await loadCalendar() } })
watch(month, () => { if (availabilityOpen.value) loadCalendar() })
onMounted(refresh)
</script>

<template>
  <section class="page-header"><p class="page-kicker">Registrar Staff</p><h1 class="page-title">Today's Appointments</h1><p class="page-description">Verify the student's claim code before completing or cancelling a document release.</p></section>
  <Transition name="toast"><p v-if="message" class="notice success" role="status">{{ message }}</p></Transition>
  <p v-if="error" class="notice error" role="alert">{{ error }}</p>

  <section class="dr-panel appointment-filter-panel verification-panel" :class="{ 'is-verified': verifiedRequest }">
    <header><p class="page-kicker">Verify request code</p><h2>Claim Verification</h2></header>
    <form class="toolbar" @submit.prevent="verify"><input v-model="code" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code" aria-label="Six-digit verification code" placeholder="6-digit code" /><button :disabled="verifying || !/^\d{6}$/.test(code)">{{ verifying ? 'Verifying…' : 'Verify' }}</button></form>
    <Transition name="verified-details"><article v-if="verifiedRequest" class="request-detail-section verified-request-details">
      <h3>{{ verifiedRequest.request_reference }} · {{ documentName(verifiedRequest) }}</h3><p>{{ studentName(verifiedRequest) }} · {{ formatDate(verifiedRequest.active_appointment?.appointment_date) }}</p>
      <textarea v-model="cancellationReason" placeholder="Cancellation reason (required only for Cancel)"></textarea>
      <div class="appointment-details-footer"><button class="danger-action" :disabled="finalizing" @click="finalize('cancel')">Cancel</button><button class="primary-action" :disabled="finalizing" @click="finalize('complete')">{{ finalizing ? 'Saving…' : 'Complete' }}</button></div>
    </article></Transition>
  </section>

  <section class="dr-panel appointment-main-table" aria-live="polite">
    <header class="release-queue-header"><div><p class="page-kicker">{{ formatDate(today()) }}</p><h2>Scheduled Today</h2></div><strong class="work-queue-count">{{ appointments.length }}</strong></header>
    <p v-if="loading" class="empty">Loading today's appointments…</p><p v-else-if="!appointments.length" class="empty">No approved appointments scheduled today.</p>
    <TransitionGroup v-else name="appointment-row" tag="div" class="work-queue-list"><article v-for="appointment in appointments" :key="appointment.id" class="release-request-row">
      <div class="release-request-summary"><strong>{{ studentName(appointment) }}</strong><span>{{ appointment.student?.student_number }}</span></div><div><strong>{{ documentName(appointment) }}</strong><small>{{ formatDate(appointment.appointment_date) }}</small></div>
      <span><span class="badge" :class="appointment.document_request?.code_verified_at ? 'approved' : appointment.status">{{ appointment.document_request?.code_verified_at ? 'Verified' : formatStatus(appointment.status) }}</span><button v-if="!appointment.document_request?.code_verified_at" class="secondary-action" :disabled="resendingId === appointment.document_request_id" @click="resend(appointment)">{{ resendingId === appointment.document_request_id ? 'Sending…' : 'Resend Claim Code' }}</button></span>
    </article></TransitionGroup>
  </section>

  <Teleport to="body"><Transition name="modal"><div v-if="availabilityOpen" class="appointment-details-backdrop" @click.self="closeAvailability"><section class="appointment-details-modal" role="dialog" aria-modal="true" aria-labelledby="capacity-title">
    <header class="appointment-details-header"><div><p class="page-kicker">Availability</p><h2 id="capacity-title">Daily Appointment Capacity</h2></div><button aria-label="Close" @click="closeAvailability">×</button></header>
    <div class="appointment-details-body"><nav class="calendar-navigation"><button @click="changeMonth(-1)">Previous</button><strong>{{ month }}</strong><button @click="changeMonth(1)">Next</button></nav><p v-if="calendarLoading">Loading calendar…</p>
      <div v-else class="appointment-calendar-grid"><article v-for="day in calendarDays" :key="day.date" class="appointment-calendar-day" :class="{ selected: day.date === today(), unavailable: day.booked >= day.capacity }"><strong>{{ Number(day.date.slice(-2)) }}</strong><span>{{ day.booked }} / {{ day.capacity }}</span><div><input v-model.number="capacityDraft[day.date]" type="number" min="1" max="100" :aria-label="`Capacity for ${day.date}`" /><button @click="saveCapacity(day)">Save</button></div></article></div>
    </div></section></div></Transition></Teleport>
</template>

<style scoped src="./documentRequest.css"></style>
<style scoped>
.verification-panel{transition:border-color .22s ease,background-color .22s ease}.verification-panel.is-verified{border-color:rgba(38,166,91,.65)}
.verified-details-enter-active,.verified-details-leave-active{transition:opacity .24s ease,transform .24s ease}.verified-details-enter-from,.verified-details-leave-to{opacity:0;transform:translateY(8px)}
.appointment-row-enter-active,.appointment-row-leave-active{transition:opacity .28s ease,transform .28s ease}.appointment-row-leave-to{opacity:0;transform:translateY(-6px)}.modal-enter-active,.modal-leave-active{transition:opacity .2s ease}.modal-enter-from,.modal-leave-to{opacity:0}.appointment-calendar-day input{width:4rem}
@media(prefers-reduced-motion:reduce){*{transition-duration:.01ms!important;scroll-behavior:auto!important}}
</style>
