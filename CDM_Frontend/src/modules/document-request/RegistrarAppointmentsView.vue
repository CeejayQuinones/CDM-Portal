<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useAppointmentAvailabilityState } from './appointmentAvailabilityState'
import { documentRequestService as api } from './documentRequestService'

const appointments = ref([]), loading = ref(false), error = ref(''), message = ref('')
const code = ref(''), verifying = ref(false), verifiedRequest = ref(null), finalizing = ref(false), cancellationReason = ref('')
const resendingId = ref(null)
const { isOpen: availabilityOpen, close: closeAvailability } = useAppointmentAvailabilityState()
const month = ref(new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', timeZone: 'Asia/Manila' }).format(new Date()))
const calendar = ref(null), calendarLoading = ref(false), calendarError = ref(''), capacityDraft = ref(5), selectedDate = ref(''), savingCapacity = ref(false)
const today = () => new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: 'Asia/Manila' }).format(new Date())
const requestError = (err) => Object.values(err.response?.data?.errors || {}).flat()[0] || err.response?.data?.message || 'The request could not be completed.'
const formatDate = (value) => value ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'long', timeZone: 'Asia/Manila' }).format(new Date(`${String(value).slice(0, 10)}T00:00:00+08:00`)) : 'Not assigned'
const formatStatus = (value) => String(value || '').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
const profileOf = (item) => item?.student?.user?.profile || item?.student?.user_profile
const studentName = (item) => [profileOf(item)?.first_name, profileOf(item)?.last_name].filter(Boolean).join(' ') || 'Student'
const documentName = (item) => item?.document_request?.document_type?.document_name || item?.document_type?.document_name || 'Document'
const calendarDays = computed(() => calendar.value?.days || [])
const defaultCapacity = computed(() => Number(calendar.value?.default_capacity || 5))
const monthLabel = computed(() => new Intl.DateTimeFormat('en-PH', { month: 'long', year: 'numeric', timeZone: 'Asia/Manila' }).format(new Date(`${month.value}-01T00:00:00+08:00`)))
const selectedDay = computed(() => calendarDays.value.find((day) => day.date === selectedDate.value) || null)
const calendarLeadingDays = computed(() => {
  const day = new Date(`${month.value}-01T00:00:00+08:00`).getDay()
  return (day + 6) % 7
})
const blockedDates = computed(() => Object.fromEntries((calendar.value?.blocked_dates || []).map((item) => [item.date || item.blocked_date, item])))
const calendarSummary = computed(() => {
  const businessDays = calendarDays.value.filter((day) => !isUnavailable(day))
  const fullDays = businessDays.filter((day) => day.booked >= day.capacity).length
  return { businessDays: businessDays.length, booked: businessDays.reduce((total, day) => total + day.booked, 0), fullDays, availableDays: businessDays.length - fullDays }
})

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
  calendarLoading.value = true; calendarError.value = ''
  try {
    calendar.value = await api.registrarCalendar(month.value)
    const current = calendarDays.value.find((day) => day.date === selectedDate.value)
    if (current && !isUnavailable(current)) capacityDraft.value = current.capacity
    else selectFirstEditableDay()
  } catch (err) { calendarError.value = requestError(err) } finally { calendarLoading.value = false }
}
function blockedDate(day) { return blockedDates.value[day.date] }
function isWeekend(day) {
  const weekday = new Date(`${day.date}T00:00:00+08:00`).getDay()
  return (weekday === 6 && calendar.value?.settings?.block_saturday) || (weekday === 0 && calendar.value?.settings?.block_sunday)
}
function isUnavailable(day) { return Boolean(blockedDate(day)) || isWeekend(day) }
function dayStatus(day) {
  const blocked = blockedDate(day)
  if (blocked) return blocked.type === 'holiday' ? 'Holiday' : 'Blocked'
  if (isWeekend(day)) return 'Closed'
  if (day.booked >= day.capacity) return 'Full'
  if (day.capacity - day.booked === 1) return 'Nearly full'
  return 'Available'
}
function dayStatusClass(day) { return dayStatus(day).toLowerCase().replaceAll(' ', '-') }
function slotsLeft(day) { return Math.max(0, day.capacity - day.booked) }
function dayAriaLabel(day) {
  const blocked = blockedDate(day)
  if (blocked) return `${formatDate(day.date)}, ${dayStatus(day)}${blocked.reason ? `: ${blocked.reason}` : ''}`
  if (isWeekend(day)) return `${formatDate(day.date)}, closed`
  return `${formatDate(day.date)}, ${day.booked} of ${day.capacity} appointments booked, ${slotsLeft(day)} slots available, ${dayStatus(day)}`
}
function selectDay(day) {
  if (isUnavailable(day)) return
  selectedDate.value = day.date
  capacityDraft.value = day.capacity
  calendarError.value = ''
}
function selectFirstEditableDay() {
  const first = calendarDays.value.find((day) => !isUnavailable(day))
  if (first) selectDay(first)
}
function adjustCapacity(amount) {
  if (!selectedDay.value) return
  capacityDraft.value = Math.min(100, Math.max(selectedDay.value.booked || 1, Number(capacityDraft.value) + amount))
}
function resetCapacity() { if (selectedDay.value) capacityDraft.value = defaultCapacity.value }
const capacityValidation = computed(() => {
  if (!selectedDay.value || !Number.isInteger(Number(capacityDraft.value))) return 'Enter a whole-number daily capacity.'
  if (Number(capacityDraft.value) < 1 || Number(capacityDraft.value) > 100) return 'Daily capacity must be between 1 and 100.'
  if (Number(capacityDraft.value) < selectedDay.value.booked) return `Capacity cannot be lower than ${selectedDay.value.booked} because ${selectedDay.value.booked} appointments are already assigned.`
  return ''
})
async function saveCapacity() {
  if (!selectedDay.value || capacityValidation.value) return
  savingCapacity.value = true; calendarError.value = ''
  try {
    const capacity = Number(capacityDraft.value)
    await api.updateDateCapacity(selectedDay.value.date, capacity)
    const updated = { ...selectedDay.value, capacity, has_custom_capacity: capacity !== defaultCapacity.value }
    calendar.value = { ...calendar.value, days: calendarDays.value.map((day) => day.date === updated.date ? updated : day) }
    message.value = capacity === defaultCapacity.value ? `${formatDate(updated.date)} now uses the default capacity of ${defaultCapacity.value}.` : `Capacity for ${formatDate(updated.date)} updated to ${capacity}.`
  } catch (err) { calendarError.value = requestError(err) } finally { savingCapacity.value = false }
}
function changeMonth(offset) {
  const [year, monthNumber] = month.value.split('-').map(Number), next = new Date(year, monthNumber - 1 + offset, 1)
  month.value = `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`
}
function goToToday() { month.value = today().slice(0, 7); selectedDate.value = today() }
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

  <Teleport to="body"><Transition name="modal"><div v-if="availabilityOpen" class="appointment-details-backdrop" @click.self="closeAvailability"><section class="appointment-details-modal capacity-planner-modal" role="dialog" aria-modal="true" aria-labelledby="capacity-title">
    <header class="appointment-details-header"><div><p class="page-kicker">Availability</p><h2 id="capacity-title">Daily Appointment Capacity</h2></div><button class="capacity-close" aria-label="Close daily appointment capacity" @click="closeAvailability">&times;</button></header>
    <div class="appointment-details-body capacity-planner-body">
      <div class="capacity-month-heading"><div><h3>{{ monthLabel }}</h3><p>Default daily capacity: <strong>{{ defaultCapacity }}</strong></p></div><nav class="calendar-navigation" aria-label="Calendar navigation"><button type="button" @click="changeMonth(-1)">Previous</button><button type="button" @click="goToToday">Today</button><button type="button" @click="changeMonth(1)">Next</button></nav></div>

      <div v-if="calendarLoading" class="capacity-state" role="status">Loading appointment availability...</div>
      <div v-else-if="calendarError && !calendar" class="capacity-state capacity-state-error" role="alert"><span>Could not load appointment availability.</span><button type="button" @click="loadCalendar">Retry</button></div>
      <template v-else-if="calendarDays.length">
        <dl class="capacity-summary" aria-label="Monthly appointment capacity summary"><div><dt>Business days</dt><dd>{{ calendarSummary.businessDays }}</dd></div><div><dt>Scheduled</dt><dd>{{ calendarSummary.booked }}</dd></div><div><dt>Full days</dt><dd>{{ calendarSummary.fullDays }}</dd></div><div><dt>Available days</dt><dd>{{ calendarSummary.availableDays }}</dd></div></dl>
        <div class="capacity-workspace">
          <section class="capacity-calendar" aria-label="Appointment capacity calendar"><div class="calendar-weekdays" aria-hidden="true"><span v-for="weekday in ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']" :key="weekday">{{ weekday }}</span></div><div class="capacity-calendar-grid">
            <span v-for="(_, index) in calendarLeadingDays" :key="`blank-${index}`" class="calendar-blank" aria-hidden="true"></span>
            <button v-for="day in calendarDays" :key="day.date" type="button" class="appointment-calendar-day" :class="[dayStatusClass(day), { selected: day.date === selectedDate, today: day.date === today(), 'is-unavailable': isUnavailable(day) }]" :disabled="isUnavailable(day)" :aria-label="dayAriaLabel(day)" @click="selectDay(day)"><span class="calendar-day-number">{{ Number(day.date.slice(-2)) }}</span><template v-if="!isUnavailable(day)"><span class="calendar-booked">{{ day.booked }} / {{ day.capacity }} booked</span><span class="calendar-remaining">{{ slotsLeft(day) }} {{ slotsLeft(day) === 1 ? 'slot' : 'slots' }} left</span><span class="calendar-status">{{ dayStatus(day) }}</span><span class="calendar-progress" aria-hidden="true"><i :style="{ width: `${Math.min(100, (day.booked / day.capacity) * 100)}%` }"></i></span></template><template v-else><span class="calendar-status">{{ dayStatus(day) }}</span><small v-if="blockedDate(day)?.reason">{{ blockedDate(day).reason }}</small></template></button>
          </div></section>

          <aside v-if="selectedDay" class="capacity-editor" aria-labelledby="capacity-editor-title"><header><p class="page-kicker">Selected date</p><h3 id="capacity-editor-title">{{ formatDate(selectedDay.date) }}</h3><span class="capacity-status-badge" :class="dayStatusClass(selectedDay)">{{ dayStatus(selectedDay) }}</span></header><dl class="capacity-editor-stats"><div><dt>Appointments</dt><dd>{{ selectedDay.booked }} booked</dd></div><div><dt>Capacity</dt><dd>{{ selectedDay.capacity }}</dd></div><div><dt>Remaining</dt><dd>{{ slotsLeft(selectedDay) }} slots</dd></div><div><dt>Capacity type</dt><dd>{{ selectedDay.has_custom_capacity ? 'Custom' : 'Default' }}</dd></div></dl><label class="capacity-stepper-label" for="daily-capacity">Daily capacity</label><div class="capacity-stepper"><button type="button" aria-label="Decrease daily capacity" :disabled="savingCapacity || capacityDraft <= selectedDay.booked" @click="adjustCapacity(-1)">-</button><input id="daily-capacity" v-model.number="capacityDraft" type="number" :min="selectedDay.booked" max="100" inputmode="numeric" :aria-describedby="capacityValidation ? 'capacity-validation' : undefined" /><button type="button" aria-label="Increase daily capacity" :disabled="savingCapacity || capacityDraft >= 100" @click="adjustCapacity(1)">+</button></div><p v-if="capacityValidation" id="capacity-validation" class="capacity-validation" role="alert">{{ capacityValidation }}</p><p v-else class="capacity-editor-hint">{{ selectedDay.has_custom_capacity ? `Custom capacity: ${selectedDay.capacity}` : `Uses the default capacity of ${defaultCapacity}` }}</p><div v-if="calendarError" class="capacity-validation" role="alert">{{ calendarError }}</div><footer><button type="button" class="reset-capacity" :disabled="savingCapacity || capacityDraft === defaultCapacity" @click="resetCapacity">Reset to Default</button><button type="button" class="save-capacity" :disabled="savingCapacity || Boolean(capacityValidation) || capacityDraft === selectedDay.capacity" @click="saveCapacity">{{ savingCapacity ? 'Saving...' : 'Save Changes' }}</button></footer></aside>
        </div>
        <div class="capacity-legend" aria-label="Calendar legend"><span><i class="legend-dot available"></i>Available</span><span><i class="legend-dot nearly-full"></i>Nearly full</span><span><i class="legend-dot full"></i>Full</span><span><i class="legend-dot closed"></i>Closed / blocked</span></div>
      </template>
      <div v-else class="capacity-state">No appointment availability data for this month.</div>
    </div></section></div></Transition></Teleport>
