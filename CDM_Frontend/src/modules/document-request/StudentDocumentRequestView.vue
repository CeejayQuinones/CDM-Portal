<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { requestReference } from './documentRequestPresentation'
import { documentRequestService as api } from './documentRequestService'

const route = useRoute()
const router = useRouter()
const requests = ref([])
const documentTypes = ref([])
const selectedPanel = ref(route.query.panel === 'appointment' ? 'appointment' : 'request')
const selectedRequestId = ref(null)
const selectedAppointmentId = ref(null)
const loading = ref(false)
const message = ref('')
const error = ref('')
const historyLimit = ref(8)
const detailPanel = ref(null)

const showRequestForm = ref(false)
const requestFormLoading = ref(false)
const requestSubmitting = ref(false)
const requestFormError = ref('')
const selectedTypeId = ref('')
const quantity = ref(1)
const purpose = ref('')
const requestDialog = ref(null)

const bookingRequest = ref(null)
const appointmentDate = ref('')
const appointmentTime = ref('')
const availabilityByMonth = ref({})
const weekendSettings = ref({ block_saturday: true, block_sunday: true })
const slots = ref([])
const slotLoading = ref(false)
const slotError = ref('')
const slotDateReason = ref('')
const bookingInProgress = ref(false)
const bookingError = ref('')
const bookingDialog = ref(null)
const currentDate = new Date()
const today = dateKey(currentDate.getFullYear(), currentDate.getMonth(), currentDate.getDate())
const calendarMonth = ref(new Date(currentDate.getFullYear(), currentDate.getMonth(), 1))
const weekdayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
let slotRequestSequence = 0

const cancellingAppointment = ref(null)
const cancellationReason = ref('')
const cancellationError = ref('')
const cancellationInProgress = ref(false)
const cancellationDialog = ref(null)

const cancellingRequest = ref(null)
const requestCancellationReason = ref('')
const requestCancellationError = ref('')
const requestCancellationInProgress = ref(false)
const requestCancellationDialog = ref(null)

const activeRequestStatuses = ['pending', 'processing', 'ready_for_release']
const activeAppointmentStatuses = ['pending', 'confirmed']
const terminalRequestStatuses = ['released', 'rejected', 'cancelled']
const terminalAppointmentStatuses = ['completed', 'cancelled', 'no_show']

const requestError = (err) => {
  const validationMessage = Object.values(err.response?.data?.errors || {}).flat()[0]

  return validationMessage || err.response?.data?.message || 'The request could not be completed.'
}

const activeRequests = computed(() => requests.value.filter((item) => activeRequestStatuses.includes(item.status)))
const allAppointments = computed(() =>
  requests.value.flatMap((documentRequest) =>
    (documentRequest.appointments || []).map((appointment) => ({
      ...appointment,
      document_request_id: documentRequest.id,
      document_request: documentRequest,
    })),
  ),
)
const activeAppointments = computed(() =>
  allAppointments.value.filter((appointment) => activeAppointmentStatuses.includes(appointment.status)),
)
const latestRequest = computed(() => activeRequests.value[0] || requests.value[0] || null)
const nearestAppointment = computed(() => {
  const sorted = [...activeAppointments.value].sort((left, right) =>
    appointmentSortKey(left).localeCompare(appointmentSortKey(right)),
  )
  const currentKey = `${today} 00:00`

  return sorted.find((appointment) => appointmentSortKey(appointment) >= currentKey) || sorted.at(-1) || null
})
const selectedRequest = computed(
  () => requests.value.find((item) => item.id === Number(selectedRequestId.value)) || latestRequest.value,
)
const selectedAppointment = computed(
  () =>
    allAppointments.value.find((item) => item.id === Number(selectedAppointmentId.value)) ||
    nearestAppointment.value ||
    allAppointments.value[0] ||
    null,
)
const selectedRequestAppointment = computed(() => {
  const appointments = selectedRequest.value?.appointments || []

  return (
    appointments.find((appointment) => activeAppointmentStatuses.includes(appointment.status)) ||
    [...appointments].sort((left, right) => right.id - left.id)[0] ||
    null
  )
})
const detailTransitionKey = computed(
  () => `${selectedPanel.value}-${selectedPanel.value === 'request' ? selectedRequest.value?.id || 'empty' : selectedAppointment.value?.id || 'empty'}`,
)
const selectedType = computed(() => documentTypes.value.find((item) => item.id === Number(selectedTypeId.value)))
const canBookSelectedRequest = computed(() => {
  const documentRequest = selectedRequest.value
  if (!documentRequest?.document_type?.requires_appointment) return false
  if (!activeRequestStatuses.includes(documentRequest.status)) return false

  return !(documentRequest.appointments || []).some((appointment) =>
    activeAppointmentStatuses.includes(appointment.status),
  )
})
const canCancelSelectedRequest = computed(() => selectedRequest.value?.status === 'pending')
const historyItems = computed(() =>
  requests.value.filter(
    (item) =>
      terminalRequestStatuses.includes(item.status) ||
      (item.appointments || []).some((appointment) => terminalAppointmentStatuses.includes(appointment.status)),
  ),
)
const visibleHistory = computed(() => historyItems.value.slice(0, historyLimit.value))
const anyModalOpen = computed(
  () => Boolean(showRequestForm.value || bookingRequest.value || cancellingAppointment.value || cancellingRequest.value),
)
let bodyOverflowBeforeModal = ''

