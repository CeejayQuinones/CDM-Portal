<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PaginationControls from '../../components/PaginationControls.vue'
import DocumentRequestDialog from './DocumentRequestDialog.vue'
import DocumentRequestEmptyState from './DocumentRequestEmptyState.vue'
import DocumentRequestPageHeader from './DocumentRequestPageHeader.vue'
import DocumentRequestStatusBadge from './DocumentRequestStatusBadge.vue'
import DocumentRequestTableSkeleton from './DocumentRequestTableSkeleton.vue'
import { formatExactDateTime, requestReference } from './documentRequestPresentation'
import { documentRequestService as api } from './documentRequestService'

const route = useRoute()
const router = useRouter()
const requests = ref([])
const documentTypes = ref([])
const selectedPanel = ref('request')
const selectedRequestId = ref(null)
const selectedAppointmentId = ref(null)
const loading = ref(false)
const message = ref('')
const error = ref('')
const historyLimit = ref(8)
const detailPanel = ref(null)
const requestDetailOpen = ref(false)
const studentSearch = ref('')
const studentStatus = ref('')
const studentRequestPage = ref(1)
const STUDENT_REQUEST_PAGE_SIZE = 10

const showRequestForm = ref(false)
const requestFormLoading = ref(false)
const requestSubmitting = ref(false)
const requestFormError = ref('')
const selectedTypeId = ref('')
const quantity = ref(1)
const purpose = ref('')

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
const currentDate = new Date()
const today = dateKey(currentDate.getFullYear(), currentDate.getMonth(), currentDate.getDate())
const calendarMonth = ref(new Date(currentDate.getFullYear(), currentDate.getMonth(), 1))
const weekdayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
let slotRequestSequence = 0

const cancellingAppointment = ref(null)
const cancellationReason = ref('')
const cancellationError = ref('')
const cancellationInProgress = ref(false)

const cancellingRequest = ref(null)
const requestCancellationReason = ref('')
const requestCancellationError = ref('')
const requestCancellationInProgress = ref(false)

const activeRequestStatuses = ['pending', 'approved']
const activeAppointmentStatuses = ['pending', 'confirmed']
const terminalRequestStatuses = ['completed', 'rejected', 'cancelled']
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
  return false
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
const releasedRequests = computed(() => requests.value.filter((item) => item.status === 'completed'))
const filteredStudentRequests = computed(() => {
  const term = studentSearch.value.trim().toLowerCase()

  return requests.value.filter((item) => {
    const matchesStatus = !studentStatus.value || item.status === studentStatus.value
    const matchesSearch =
      !term ||
      [requestReference(item.id), item.document_type?.document_name, item.purpose]
        .some((value) => String(value || '').toLowerCase().includes(term))

    return matchesStatus && matchesSearch
  })
})
const studentRequestLastPage = computed(() => Math.max(1, Math.ceil(filteredStudentRequests.value.length / STUDENT_REQUEST_PAGE_SIZE)))
const visibleStudentRequests = computed(() => {
  const start = (studentRequestPage.value - 1) * STUDENT_REQUEST_PAGE_SIZE

  return filteredStudentRequests.value.slice(start, start + STUDENT_REQUEST_PAGE_SIZE)
})
const requestProgressEvents = computed(() => {
  const item = selectedRequest.value
  if (!item) return []
  const events = [
    { key: 'requested', label: 'Requested', timestamp: item.created_at || item.request_date, status: 'pending' },
  ]
  if (item.approved_at) events.push({ key: 'approved', label: 'Ready for Release', timestamp: item.approved_at, status: 'approved' })
  if (item.completed_at) events.push({ key: 'completed', label: 'Released', timestamp: item.completed_at, status: 'completed' })
  if (item.rejected_at) events.push({ key: 'rejected', label: 'Rejected', timestamp: item.rejected_at, status: 'rejected' })
  if (item.cancelled_at) events.push({ key: 'cancelled', label: 'Cancelled', timestamp: item.cancelled_at, status: 'cancelled' })

  return events.filter((event) => event.timestamp)
})
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

function viewStudentRequest(item) {
  selectedRequestId.value = item.id
  requestDetailOpen.value = true
}

function closeStudentRequest() {
  requestDetailOpen.value = false
}

function resetStudentFilters() {
  studentSearch.value = ''
  studentStatus.value = ''
  studentRequestPage.value = 1
}

function changeStudentRequestPage(page) {
  studentRequestPage.value = page
}