</template>

<style scoped src="./documentRequest.css"></style>
<style scoped>
.verification-panel{transition:border-color .22s ease,background-color .22s ease}.verification-panel.is-verified{border-color:rgba(38,166,91,.65)}
.verified-details-enter-active,.verified-details-leave-active{transition:opacity .24s ease,transform .24s ease}.verified-details-enter-from,.verified-details-leave-to{opacity:0;transform:translateY(8px)}
.appointment-row-enter-active,.appointment-row-leave-active{transition:opacity .28s ease,transform .28s ease}.appointment-row-leave-to{opacity:0;transform:translateY(-6px)}.modal-enter-active,.modal-leave-active{transition:opacity .2s ease}.modal-enter-from,.modal-leave-to{opacity:0}
.capacity-planner-modal{max-width:1120px}.capacity-close{align-items:center;background:#edf3ef;border:1px solid #d5dfd8;border-radius:50%;color:#405047;cursor:pointer;display:inline-flex;font-size:1.35rem;height:36px;justify-content:center;line-height:1;padding:0;width:36px}.capacity-close:focus-visible,.capacity-close:hover{border-color:var(--color-dark-spring-green);outline:2px solid rgb(0 111 60 / 18%);outline-offset:2px}.capacity-planner-body{display:grid;gap:18px}.capacity-month-heading{align-items:center;display:flex;gap:16px;justify-content:space-between}.capacity-month-heading h3,.capacity-editor h3{color:#193e29;font-size:1.05rem;margin:0}.capacity-month-heading p{color:var(--color-muted);font-size:.82rem;margin:5px 0 0}.calendar-navigation{display:flex;gap:7px}.calendar-navigation button,.capacity-state button{background:#fff;border:1px solid #cfdcd2;border-radius:6px;color:#245238;cursor:pointer;font:inherit;font-size:.78rem;font-weight:800;min-height:34px;padding:6px 10px}.calendar-navigation button:hover,.calendar-navigation button:focus-visible,.capacity-state button:hover,.capacity-state button:focus-visible{background:#f1f7f2;border-color:var(--color-dark-spring-green);outline:none}.capacity-summary{display:grid;gap:8px;grid-template-columns:repeat(4,minmax(0,1fr));margin:0}.capacity-summary div{background:#f5f8f5;border-left:3px solid #d9e8dd;min-width:0;padding:9px 11px}.capacity-summary dt,.capacity-editor-stats dt{color:var(--color-muted);font-size:.65rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase}.capacity-summary dd,.capacity-editor-stats dd{color:#193e29;font-size:.94rem;font-weight:800;margin:4px 0 0}.capacity-workspace{align-items:start;display:grid;gap:18px;grid-template-columns:minmax(0,1fr) 270px}.capacity-calendar{min-width:0}.calendar-weekdays,.capacity-calendar-grid{display:grid;grid-template-columns:repeat(7,minmax(90px,1fr))}.calendar-weekdays{gap:5px;margin-bottom:5px}.calendar-weekdays span{color:var(--color-muted);font-size:.68rem;font-weight:800;padding:0 4px;text-align:left;text-transform:uppercase}.capacity-calendar-grid{background:#e0e8e2;border:1px solid #d4dfd6;gap:1px;overflow:auto}.calendar-blank{background:#f7f9f7;min-height:132px}.appointment-calendar-day{align-items:flex-start;background:#fff;border:0;border-left:3px solid #c4dfcb;color:#264533;cursor:pointer;display:flex;flex-direction:column;min-height:132px;padding:10px;text-align:left;transition:background-color .18s ease,box-shadow .18s ease,transform .18s ease}.appointment-calendar-day:hover:not(:disabled){background:#f7fbf7;transform:translateY(-1px)}.appointment-calendar-day:focus-visible{box-shadow:inset 0 0 0 3px var(--color-naples-yellow);outline:none;position:relative;z-index:1}.appointment-calendar-day.selected{box-shadow:inset 0 0 0 2px var(--color-dartmouth-green);position:relative;z-index:1}.appointment-calendar-day.today .calendar-day-number{background:var(--color-dartmouth-green);border-radius:50%;color:#fff;height:25px;line-height:25px;text-align:center;width:25px}.calendar-day-number{color:#173c27;font-size:.91rem;font-weight:900}.calendar-booked{font-size:.75rem;font-weight:800;margin-top:11px}.calendar-remaining{color:#536259;font-size:.7rem;margin-top:2px}.calendar-status{font-size:.67rem;font-weight:900;margin-top:auto;text-transform:uppercase}.calendar-progress{background:#dfe8e1;border-radius:4px;height:4px;margin-top:7px;overflow:hidden;width:100%}.calendar-progress i{background:#31965b;display:block;height:100%}.appointment-calendar-day.nearly-full{border-left-color:#d1a227}.appointment-calendar-day.nearly-full .calendar-progress i{background:#d1a227}.appointment-calendar-day.full{background:#fff7f5;border-left-color:#bf5648}.appointment-calendar-day.full .calendar-status{color:#9f3f34}.appointment-calendar-day.full .calendar-progress i{background:#bf5648}.appointment-calendar-day.is-unavailable{background:#f3f5f3;border-left-color:#97a39b;color:#607067;cursor:not-allowed}.appointment-calendar-day.is-unavailable small{font-size:.67rem;line-height:1.25;margin-top:4px}.capacity-editor{background:#f8fbf9;border:1px solid #d5e1d7;border-radius:8px;box-shadow:0 4px 14px rgb(19 60 39 / 8%);padding:16px}.capacity-editor header{border-bottom:1px solid #dce6de;padding-bottom:12px}.capacity-status-badge{border-radius:999px;display:inline-block;font-size:.66rem;font-weight:900;margin-top:9px;padding:4px 7px;text-transform:uppercase}.capacity-status-badge.available{background:#e8f5ec;color:#176c3a}.capacity-status-badge.nearly-full{background:#fff5d8;color:#795c06}.capacity-status-badge.full{background:#fff0ed;color:#9f3f34}.capacity-editor-stats{display:grid;gap:8px;grid-template-columns:repeat(2,minmax(0,1fr));margin:14px 0}.capacity-editor-stats div{background:#fff;border-left:2px solid #d9e8dd;padding:8px}.capacity-editor-stats dd{font-size:.78rem}.capacity-stepper-label{color:#264533;display:block;font-size:.78rem;font-weight:800;margin-bottom:7px}.capacity-stepper{display:grid;grid-template-columns:38px minmax(0,1fr) 38px}.capacity-stepper button,.capacity-stepper input{border:1px solid #bdcdbf;min-height:38px}.capacity-stepper button{background:#edf3ef;color:#245238;cursor:pointer;font-size:1.1rem;font-weight:800}.capacity-stepper button:disabled{cursor:not-allowed;opacity:.45}.capacity-stepper input{border-left:0;border-right:0;color:#193e29;font:inherit;font-weight:800;min-width:0;text-align:center}.capacity-stepper input:focus-visible{outline:2px solid rgb(0 111 60 / 25%);outline-offset:-2px}.capacity-editor-hint,.capacity-validation{font-size:.72rem;line-height:1.4;margin:9px 0}.capacity-editor-hint{color:var(--color-muted)}.capacity-validation{color:#9f3f34}.capacity-editor footer{display:grid;gap:8px;grid-template-columns:1fr 1fr;margin-top:16px}.capacity-editor footer button{border-radius:6px;cursor:pointer;font:inherit;font-size:.75rem;font-weight:800;min-height:37px;padding:7px}.reset-capacity{background:#fff;border:1px solid #c5d1c7;color:#385448}.save-capacity{background:var(--color-dartmouth-green);border:1px solid var(--color-dartmouth-green);color:#fff}.capacity-editor footer button:disabled{cursor:not-allowed;opacity:.5}.capacity-legend{display:flex;flex-wrap:wrap;gap:13px}.capacity-legend span{align-items:center;color:#536259;display:inline-flex;font-size:.71rem;font-weight:700;gap:5px}.legend-dot{border-radius:50%;height:8px;width:8px}.legend-dot.available{background:#31965b}.legend-dot.nearly-full{background:#d1a227}.legend-dot.full{background:#bf5648}.legend-dot.closed{background:#97a39b}.capacity-state{align-items:center;background:#f5f8f5;border:1px dashed #cbd9ce;color:#536259;display:flex;font-size:.84rem;gap:12px;justify-content:center;min-height:180px;padding:18px;text-align:center}.capacity-state-error{color:#9f3f34}
@media(max-width:800px){.capacity-workspace{grid-template-columns:1fr}.capacity-editor{position:relative}.capacity-calendar-grid{max-width:100%;overflow-x:auto}.calendar-weekdays,.capacity-calendar-grid{grid-template-columns:repeat(7,minmax(88px,1fr))}}@media(max-width:560px){.capacity-month-heading{align-items:flex-start;flex-direction:column}.capacity-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.capacity-calendar{overflow-x:auto}.calendar-weekdays,.capacity-calendar-grid{min-width:630px}.capacity-editor footer{grid-template-columns:1fr}.appointment-calendar-day,.calendar-blank{min-height:118px}}
@media(prefers-reduced-motion:reduce){*{transition-duration:.01ms!important;scroll-behavior:auto!important}}
</style>