const calendarMonthKey = computed(
  () => `${calendarMonth.value.getFullYear()}-${String(calendarMonth.value.getMonth() + 1).padStart(2, '0')}`,
)
const blockedByDate = computed(() => {
  const blockedDates = availabilityByMonth.value[calendarMonthKey.value] || []

  return blockedDates.reduce((dates, blockedDate) => {
    const existing = dates.get(blockedDate.date)
    dates.set(blockedDate.date, {
      ...blockedDate,
      reason: existing ? `${existing.reason}; ${blockedDate.reason}` : blockedDate.reason,
    })

    return dates
  }, new Map())
})
const selectedUnavailable = computed(() => {
  if (!appointmentDate.value) return null
  const blockedDate = Object.values(availabilityByMonth.value)
    .flat()
    .find((item) => item.date === appointmentDate.value)
  if (blockedDate) return blockedDate

  const [year, month, day] = appointmentDate.value.split('-').map(Number)

  return blockedWeekend(new Date(year, month - 1, day))
})
const calendarLabel = computed(() =>
  new Intl.DateTimeFormat('en-PH', { month: 'long', year: 'numeric' }).format(calendarMonth.value),
)
const availableSlots = computed(() => slots.value.filter((slot) => slot.available))
const canBook = computed(
  () =>
    Boolean(bookingRequest.value) &&
    Boolean(appointmentDate.value) &&
    Boolean(appointmentTime.value) &&
    availableSlots.value.some((slot) => slot.time === appointmentTime.value) &&
    !selectedUnavailable.value &&
    !slotLoading.value &&
    !bookingInProgress.value,
)
const isCurrentMonth = computed(
  () =>
    calendarMonth.value.getFullYear() === currentDate.getFullYear() &&
    calendarMonth.value.getMonth() === currentDate.getMonth(),
)
const calendarDays = computed(() => {
  const year = calendarMonth.value.getFullYear()
  const month = calendarMonth.value.getMonth()
  const leadingDays = new Date(year, month, 1).getDay()
  const daysInMonth = new Date(year, month + 1, 0).getDate()
  const days = Array.from({ length: leadingDays }, (_, index) => ({ key: `blank-${index}`, blank: true }))

  for (let day = 1; day <= daysInMonth; day += 1) {
    const date = dateKey(year, month, day)
    const blockedDate = blockedByDate.value.get(date) || null
    const weekend = blockedWeekend(new Date(year, month, day))
    const unavailable = blockedDate || weekend

    days.push({ key: date, blank: false, date, day, unavailable, disabled: date < today || Boolean(unavailable) })
  }

  return days
})