function cancelRequestFromDetails() {
  const item = selectedRequest.value
  closeStudentRequest()
  if (item) nextTick(() => openRequestCancellation(item))
}

function cancelAppointmentFromDetails(appointment) {
  closeStudentRequest()
  nextTick(() => openCancellation({ ...appointment, document_request: selectedRequest.value }))
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

watch([studentSearch, studentStatus], () => {
  studentRequestPage.value = 1
})

onMounted(refresh)
</script>

<template>
  <DocumentRequestPageHeader
    eyebrow="Student Services"
    title="Document Requests"
    description="Request official documents and track each request through the Registrar-assigned appointment workflow."
  >
    <template #actions>
      <button type="button" class="dr-button dr-button--primary" @click="openRequestForm">Request a Document</button>
    </template>
  </DocumentRequestPageHeader>

  <p v-if="message" class="notice success" role="status">{{ message }}</p>
  <p v-if="error" class="notice error" role="alert">{{ error }}</p>

  <section class="dr-summary-row student-request-summary" aria-label="Document request summary">
    <article class="dr-summary-card"><span>Active requests</span><strong>{{ activeRequests.length }}</strong></article>
    <article class="dr-summary-card"><span>Upcoming appointments</span><strong>{{ activeAppointments.length }}</strong></article>
    <article class="dr-summary-card"><span>Released documents</span><strong>{{ releasedRequests.length }}</strong></article>
  </section>

  <section class="dr-filter-bar student-request-filter" aria-label="My request filters">
    <label>Search<input v-model="studentSearch" type="search" placeholder="Request ID or document type" /></label>
    <label>Status<select v-model="studentStatus"><option value="">All statuses</option><option value="pending">Pending</option><option value="approved">Ready for Release</option><option value="completed">Released</option><option value="rejected">Rejected</option><option value="cancelled">Cancelled</option></select></label>
    <button type="button" class="dr-button dr-button--secondary" :disabled="!studentSearch && !studentStatus" @click="resetStudentFilters">Reset</button>
  </section>

  <section class="dr-table-panel student-requests-panel" aria-labelledby="my-requests-title">
    <header class="dr-records-heading">
      <div><h2 id="my-requests-title">My Requests</h2><p>Track active requests and review previous outcomes.</p></div>
    </header>
    <DocumentRequestTableSkeleton v-if="loading && !requests.length" :columns="6" :rows="5" label="Loading your document requests" />
    <DocumentRequestEmptyState
      v-else-if="!filteredStudentRequests.length"
      :message="studentSearch || studentStatus ? 'No requests match your filters.' : 'You have not requested any documents yet.'"
    />
    <div v-else class="dr-table-scroll">
      <table class="dr-table student-requests-table">
        <thead><tr><th scope="col">Request ID</th><th scope="col">Document</th><th scope="col">Requested</th><th scope="col">Status</th><th scope="col">Last Updated</th><th scope="col">Action</th></tr></thead>
        <tbody>
          <tr v-for="item in visibleStudentRequests" :key="item.id">
            <td><strong class="history-request-reference">{{ requestReference(item.id) }}</strong></td>
            <td><strong>{{ item.document_type.document_name }}</strong><small v-if="item.purpose">{{ item.purpose }}</small></td>
            <td>{{ formatDate(item.request_date || item.created_at) }}</td>
            <td><DocumentRequestStatusBadge :status="item.status" /></td>
            <td>{{ formatExactDateTime(item.completed_at || item.rejected_at || item.cancelled_at || item.approved_at || item.created_at) }}</td>
            <td><button type="button" class="dr-button dr-button--secondary" @click="viewStudentRequest(item)">View</button></td>
          </tr>
        </tbody>
      </table>
    </div>
    <PaginationControls
      v-if="filteredStudentRequests.length"
      :current-page="studentRequestPage"
      :last-page="studentRequestLastPage"
      :total="filteredStudentRequests.length"
      total-label="requests"
      aria-label="My document request pages"
      @page-change="changeStudentRequestPage"
    />
  </section>

  <DocumentRequestDialog
    v-if="requestDetailOpen && selectedRequest"
    labelledby="student-request-detail-title"
    wide
    @cancel="closeStudentRequest"
  >
    <header class="dr-dialog-header">
      <div><h2 id="student-request-detail-title">Request Details</h2><p>{{ requestReference(selectedRequest.id) }} · {{ selectedRequest.document_type.document_name }}</p></div>
      <button type="button" class="dr-dialog-close" aria-label="Close request details" @click="closeStudentRequest">×</button>
    </header>
    <div class="dr-dialog-body student-request-detail-body">
      <section class="student-request-detail-section">
        <h3>Document Request</h3>
        <dl class="student-request-facts">
          <div><dt>Request ID</dt><dd>{{ requestReference(selectedRequest.id) }}</dd></div>
          <div><dt>Document</dt><dd>{{ selectedRequest.document_type.document_name }}</dd></div>
          <div><dt>Copies</dt><dd>{{ selectedRequest.quantity || 1 }}</dd></div>
          <div><dt>Requested</dt><dd>{{ formatDate(selectedRequest.request_date || selectedRequest.created_at) }}</dd></div>
          <div><dt>Fee</dt><dd>₱{{ formatMoney(selectedRequest.total_fee) }}</dd></div>
          <div><dt>Status</dt><dd><DocumentRequestStatusBadge :status="selectedRequest.status" /></dd></div>
          <div class="wide"><dt>Purpose</dt><dd>{{ selectedRequest.purpose || 'No purpose provided.' }}</dd></div>
          <div v-if="selectedRequest.remarks" class="wide"><dt>Registrar remarks</dt><dd>{{ selectedRequest.remarks }}</dd></div>
          <div v-if="selectedRequest.cancellation_reason" class="wide"><dt>Cancellation reason</dt><dd>{{ selectedRequest.cancellation_reason }}</dd></div>
        </dl>
        <p v-if="selectedRequest.status === 'approved'" class="student-request-guidance">Your request is ready for release. Check your registered email for your verification code and appointment instructions.</p>
      </section>

      <section class="student-request-detail-section">
        <h3>Status History</h3>
        <ol class="student-request-timeline">
          <li v-for="event in requestProgressEvents" :key="event.key" :class="`timeline-${event.status}`">
            <span class="student-timeline-marker" aria-hidden="true"></span>
            <div><strong>{{ event.label }}</strong><time :datetime="event.timestamp">{{ formatExactDateTime(event.timestamp) }}</time></div>
          </li>
        </ol>
      </section>

      <section class="student-request-detail-section">
        <h3>Appointment</h3>
        <DocumentRequestEmptyState v-if="!selectedRequest.appointments?.length" message="Not assigned yet." />
        <ul v-else class="student-request-appointments">
          <li v-for="appointment in selectedRequest.appointments" :key="appointment.id">
            <span><strong>{{ formatDate(appointment.appointment_date) }} · {{ formatTime(appointment.appointment_time) }}</strong><small v-if="appointment.remarks">{{ appointment.remarks }}</small></span>
            <span class="student-appointment-record-actions">
              <span class="badge" :class="appointment.status">{{ formatStatus(appointment.status) }}</span>
              <button v-if="appointmentCanBeCancelled({ ...appointment, document_request: selectedRequest })" type="button" class="dr-button dr-button--danger" @click="cancelAppointmentFromDetails(appointment)">Cancel Appointment</button>
            </span>
          </li>
        </ul>
      </section>
    </div>
    <footer class="dr-dialog-footer">
      <button v-if="canCancelSelectedRequest" type="button" class="dr-button dr-button--danger" @click="cancelRequestFromDetails">Cancel Request</button>
      <button type="button" class="dr-button dr-button--secondary" @click="closeStudentRequest">Close</button>
    </footer>
  </DocumentRequestDialog>

  <DocumentRequestDialog
    v-if="showRequestForm"
    labelledby="student-request-modal-title"
    panel-class="student-request-modal"
    :busy="requestSubmitting"
    @cancel="closeRequestForm"
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
  </DocumentRequestDialog>

  <DocumentRequestDialog
    v-if="bookingRequest"
    labelledby="student-booking-title"
    panel-class="student-booking-modal"
    wide
    :busy="bookingInProgress"
    @cancel="closeBooking"
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
  </DocumentRequestDialog>

  <DocumentRequestDialog
    v-if="cancellingRequest"
    labelledby="student-request-cancellation-title"
    panel-class="student-request-cancellation-modal"
    :busy="requestCancellationInProgress"
    @cancel="closeRequestCancellation"
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
  </DocumentRequestDialog>

  <DocumentRequestDialog
    v-if="cancellingAppointment"
    labelledby="student-cancellation-title"
    panel-class="student-cancellation-modal"
    :busy="cancellationInProgress"
    @cancel="closeCancellation"
  >
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
  </DocumentRequestDialog>
</template>

<style scoped src="./documentRequest.css"></style>