function dateKey(year, month, day) {
  return `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

function appointmentSortKey(appointment) {
  return `${String(appointment?.appointment_date || '').slice(0, 10)} ${String(appointment?.appointment_time || '').slice(0, 5)}`
}

function formatDate(value) {
  if (!value) return 'Not available'
  const normalized = String(value).slice(0, 10)
  const date = new Date(`${normalized}T00:00:00`)

  return Number.isNaN(date.getTime())
    ? normalized
    : new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium' }).format(date)
}

function formatTime(value) {
  const normalized = String(value || '').slice(0, 5)
  if (!normalized) return 'Not available'
  const [hour, minute] = normalized.split(':').map(Number)
  const date = new Date(2000, 0, 1, hour, minute)

  return new Intl.DateTimeFormat('en-PH', { hour: 'numeric', minute: '2-digit' }).format(date)
}

function formatMoney(value) {
  return Number(value || 0).toFixed(2)
}

function formatStatus(value) {
  return String(value || 'unknown').replaceAll('_', ' ')
}

function documentTypeLabel(type) {
  return Number(type.processing_fee) > 0
    ? `${type.document_name} — ₱${formatMoney(type.processing_fee)}`
    : type.document_name
}

function blockedWeekend(date) {
  if (date.getDay() === 6 && weekendSettings.value.block_saturday) return { reason: 'Saturday', type: 'weekend' }
  if (date.getDay() === 0 && weekendSettings.value.block_sunday) return { reason: 'Sunday', type: 'weekend' }

  return null
}

function syncSelections() {
  if (!requests.value.some((item) => item.id === Number(selectedRequestId.value))) {
    selectedRequestId.value = latestRequest.value?.id || null
  }
  if (!allAppointments.value.some((item) => item.id === Number(selectedAppointmentId.value))) {
    selectedAppointmentId.value = nearestAppointment.value?.id || allAppointments.value[0]?.id || null
  }
}

async function loadRequests() {
  requests.value = await api.myRequests()
  syncSelections()
}

async function refresh() {
  loading.value = true
  error.value = ''
  try {
    await loadRequests()
  } catch (err) {
    error.value = requestError(err)
  } finally {
    loading.value = false
  }
}

function selectPanel(panel) {
  selectedPanel.value = panel
  showRequestForm.value = false
  if (panel !== 'request') closeBooking()
  if (route.query.panel) {
    router.replace({ name: 'student-document-requests', query: { ...route.query, panel: undefined } })
  }
}

function selectRequest(id, scroll = false) {
  selectedRequestId.value = id
  selectPanel('request')
  if (scroll) {
    nextTick(() =>
      detailPanel.value?.scrollIntoView({
        behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        block: 'start',
      }),
    )
  }
}

function selectAppointment(id) {
  selectedAppointmentId.value = id
  selectPanel('appointment')
}

function viewRequestAppointment() {
  if (!selectedRequestAppointment.value) return
  selectAppointment(selectedRequestAppointment.value.id)
}

async function openRequestForm() {
  selectPanel('request')
  showRequestForm.value = true
  requestFormError.value = ''
  nextTick(() => requestDialog.value?.focus())
  if (documentTypes.value.length) return

  requestFormLoading.value = true
  try {
    documentTypes.value = await api.documentTypes()
  } catch (err) {
    requestFormError.value = requestError(err)
  } finally {
    requestFormLoading.value = false
  }
}

function closeRequestForm(force = false) {
  if (requestSubmitting.value && !force) return
  showRequestForm.value = false
  requestFormError.value = ''
  selectedTypeId.value = ''
  quantity.value = 1
  purpose.value = ''
}

async function submitRequest() {
  if (requestSubmitting.value || requestFormLoading.value) return
  requestSubmitting.value = true
  requestFormError.value = ''
  message.value = ''
  try {
    const created = await api.createRequest({
      document_type_id: Number(selectedTypeId.value),
      quantity: Number(quantity.value),
      purpose: purpose.value || null,
    })
    closeRequestForm(true)
    await loadRequests()
    selectedRequestId.value = created.id
    selectedPanel.value = 'request'
    message.value = 'Document request submitted successfully.'
  } catch (err) {
    requestFormError.value = requestError(err)
  } finally {
    requestSubmitting.value = false
  }
}

function clearSlotSelection() {
  slotRequestSequence += 1
  appointmentDate.value = ''
  appointmentTime.value = ''
  slots.value = []
  slotLoading.value = false
  slotError.value = ''
  slotDateReason.value = ''
}

async function openBooking(documentRequest) {
  bookingRequest.value = documentRequest
  bookingError.value = ''
  showRequestForm.value = false
  clearSlotSelection()
  calendarMonth.value = new Date(currentDate.getFullYear(), currentDate.getMonth(), 1)
  await loadAvailability()
  nextTick(() => bookingDialog.value?.focus())
}

function closeBooking(force = false) {
  if (bookingInProgress.value && !force) return
  bookingRequest.value = null
  bookingError.value = ''
  clearSlotSelection()
}

function selectDate(day) {
  if (!day.disabled) appointmentDate.value = day.date
}

async function changeMonth(offset) {
  clearSlotSelection()
  calendarMonth.value = new Date(calendarMonth.value.getFullYear(), calendarMonth.value.getMonth() + offset, 1)
  await loadAvailability()
}

async function loadAvailability() {
  const month = calendarMonthKey.value
  if (Object.hasOwn(availabilityByMonth.value, month)) return

  try {
    const availability = await api.appointmentAvailability(month)
    availabilityByMonth.value = { ...availabilityByMonth.value, [month]: availability.blocked_dates }
    weekendSettings.value = {
      block_saturday: availability.settings?.block_saturday ?? true,
      block_sunday: availability.settings?.block_sunday ?? true,
    }
  } catch (err) {
    error.value = requestError(err)
  }
}

async function loadSlots(date = appointmentDate.value) {
  const requestSequence = ++slotRequestSequence
  appointmentTime.value = ''
  slots.value = []
  slotError.value = ''
  slotDateReason.value = ''
  if (!date || selectedUnavailable.value) return

  slotLoading.value = true
  try {
    const response = await api.slots(date)
    if (requestSequence !== slotRequestSequence || appointmentDate.value !== date) return
    slots.value = response.date === date && Array.isArray(response.slots) ? response.slots : []
    slotDateReason.value = response.date === date ? response.unavailable_reason || '' : ''
  } catch (err) {
    if (requestSequence === slotRequestSequence) slotError.value = requestError(err)
  } finally {
    if (requestSequence === slotRequestSequence) slotLoading.value = false
  }
}

function selectTime(slot) {
  if (!slot.available || slotLoading.value) return
  appointmentTime.value = slot.time
  slotError.value = ''
}

async function book() {
  if (!bookingRequest.value || bookingInProgress.value) return
  if (!appointmentDate.value) {
    slotError.value = 'Select an appointment date.'
    return
  }
  if (!appointmentTime.value || !availableSlots.value.some((slot) => slot.time === appointmentTime.value)) {
    slotError.value = 'Select an available appointment time.'
    return
  }

  bookingInProgress.value = true
  error.value = ''
  const bookedRequestId = bookingRequest.value.id
  try {
    const appointment = await api.book(bookedRequestId, {
      appointment_date: appointmentDate.value,
      appointment_time: appointmentTime.value,
    })
    closeBooking(true)
    await loadRequests()
    selectedAppointmentId.value = appointment.id
    selectedPanel.value = 'appointment'
    message.value = `Appointment booked for ${formatDate(appointment.appointment_date)} at ${formatTime(appointment.appointment_time)}.`
  } catch (err) {
    bookingError.value = requestError(err)
    await loadSlots(appointmentDate.value)
  } finally {
    bookingInProgress.value = false
  }
}

function openRequestCancellation(documentRequest) {
  if (documentRequest?.status !== 'pending') return
  cancellingRequest.value = documentRequest
  requestCancellationReason.value = ''
  requestCancellationError.value = ''
  nextTick(() => requestCancellationDialog.value?.focus())
}

function closeRequestCancellation(force = false) {
  if (requestCancellationInProgress.value && !force) return
  cancellingRequest.value = null
  requestCancellationReason.value = ''
  requestCancellationError.value = ''
}

async function cancelDocumentRequest() {
  if (!cancellingRequest.value || requestCancellationInProgress.value) return
  const reason = requestCancellationReason.value.trim()
  if (!reason) {
    requestCancellationError.value = 'Enter a reason for cancelling this document request.'
    return
  }

  requestCancellationInProgress.value = true
  requestCancellationError.value = ''
  const requestId = cancellingRequest.value.id
  try {
    await api.cancelRequest(requestId, { reason })
    closeRequestCancellation(true)
    await loadRequests()
    selectedRequestId.value = requestId
    selectedPanel.value = 'request'
    message.value = 'Document request cancelled successfully.'
  } catch (err) {
    requestCancellationError.value = requestError(err)
  } finally {
    requestCancellationInProgress.value = false
  }
}

function appointmentCanBeCancelled(appointment) {
  return (
    ['pending', 'confirmed'].includes(appointment?.status) &&
    !['released', 'rejected', 'cancelled'].includes(appointment?.document_request?.status)
  )
}

function openCancellation(appointment) {
  if (!appointmentCanBeCancelled(appointment)) return
  cancellingAppointment.value = appointment
  cancellationReason.value = ''
  cancellationError.value = ''
  nextTick(() => cancellationDialog.value?.focus())
}

function closeCancellation(force = false) {
  if (cancellationInProgress.value && !force) return
  cancellingAppointment.value = null
  cancellationReason.value = ''
  cancellationError.value = ''
}

async function cancelAppointment() {
  if (!cancellingAppointment.value || cancellationInProgress.value) return
  const reason = cancellationReason.value.trim()
  if (!reason) {
    cancellationError.value = 'Enter a reason for cancelling this appointment.'
    return
  }

  cancellationInProgress.value = true
  cancellationError.value = ''
  const appointmentId = cancellingAppointment.value.id
  try {
    await api.cancelAppointment(appointmentId, { reason })
    closeCancellation(true)
    await loadRequests()
    selectedAppointmentId.value = appointmentId
    message.value = 'Appointment cancelled. Its time slot is available again when capacity permits.'
  } catch (err) {
    cancellationError.value = requestError(err)
  } finally {
    cancellationInProgress.value = false
  }
}

watch(appointmentDate, async (selectedDate) => {
  appointmentTime.value = ''
  slots.value = []
  slotError.value = ''
  slotDateReason.value = ''
  if (!selectedDate) return
  if (selectedUnavailable.value) {
    slotError.value = `${selectedUnavailable.value.reason} is unavailable for appointments.`
    return
  }
  await loadSlots(selectedDate)
})

watch(
  () => route.query.panel,
  (panel) => {
    if (panel === 'appointment') selectedPanel.value = 'appointment'
  },
)

watch(anyModalOpen, (isOpen) => {
  if (isOpen) {
    bodyOverflowBeforeModal = document.body.style.overflow
    document.body.style.overflow = 'hidden'
  } else {
    document.body.style.overflow = bodyOverflowBeforeModal
  }
})

onMounted(refresh)
onBeforeUnmount(() => {
  document.body.style.overflow = bodyOverflowBeforeModal
})
</script>

<template>
  <section class="page-header student-document-heading">
    <p class="page-kicker">Student Services</p>
    <h1 class="page-title">Student Document Requests</h1>
    <p class="page-description">Request documents, book appointments, and follow your progress in one place.</p>
  </section>

  <p v-if="message" class="notice success" role="status">{{ message }}</p>
  <p v-if="error" class="notice error" role="alert">{{ error }}</p>

  <section class="student-request-workspace" aria-label="Document request and appointment workspace">
    <aside class="student-summary-rail" aria-label="Select details to view">
      <button
        type="button"
        class="student-summary-card"
        :class="{ 'is-active': selectedPanel === 'request' }"
        :aria-pressed="selectedPanel === 'request'"
        @click="selectPanel('request')"
      >
        <span class="student-summary-icon" aria-hidden="true">DR</span>
        <span class="student-summary-copy">
          <small>Document Request</small>
          <strong>{{ activeRequests.length }} active</strong>
          <span v-if="latestRequest">{{ latestRequest.document_type.document_name }}</span>
          <span v-else>No current request</span>
          <em v-if="latestRequest" class="badge" :class="latestRequest.status">{{ formatStatus(latestRequest.status) }}</em>
        </span>
        <span class="student-summary-chevron" aria-hidden="true">›</span>
      </button>

      <button
        type="button"
        class="student-summary-card"
        :class="{ 'is-active': selectedPanel === 'appointment' }"
        :aria-pressed="selectedPanel === 'appointment'"
        @click="selectPanel('appointment')"
      >
        <span class="student-summary-icon" aria-hidden="true">AP</span>
        <span class="student-summary-copy">
          <small>Appointment</small>
          <strong>{{ activeAppointments.length }} upcoming/current</strong>
          <span v-if="nearestAppointment">
            {{ formatDate(nearestAppointment.appointment_date) }} · {{ formatTime(nearestAppointment.appointment_time) }}
          </span>
          <span v-else>No current appointment</span>
          <em v-if="nearestAppointment" class="badge" :class="nearestAppointment.status">
            {{ formatStatus(nearestAppointment.status) }}
          </em>
        </span>
        <span class="student-summary-chevron" aria-hidden="true">›</span>
      </button>
    </aside>

    <section ref="detailPanel" class="dr-panel student-detail-panel" aria-live="polite">
      <p v-if="loading && !requests.length" class="student-panel-state">Loading your document requests…</p>

      <Transition name="student-detail-swap" mode="out-in">
        <article v-if="!loading || requests.length" :key="detailTransitionKey" class="student-detail-content">
          <template v-if="selectedPanel === 'request'">
            <header class="student-detail-heading">
              <div>
                <p class="page-kicker">Selected details</p>
                <h2>Document Request</h2>
              </div>
              <button type="button" class="student-primary-action" @click="openRequestForm">New Request</button>
            </header>

            <label v-if="activeRequests.length > 1" class="student-record-selector">
              Current request
              <select v-model="selectedRequestId">
                <option v-for="item in activeRequests" :key="item.id" :value="item.id">
                  {{ requestReference(item.id) }} · {{ item.document_type.document_name }}
                </option>
              </select>
            </label>

            <template v-if="selectedRequest">
              <dl class="student-detail-grid">
                <div><dt>Request reference</dt><dd>{{ requestReference(selectedRequest.id) }}</dd></div>
                <div><dt>Document type</dt><dd>{{ selectedRequest.document_type.document_name }}</dd></div>
                <div><dt>Status</dt><dd><span class="badge" :class="selectedRequest.status">{{ formatStatus(selectedRequest.status) }}</span></dd></div>
                <div><dt>Request date</dt><dd>{{ formatDate(selectedRequest.request_date) }}</dd></div>
                <div><dt>Fee</dt><dd>₱{{ formatMoney(selectedRequest.total_fee) }}</dd></div>
                <div>
                  <dt>Appointment requirement</dt>
                  <dd>{{ selectedRequest.document_type.requires_appointment ? 'Required' : 'Not required' }}</dd>
                </div>
                <div v-if="selectedRequestAppointment">
                  <dt>Appointment date</dt><dd>{{ formatDate(selectedRequestAppointment.appointment_date) }}</dd>
                </div>
                <div v-if="selectedRequestAppointment">
                  <dt>Appointment time</dt><dd>{{ formatTime(selectedRequestAppointment.appointment_time) }}</dd>
                </div>
              </dl>

              <p v-if="selectedRequest.purpose" class="student-detail-note"><strong>Purpose:</strong> {{ selectedRequest.purpose }}</p>
              <p v-if="selectedRequest.remarks" class="student-detail-note"><strong>Registrar remarks:</strong> {{ selectedRequest.remarks }}</p>

              <div class="student-detail-actions">
                <button
                  v-if="canBookSelectedRequest"
                  type="button"
                  class="student-primary-action"
                  @click="openBooking(selectedRequest)"
                >
                  Book Appointment
                </button>
                <button
                  v-if="selectedRequestAppointment"
                  type="button"
                  class="student-secondary-action"
                  @click="viewRequestAppointment"
                >
                  View Appointment
                </button>
                <button
                  v-if="canCancelSelectedRequest"
                  type="button"
                  class="student-danger-action"
                  @click="openRequestCancellation(selectedRequest)"
                >
                  Cancel Request
                </button>
              </div>
            </template>
            <p v-else class="student-panel-state">No document requests yet. Start a new request when you are ready.</p>

          </template>

          <template v-else>
            <header class="student-detail-heading">
              <div><p class="page-kicker">Selected details</p><h2>Appointment</h2></div>
            </header>

            <label v-if="activeAppointments.length > 1" class="student-record-selector">
              Current appointment
              <select v-model="selectedAppointmentId">
                <option v-for="item in activeAppointments" :key="item.id" :value="item.id">
                  {{ formatDate(item.appointment_date) }} · {{ formatTime(item.appointment_time) }} · {{ item.document_request.document_type.document_name }}
                </option>
              </select>
            </label>

            <template v-if="selectedAppointment">
              <dl class="student-detail-grid">
                <div><dt>Linked request</dt><dd>{{ requestReference(selectedAppointment.document_request_id) }}</dd></div>
                <div><dt>Document type</dt><dd>{{ selectedAppointment.document_request.document_type.document_name }}</dd></div>
                <div><dt>Appointment date</dt><dd>{{ formatDate(selectedAppointment.appointment_date) }}</dd></div>
                <div><dt>Appointment time</dt><dd>{{ formatTime(selectedAppointment.appointment_time) }}</dd></div>
                <div><dt>Appointment status</dt><dd><span class="badge" :class="selectedAppointment.status">{{ formatStatus(selectedAppointment.status) }}</span></dd></div>
                <div><dt>Request status</dt><dd><span class="badge" :class="selectedAppointment.document_request.status">{{ formatStatus(selectedAppointment.document_request.status) }}</span></dd></div>
              </dl>
              <p v-if="selectedAppointment.remarks" class="student-detail-note"><strong>Notes:</strong> {{ selectedAppointment.remarks }}</p>
              <div class="student-detail-actions">
                <button
                  v-if="appointmentCanBeCancelled(selectedAppointment)"
                  type="button"
                  class="student-danger-action"
                  @click="openCancellation(selectedAppointment)"
                >
                  Cancel Appointment
                </button>
                <button type="button" class="student-secondary-action" @click="selectRequest(selectedAppointment.document_request_id)">View Request</button>
              </div>
            </template>
            <p v-else class="student-panel-state">No appointments have been booked yet.</p>
          </template>
        </article>
      </Transition>
    </section>
  </section>

  <section class="student-history-panel">
    <header class="student-history-heading">
      <div><p class="page-kicker">Past activity</p><h2>History</h2></div>
      <span>{{ historyItems.length }} record{{ historyItems.length === 1 ? '' : 's' }}</span>
    </header>
    <p v-if="!historyItems.length" class="student-panel-state">Completed or cancelled activity will appear here.</p>
    <div v-else class="student-history-list">
      <button
        v-for="item in visibleHistory"
        :key="item.id"
        type="button"
        class="student-history-row"
        @click="selectRequest(item.id, true)"
      >
        <span><strong>{{ item.document_type.document_name }}</strong><small>{{ requestReference(item.id) }} · {{ formatDate(item.request_date) }}</small></span>
        <span class="badge" :class="item.status">{{ formatStatus(item.status) }}</span>
        <span v-if="item.appointments?.length" class="student-history-appointment">
          Appointment {{ formatStatus(item.appointments[0].status) }}
        </span>
        <span class="student-history-chevron" aria-hidden="true">›</span>
      </button>
    </div>
    <button
      v-if="visibleHistory.length < historyItems.length"
      type="button"
      class="student-history-more"
      @click="historyLimit += 8"
    >
      Show more history
    </button>
  </section>

  <Teleport to="body">
    <Transition name="student-modal" appear>
      <div
        v-if="showRequestForm"
        class="appointment-details-backdrop student-workflow-backdrop"
        role="presentation"
        @mousedown.self="closeRequestForm"
        @keydown.esc="closeRequestForm"
      >
        <section
          ref="requestDialog"
          class="appointment-details-modal student-request-modal"
          role="dialog"
          aria-modal="true"
          aria-labelledby="student-request-modal-title"
          tabindex="-1"
        >
          <header class="appointment-details-header student-modal-header">
            <div>
              <p class="page-kicker">Student document request</p>
              <h2 id="student-request-modal-title">New Document Request</h2>
              <p>Choose the official document you need and review its processing details.</p>
            </div>
            <button
              type="button"
              class="student-modal-close"
              aria-label="Close new request modal"
              :disabled="requestSubmitting"
              @click="closeRequestForm"
            >
              ×
            </button>
          </header>

          <form class="student-modal-form" @submit.prevent="submitRequest">
            <div class="appointment-details-body student-request-modal-body">
              <div class="form-grid student-request-form-grid">
                <label class="wide">
                  Document type
                  <select v-model="selectedTypeId" :disabled="requestFormLoading || requestSubmitting" required>
                    <option value="" disabled>{{ requestFormLoading && !documentTypes.length ? 'Loading documents…' : 'Select a document' }}</option>
                    <option v-for="type in documentTypes" :key="type.id" :value="type.id">{{ documentTypeLabel(type) }}</option>
                  </select>
                </label>
                <label>
                  Copies
                  <input v-model="quantity" type="number" min="1" max="10" :disabled="requestSubmitting" required />
                </label>
                <label class="wide">
                  Purpose / remarks
                  <textarea
                    v-model="purpose"
                    rows="4"
                    :disabled="requestSubmitting"
                    placeholder="Tell us why you need this document (optional)."
                  ></textarea>
                </label>
              </div>

              <aside v-if="selectedType" class="student-request-summary" aria-live="polite">
                <strong>Request summary</strong>
                <span>{{ selectedType.document_name }} · {{ quantity }} {{ Number(quantity) === 1 ? 'copy' : 'copies' }}</span>
                <span>Processing time: {{ selectedType.processing_days }} day(s)</span>
                <span>{{ selectedType.requires_appointment ? 'An appointment is required.' : 'No appointment is required.' }}</span>
                <span>Estimated fee: ₱{{ formatMoney(Number(selectedType.processing_fee) * Number(quantity || 0)) }}</span>
              </aside>

              <p v-if="requestFormError" class="notice error" role="alert">{{ requestFormError }}</p>
            </div>

            <footer class="appointment-details-footer">
              <button type="button" class="appointment-close-action" :disabled="requestSubmitting" @click="closeRequestForm">Cancel</button>
              <button type="submit" :disabled="requestFormLoading || requestSubmitting || !selectedTypeId">
                {{ requestSubmitting ? 'Submitting…' : 'Submit Request' }}
              </button>
            </footer>
          </form>
        </section>
      </div>
    </Transition>
  </Teleport>

  <Teleport to="body">
    <Transition name="student-modal" appear>
      <div
        v-if="bookingRequest"
        class="appointment-details-backdrop student-workflow-backdrop"
        role="presentation"
        @mousedown.self="closeBooking"
        @keydown.esc="closeBooking"
      >
        <section
          ref="bookingDialog"
          class="appointment-details-modal student-booking-modal"
          role="dialog"
          aria-modal="true"
          aria-labelledby="student-booking-title"
          tabindex="-1"
        >
          <header class="appointment-details-header student-modal-header">
            <div>
              <p class="page-kicker">Student appointment</p>
              <h2 id="student-booking-title">Book Appointment</h2>
              <p>Choose an available date and time for your document request.</p>
            </div>
            <button type="button" class="student-modal-close" aria-label="Close booking modal" :disabled="bookingInProgress" @click="closeBooking">×</button>
          </header>

          <form class="student-modal-form" @submit.prevent="book">
            <div class="appointment-details-body student-booking-body">
              <dl class="appointment-detail-grid student-booking-request-summary">
                <div><dt>Request reference</dt><dd>{{ requestReference(bookingRequest.id) }}</dd></div>
                <div><dt>Document type</dt><dd>{{ bookingRequest.document_type.document_name }}</dd></div>
                <div><dt>Request status</dt><dd><span class="badge" :class="bookingRequest.status">{{ formatStatus(bookingRequest.status) }}</span></dd></div>
              </dl>

              <div class="student-booking-grid">
                <fieldset class="appointment-calendar">
                  <legend>Available Dates</legend>
                  <div class="calendar-toolbar">
                    <button type="button" class="calendar-nav" :disabled="isCurrentMonth" aria-label="Previous month" @click="changeMonth(-1)">&lsaquo;</button>
                    <strong>{{ calendarLabel }}</strong>
                    <button type="button" class="calendar-nav" aria-label="Next month" @click="changeMonth(1)">&rsaquo;</button>
                  </div>
                  <div class="calendar-grid" role="grid" :aria-label="calendarLabel">
                    <span v-for="weekday in weekdayLabels" :key="weekday" class="calendar-weekday" role="columnheader">{{ weekday }}</span>
                    <template v-for="day in calendarDays" :key="day.key">
                      <span v-if="day.blank" class="calendar-blank" aria-hidden="true"></span>
                      <button
                        v-else
                        type="button"
                        class="calendar-day"
                        :class="{ selected: appointmentDate === day.date, unavailable: day.unavailable }"
                        :disabled="day.disabled"
                        :aria-label="day.unavailable ? `${day.date}: ${day.unavailable.reason}, unavailable` : day.date"
                        @click="selectDate(day)"
                      >
                        <span>{{ day.day }}</span><small v-if="day.unavailable">{{ day.unavailable.reason }}</small>
                      </button>
                    </template>
                  </div>
                  <p class="calendar-selection">Selected date: {{ appointmentDate ? formatDate(appointmentDate) : 'None' }}</p>
                </fieldset>

                <fieldset class="appointment-times" :aria-busy="slotLoading">
                  <legend>Available Times</legend>
                  <p v-if="!appointmentDate" class="appointment-time-hint">Choose an available date to see appointment times.</p>
                  <p v-else-if="slotLoading" class="appointment-time-hint" role="status">Loading available times...</p>
                  <template v-else>
                    <div v-if="slots.length" class="appointment-time-grid">
                      <button
                        v-for="slot in slots"
                        :key="slot.time"
                        type="button"
                        class="appointment-time-option"
                        :class="{ selected: appointmentTime === slot.time }"
                        :disabled="!slot.available"
                        :aria-pressed="appointmentTime === slot.time"
                        :aria-label="`${slot.label}${slot.available ? '' : `, ${slot.reason || 'unavailable'}`}`"
                        @click="selectTime(slot)"
                      >
                        <span>{{ slot.label }}</span><strong v-if="appointmentTime === slot.time" aria-hidden="true">✓</strong>
                        <small v-else-if="!slot.available">{{ slot.reason || 'Unavailable' }}</small>
                      </button>
                    </div>
                    <p v-if="appointmentDate && !availableSlots.length && !slotError" class="appointment-time-empty" role="status">
                      {{ slotDateReason || 'No appointment times are available for this date.' }}<br />Please choose another date.
                    </p>
                  </template>
                  <p class="calendar-selection">Selected time: {{ appointmentTime ? formatTime(appointmentTime) : 'None' }}</p>
                  <p v-if="slotError" class="appointment-time-error" role="alert">{{ slotError }}</p>
                </fieldset>
              </div>
              <p v-if="bookingError" class="notice error" role="alert">{{ bookingError }}</p>
            </div>

            <footer class="appointment-details-footer">
              <button type="button" class="appointment-close-action" :disabled="bookingInProgress" @click="closeBooking">Cancel</button>
              <button type="submit" :disabled="!canBook">{{ bookingInProgress ? 'Booking…' : 'Book Appointment' }}</button>
            </footer>
          </form>
        </section>
      </div>
    </Transition>
  </Teleport>

  <Teleport to="body">
    <Transition name="student-modal" appear>
      <div
        v-if="cancellingRequest"
        class="appointment-details-backdrop student-workflow-backdrop"
        role="presentation"
        @mousedown.self="closeRequestCancellation"
        @keydown.esc="closeRequestCancellation"
      >
        <section
          ref="requestCancellationDialog"
          class="appointment-details-modal student-request-cancellation-modal"
          role="dialog"
          aria-modal="true"
          aria-labelledby="student-request-cancellation-title"
          tabindex="-1"
        >
          <header class="appointment-details-header student-modal-header">
            <div>
              <p class="page-kicker">Student document request</p>
              <h2 id="student-request-cancellation-title">Cancel Document Request</h2>
              <p>This action also cancels any pending or confirmed appointment linked to this request.</p>
            </div>
            <button type="button" class="student-modal-close" aria-label="Close request cancellation modal" :disabled="requestCancellationInProgress" @click="closeRequestCancellation">×</button>
          </header>
          <form class="student-modal-form" @submit.prevent="cancelDocumentRequest">
            <div class="appointment-details-body">
              <dl class="appointment-detail-grid">
                <div><dt>Request reference</dt><dd>{{ requestReference(cancellingRequest.id) }}</dd></div>
                <div><dt>Document type</dt><dd>{{ cancellingRequest.document_type.document_name }}</dd></div>
              </dl>
              <label class="student-cancellation-reason">
                Reason for cancellation
                <textarea
                  v-model="requestCancellationReason"
                  rows="4"
                  maxlength="2000"
                  :disabled="requestCancellationInProgress"
                  placeholder="Explain why you need to cancel this document request."
                  required
                ></textarea>
              </label>
              <p v-if="requestCancellationError" class="notice error" role="alert">{{ requestCancellationError }}</p>
            </div>
            <footer class="appointment-details-footer">
              <button type="button" class="appointment-close-action" :disabled="requestCancellationInProgress" @click="closeRequestCancellation">Keep Request</button>
              <button type="submit" class="student-confirm-cancellation" :disabled="requestCancellationInProgress || !requestCancellationReason.trim()">
                {{ requestCancellationInProgress ? 'Cancelling…' : 'Cancel Request' }}
              </button>
            </footer>
          </form>
        </section>
      </div>
    </Transition>
  </Teleport>

  <Teleport to="body">
    <Transition name="student-modal" appear>
      <div v-if="cancellingAppointment" class="appointment-details-backdrop student-workflow-backdrop" role="presentation" @mousedown.self="closeCancellation" @keydown.esc="closeCancellation">
        <section ref="cancellationDialog" class="appointment-details-modal student-cancellation-modal" role="dialog" aria-modal="true" aria-labelledby="student-cancellation-title" tabindex="-1">
        <header class="appointment-details-header">
          <div><p class="page-kicker">Student appointment</p><h2 id="student-cancellation-title">Cancel Appointment</h2><p>Review the schedule and tell the Registrar why you need to cancel.</p></div>
        </header>
        <form class="student-cancellation-form" @submit.prevent="cancelAppointment">
          <div class="appointment-details-body">
            <dl class="appointment-detail-grid">
              <div><dt>Date</dt><dd>{{ formatDate(cancellingAppointment.appointment_date) }}</dd></div>
              <div><dt>Time</dt><dd>{{ formatTime(cancellingAppointment.appointment_time) }}</dd></div>
              <div><dt>Document request</dt><dd>{{ requestReference(cancellingAppointment.document_request_id) }}</dd></div>
              <div><dt>Document type</dt><dd>{{ cancellingAppointment.document_request?.document_type?.document_name || 'Not available' }}</dd></div>
            </dl>
            <label class="student-cancellation-reason">Reason<textarea v-model="cancellationReason" rows="4" maxlength="2000" :disabled="cancellationInProgress" placeholder="Explain why you need to cancel this appointment." required></textarea></label>
            <p v-if="cancellationError" class="notice error" role="alert">{{ cancellationError }}</p>
          </div>
          <footer class="appointment-details-footer">
            <button type="button" class="appointment-close-action" :disabled="cancellationInProgress" @click="closeCancellation">Keep Appointment</button>
            <button type="submit" class="student-confirm-cancellation" :disabled="cancellationInProgress || !cancellationReason.trim()">{{ cancellationInProgress ? 'Cancelling…' : 'Cancel Appointment' }}</button>
          </footer>
        </form>
      </section>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped src="./documentRequest.css"></style>
