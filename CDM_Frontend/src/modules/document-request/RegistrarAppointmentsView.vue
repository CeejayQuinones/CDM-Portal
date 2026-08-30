<script setup>
import { nextTick, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PaginationControls from '../../components/PaginationControls.vue'
import RegistrarRecentActivity from './RegistrarRecentActivity.vue'
import RequestWorkflowReasonModal from './RequestWorkflowReasonModal.vue'
import { rememberDocumentRequestFocus } from './documentRequestFocus'
import {
  appointmentDateTime,
  documentTypeAccentClass,
  formatExactDateTime,
  formatRelativeTime,
  requestReference,
  requestStatusAccentClass,
  studentName,
  TIME_FILTERS,
} from './documentRequestPresentation'
import { requestDocumentName, requestStudentNumber } from './documentRequestRow'
import { documentRequestService as api } from './documentRequestService'

const route = useRoute()
const router = useRouter()
const appointments = ref([])
const readyRequests = ref([])
const search = ref('')
const date = ref('')
const status = ref('')
const group = ref('active')
const timeFilter = ref('all')
const loading = ref(false)
const message = ref('')
const error = ref('')
const page = ref(1)
const lastPage = ref(1)
const releasePage = ref(1)
const releaseLastPage = ref(1)
const releaseTotal = ref(0)
const releasingId = ref(null)
const returningId = ref(null)
const returnTarget = ref(null)
const returnDialogOpen = ref(false)
const requestIdFilter = ref(null)
const appointmentIdFilter = ref(null)
const focusedAppointmentId = ref(null)
const availabilityOpen = ref(false)
const availabilityDialog = ref(null)
const blockedDateForm = ref(null)
const blockedDates = ref([])
const availabilityLoading = ref(false)
const availabilityLoaded = ref(false)
const blockedDateSubmitting = ref(false)
const savingWeekendSettings = ref(false)
const availabilityMessage = ref('')
const availabilityError = ref('')
const editingBlockedDateId = ref(null)
const weekendSettings = reactive({
  block_saturday: true,
  block_sunday: true,
})
const blockedDateTypes = [
  { value: 'holiday', label: 'Holiday' },
  { value: 'maintenance', label: 'Maintenance' },
  { value: 'office_closure', label: 'Office Closure' },
  { value: 'school_event', label: 'School Event' },
  { value: 'other', label: 'Other' },
]
const blockedDate = reactive({
  blocked_date: '',
  type: 'office_closure',
  reason: '',
  is_active: true,
})
const statuses = ['pending', 'confirmed']
const activeStatuses = ['pending', 'confirmed']
const activeGroups = [
  { value: 'active', label: 'All Active' },
  { value: 'recent', label: 'Recent' },
  { value: 'upcoming', label: 'Upcoming' },
  { value: 'today', label: 'Today' },
]
const RETURN_REASONS = [
  'Wrong request selected',
  'Document preparation error',
  'Incorrect document',
  'Needs correction',
  'Other',
]
const requestError = (err) => err.response?.data?.message || 'The appointment could not be completed.'
const availabilityRequestError = (err) =>
  Object.values(err.response?.data?.errors || {})[0]?.[0] ||
  err.response?.data?.message ||
  'The appointment availability settings could not be saved.'
const queryValue = (value) => (Array.isArray(value) ? value[0] : value)
const positiveId = (value) => {
  const id = Number(queryValue(value))

  return Number.isInteger(id) && id > 0 ? id : null
}
const focusedId = (value) => {
  const match = String(queryValue(value) || '').match(/^appointment-(\d+)$/)

  return match ? positiveId(match[1]) : null
}
const referenceFor = (appointment) =>
  appointment?.document_request?.request_reference ||
  requestReference(appointment?.document_request_id || appointment?.document_request?.id)
const appointmentTimestamp = (appointment) => {
  if (!appointment?.appointment_date) return null

  return `${String(appointment.appointment_date).slice(0, 10)}T${String(appointment.appointment_time || '00:00').slice(0, 8)}`
}
const releaseTimestamp = (request) => request?.ready_for_release_at || request?.updated_at || null

function blockedDateLabel(value) {
  return new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium' }).format(new Date(`${value}T00:00:00`))
}

function blockedDateTypeLabel(value) {
  return blockedDateTypes.find((option) => option.value === value)?.label || value.replaceAll('_', ' ')
}

function resetBlockedDateForm() {
  editingBlockedDateId.value = null
  Object.assign(blockedDate, {
    blocked_date: '',
    type: 'office_closure',
    reason: '',
    is_active: true,
  })
}

function upsertBlockedDate(item) {
  blockedDates.value = [item, ...blockedDates.value.filter((blocked) => blocked.id !== item.id)].sort(
    (left, right) => String(right.blocked_date).localeCompare(String(left.blocked_date)),
  )
}

async function loadAvailabilitySettings() {
  if (availabilityLoaded.value || availabilityLoading.value) return

  availabilityLoading.value = true
  availabilityError.value = ''
  try {
    const [dates, settings] = await Promise.all([
      api.appointmentBlockedDates(),
      api.appointmentAvailabilitySettings(),
    ])
    blockedDates.value = dates
    Object.assign(weekendSettings, settings)
    availabilityLoaded.value = true
  } catch (err) {
    availabilityError.value = availabilityRequestError(err)
  } finally {
    availabilityLoading.value = false
  }
}

async function openAvailabilitySettings() {
  resetBlockedDateForm()
  availabilityMessage.value = ''
  availabilityError.value = ''
  availabilityOpen.value = true
  await nextTick()
  availabilityDialog.value?.focus()
  await loadAvailabilitySettings()
}

function closeAvailabilitySettings() {
  availabilityOpen.value = false
  availabilityMessage.value = ''
  availabilityError.value = ''
  resetBlockedDateForm()
}

async function saveWeekendSettings() {
  savingWeekendSettings.value = true
  availabilityMessage.value = ''
  availabilityError.value = ''
  try {
    const response = await api.updateAppointmentAvailabilitySettings({ ...weekendSettings })
    Object.assign(weekendSettings, response.data)
    availabilityMessage.value = response.message
  } catch (err) {
    availabilityError.value = availabilityRequestError(err)
  } finally {
    savingWeekendSettings.value = false
  }
}

async function saveBlockedDate() {
  blockedDateSubmitting.value = true
  availabilityMessage.value = ''
  availabilityError.value = ''
  try {
    const response = editingBlockedDateId.value
      ? await api.updateAppointmentBlockedDate(editingBlockedDateId.value, { ...blockedDate })
      : await api.createAppointmentBlockedDate({ ...blockedDate })
    availabilityMessage.value = response.message
    upsertBlockedDate(response.data)
    resetBlockedDateForm()
  } catch (err) {
    availabilityError.value = availabilityRequestError(err)
  } finally {
    blockedDateSubmitting.value = false
  }
}

async function editBlockedDate(item) {
  editingBlockedDateId.value = item.id
  Object.assign(blockedDate, {
    blocked_date: item.blocked_date,
    type: item.type,
    reason: item.reason,
    is_active: item.is_active,
  })
  await nextTick()
  blockedDateForm.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

async function toggleBlockedDate(item) {
  availabilityMessage.value = ''
  availabilityError.value = ''
  try {
    const response = await api.updateAppointmentBlockedDate(item.id, {
      is_active: !item.is_active,
    })
    availabilityMessage.value = response.message
    upsertBlockedDate(response.data)
  } catch (err) {
    availabilityError.value = availabilityRequestError(err)
  }
}

async function deleteBlockedDate(item) {
  if (!window.confirm(`Delete the block for ${blockedDateLabel(item.blocked_date)}?`)) return

  availabilityMessage.value = ''
  availabilityError.value = ''
  try {
    const response = await api.deleteAppointmentBlockedDate(item.id)
    availabilityMessage.value = response.message
    if (editingBlockedDateId.value === item.id) resetBlockedDateForm()
    blockedDates.value = blockedDates.value.filter((blocked) => blocked.id !== item.id)
  } catch (err) {
    availabilityError.value = availabilityRequestError(err)
  }
}

function applyRouteQuery(query) {
  const nextStatus = String(queryValue(query.status) || '')
  const nextGroup = String(queryValue(query.group) || 'active')
  const nextTimeFilter = String(queryValue(query.time_filter) || 'all')

  search.value = String(queryValue(query.search) || '')
  date.value = String(queryValue(query.date) || '')
  status.value = statuses.includes(nextStatus) ? nextStatus : ''
  group.value = activeGroups.some((option) => option.value === nextGroup) ? nextGroup : 'active'
  timeFilter.value = TIME_FILTERS.some((option) => option.value === nextTimeFilter) ? nextTimeFilter : 'all'
  requestIdFilter.value = positiveId(query.request_id)
  appointmentIdFilter.value = positiveId(query.appointment_id)
  focusedAppointmentId.value = focusedId(query.focus) || appointmentIdFilter.value
  page.value = positiveId(query.page) || 1
  releasePage.value = positiveId(query.release_page) || 1
}

async function refresh(resetPage = false) {
  if (resetPage) {
    page.value = 1
    releasePage.value = 1
  }
  loading.value = true
  error.value = ''
  try {
    const [appointmentResult, releaseResult] = await Promise.all([
      api.registrarAppointments({
        search: search.value || undefined,
        date: date.value || undefined,
        status: status.value || undefined,
        group: group.value,
        time_filter: timeFilter.value,
        request_id: requestIdFilter.value || undefined,
        appointment_id: appointmentIdFilter.value || undefined,
        page: page.value,
      }),
      api.registrarRequests({
        status: 'ready_for_release',
        search: search.value || undefined,
        time_filter: timeFilter.value,
        request_id: requestIdFilter.value || undefined,
        page: releasePage.value,
      }),
    ])
    appointments.value = appointmentResult.data
    page.value = appointmentResult.current_page || page.value
    lastPage.value = appointmentResult.last_page
    readyRequests.value = releaseResult.data
    releasePage.value = releaseResult.current_page || releasePage.value
    releaseLastPage.value = releaseResult.last_page
    releaseTotal.value = releaseResult.total
  } catch (err) {
    error.value = requestError(err)
  } finally {
    loading.value = false
  }
}

async function revealFocusedAppointment() {
  if (!focusedAppointmentId.value) return

  await nextTick()
  document
    .getElementById(`appointment-${focusedAppointmentId.value}`)
    ?.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

async function applyFilters() {
  requestIdFilter.value = null
  appointmentIdFilter.value = null
  focusedAppointmentId.value = null
  await refresh(true)
}

async function selectGroup(nextGroup) {
  group.value = nextGroup
  date.value = ''
  timeFilter.value = 'all'
  requestIdFilter.value = null
  appointmentIdFilter.value = null
  focusedAppointmentId.value = null
  await refresh(true)
}

function showHistory(appointmentStatus) {
  router.push({
    name: 'registrar-document-request-history',
    query: {
      section: 'appointments',
      appointment_status: appointmentStatus,
      time_filter: timeFilter.value,
    },
  })
}

async function changePage(nextPage) {
  page.value = nextPage
  focusedAppointmentId.value = null
  await refresh()
}

async function changeReleasePage(nextPage) {
  releasePage.value = nextPage
  await refresh()
}

function viewRequest(request) {
  router.push({
    name: 'registrar-document-requests',
    query: { request_id: request.id },
  })
}

function openReturnToProcessing(request) {
  returnTarget.value = request
  returnDialogOpen.value = true
}

function closeReturnDialog() {
  if (returningId.value) return
  returnDialogOpen.value = false
  returnTarget.value = null
}

async function confirmReturnToProcessing({ reason }) {
  if (!returnTarget.value) return

  error.value = ''
  message.value = ''
  returningId.value = returnTarget.value.id
  try {
    const request = returnTarget.value
    await api.updateRequest(request.id, {
      action: 'return_to_processing',
      reason,
    })
    readyRequests.value = readyRequests.value.filter((item) => item.id !== request.id)
    releaseTotal.value = Math.max(0, releaseTotal.value - 1)
    rememberDocumentRequestFocus(request.id)
    message.value = `${request.request_reference || requestReference(request.id)} returned to Processing.`
    returnDialogOpen.value = false
    returnTarget.value = null

    if (!readyRequests.value.length && releasePage.value > 1) {
      releasePage.value -= 1
      await refresh()
    }
  } catch (err) {
    error.value = requestError(err)
  } finally {
    returningId.value = null
  }
}

async function releaseDocument(request) {
  error.value = ''
  message.value = ''
  releasingId.value = request.id
  try {
    await api.updateRequest(request.id, {
      action: 'release',
      remarks: request.remarks || null,
    })
    readyRequests.value = readyRequests.value.filter((item) => item.id !== request.id)
    releaseTotal.value = Math.max(0, releaseTotal.value - 1)
    message.value = `${request.request_reference || requestReference(request.id)} released and moved to History.`

    if (!readyRequests.value.length && releasePage.value > 1) {
      releasePage.value -= 1
      await refresh()
    }
  } catch (err) {
    error.value = requestError(err)
  } finally {
    releasingId.value = null
  }
}

async function updateStatus(appointment, nextStatus) {
  error.value = ''
  message.value = ''
  try {
    const updated = await api.updateAppointment(appointment.id, {
      status: nextStatus,
    })
    if (!activeStatuses.includes(updated.status) || (status.value && status.value !== updated.status)) {
      appointments.value = appointments.value.filter((item) => item.id !== updated.id)
    } else {
      appointments.value = appointments.value.map((item) => (item.id === updated.id ? updated : item))
    }
    message.value = 'Appointment updated.'
    if (!appointments.value.length && page.value > 1) page.value -= 1
    await refresh()
  } catch (err) {
    error.value = requestError(err)
  }
}

watch(
  [
    () => queryValue(route.query.search),
    () => queryValue(route.query.date),
    () => queryValue(route.query.status),
    () => queryValue(route.query.group),
    () => queryValue(route.query.time_filter),
    () => queryValue(route.query.request_id),
    () => queryValue(route.query.appointment_id),
    () => queryValue(route.query.focus),
    () => queryValue(route.query.page),
    () => queryValue(route.query.release_page),
  ],
  async () => {
    applyRouteQuery(route.query)
    await refresh()
    await revealFocusedAppointment()
  },
  { flush: 'post', immediate: true },
)
</script>

<template>
  <section class="page-header registrar-appointments-header">
    <p class="page-kicker">Registrar Staff</p>
    <div class="appointments-title-row">
      <h1 class="page-title">Appointments &amp; Release</h1>
    </div>
    <p class="page-description">Manage scheduled pickups and release documents that are ready for students.</p>
  </section>

  <Teleport to="body">
    <button
      type="button"
      class="availability-settings-button"
      title="Appointment Availability Settings"
      aria-label="Appointment Availability Settings"
      @click="openAvailabilitySettings"
    >
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path
          d="M19.4 13a7.9 7.9 0 0 0 .05-1 7.9 7.9 0 0 0-.05-1l2.1-1.65-2-3.46-2.54 1.03a8.2 8.2 0 0 0-1.73-1L14.85 3h-4l-.38 2.92a8.2 8.2 0 0 0-1.73 1L6.2 5.89l-2 3.46L6.3 11a7.9 7.9 0 0 0-.05 1 7.9 7.9 0 0 0 .05 1l-2.1 1.65 2 3.46 2.54-1.03a8.2 8.2 0 0 0 1.73 1l.38 2.92h4l.38-2.92a8.2 8.2 0 0 0 1.73-1l2.54 1.03 2-3.46L19.4 13Zm-6.55 2.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7Z"
        />
      </svg>
      <span class="availability-settings-label">Availability</span>
    </button>
  </Teleport>

  <p v-if="message" class="notice success">{{ message }}</p>
  <p v-if="error" class="notice error">{{ error }}</p>

  <section class="dr-panel release-queue-panel" aria-labelledby="release-queue-title">
    <header class="release-queue-header">
      <div>
        <p class="record-eyebrow">Document handoff</p>
        <h2 id="release-queue-title">Ready for Release</h2>
        <p>Prepared requests remain here until the document is handed to the student.</p>
      </div>
      <strong class="work-queue-count" :aria-label="`${releaseTotal} requests ready for release`">
        {{ releaseTotal }}
      </strong>
    </header>
    <p v-if="loading && !readyRequests.length" class="empty">Loading ready requests&hellip;</p>
    <p v-else-if="!readyRequests.length" class="empty">No documents are currently ready for release.</p>
    <article
      v-for="request in readyRequests"
      :key="request.id"
      class="release-request-row"
      :class="requestStatusAccentClass(request.status)"
    >
      <div class="release-request-summary">
        <span class="compact-request-heading">
          <strong>{{ request.request_reference || requestReference(request.id) }}</strong>
          <time
            v-if="releaseTimestamp(request)"
            :datetime="releaseTimestamp(request)"
            :title="formatExactDateTime(releaseTimestamp(request))"
          >
            {{ formatRelativeTime(releaseTimestamp(request)) }}
          </time>
        </span>
        <strong>{{ studentName(request.student) }}</strong>
        <span class="compact-request-meta">
          <span class="document-type-chip" :class="documentTypeAccentClass(requestDocumentName(request))">
            {{ requestDocumentName(request) }}
          </span>
          <span>{{ requestStudentNumber(request) }}</span>
        </span>
      </div>
      <span class="release-request-actions">
        <button type="button" class="secondary" @click="viewRequest(request)">View Details</button>
        <button
          type="button"
          class="secondary"
          :disabled="releasingId === request.id || returningId === request.id"
          @click="openReturnToProcessing(request)"
        >
          Return to Processing
        </button>
        <button
          type="button"
          :disabled="releasingId === request.id || returningId === request.id"
          @click="releaseDocument(request)"
        >
          {{ releasingId === request.id ? 'Releasing…' : 'Release Document' }}
        </button>
      </span>
    </article>
    <PaginationControls
      :current-page="releasePage"
      :last-page="releaseLastPage"
      :busy="loading"
      aria-label="Ready for release request pages"
      @page-change="changeReleasePage"
    />
  </section>

  <RequestWorkflowReasonModal
    :open="returnDialogOpen"
    title="Return to Processing"
    description="Confirm why this prepared request needs more work. The reason and your identity are recorded."
    confirm-label="Return to Processing"
    :reason-options="RETURN_REASONS"
    :busy="Boolean(returningId)"
    @close="closeReturnDialog"
    @confirm="confirmReturnToProcessing"
  />

  <section class="dr-panel">
    <nav class="group-tabs" aria-label="Active appointment groups">
      <button
        v-for="option in activeGroups"
        :key="option.value"
        type="button"
        :class="{ active: group === option.value }"
        :aria-pressed="group === option.value"
        @click="selectGroup(option.value)"
      >
        {{ option.label }}
      </button>
      <button type="button" class="history-shortcut" @click="showHistory('completed')">Completed</button>
      <button type="button" class="history-shortcut" @click="showHistory('cancelled')">Cancelled</button>
    </nav>

    <form class="toolbar" @submit.prevent="applyFilters">
      <input v-model="search" placeholder="REQ-000001, student number, name, or document" />
      <input v-model="date" type="date" />
      <select v-model="status">
        <option value="">All active statuses</option>
        <option v-for="value in statuses" :key="value" :value="value">
          {{ value.replaceAll('_', ' ') }}
        </option>
      </select>
      <select v-model="timeFilter" aria-label="Appointment time period">
        <option v-for="option in TIME_FILTERS" :key="option.value" :value="option.value">
          {{ option.label }}
        </option>
      </select>
      <button :disabled="loading">Filter</button>
    </form>
    <p v-if="loading && !appointments.length" class="empty">Loading appointments…</p>
    <p v-else-if="!appointments.length" class="empty">No matching appointments.</p>
    <div
      v-for="appointment in appointments"
      :id="`appointment-${appointment.id}`"
      :key="appointment.id"
      class="appointment appointment-row"
      :class="{ 'focused-record': focusedAppointmentId === appointment.id }"
    >
      <span>
        <strong>
          {{ appointment.document_request.document_type.document_name }} · {{ referenceFor(appointment) }}
        </strong>
        <small>{{ studentName(appointment.student) }} ({{ appointment.student.student_number }})</small>
        <time
          v-if="appointmentTimestamp(appointment)"
          class="time-display"
          :datetime="appointmentTimestamp(appointment)"
          :title="appointmentDateTime(appointment.appointment_date, appointment.appointment_time)"
        >
          {{ appointmentDateTime(appointment.appointment_date, appointment.appointment_time) }}
        </time>
        <time
          v-if="appointment.updated_at"
          class="time-display"
          :datetime="appointment.updated_at"
          :title="formatExactDateTime(appointment.updated_at)"
        >
          Updated {{ formatRelativeTime(appointment.updated_at) }}
          <small>{{ formatExactDateTime(appointment.updated_at) }}</small>
        </time>
      </span>
      <span class="badge" :class="appointment.status">{{ appointment.status.replaceAll('_', ' ') }}</span>
      <span class="actions">
        <button v-if="appointment.status === 'pending'" @click="updateStatus(appointment, 'confirmed')">Confirm</button>
        <button v-if="appointment.status === 'confirmed'" @click="updateStatus(appointment, 'completed')">
          Complete
        </button>
        <button
          v-if="['pending', 'confirmed'].includes(appointment.status)"
          class="secondary"
          @click="updateStatus(appointment, 'cancelled')"
        >
          Cancel
        </button>
        <button v-if="appointment.status === 'confirmed'" class="danger" @click="updateStatus(appointment, 'no_show')">
          No show
        </button>
      </span>
    </div>
    <PaginationControls
      :current-page="page"
      :last-page="lastPage"
      :busy="loading"
      aria-label="Registrar appointment pages"
      @page-change="changePage"
    />
  </section>

  <RegistrarRecentActivity />

  <Teleport to="body">
    <div
      v-if="availabilityOpen"
      class="availability-modal-backdrop"
      role="presentation"
      @click.self="closeAvailabilitySettings"
      @keydown.esc="closeAvailabilitySettings"
    >
      <section
        ref="availabilityDialog"
        class="availability-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="availability-modal-title"
        tabindex="-1"
      >
        <header class="availability-modal-header">
          <div>
            <p class="page-kicker">Registrar Staff</p>
            <h2 id="availability-modal-title">Appointment Availability Settings</h2>
          </div>
          <button
            type="button"
            class="availability-modal-close"
            aria-label="Close appointment availability settings"
            title="Close"
            @click="closeAvailabilitySettings"
          >
            &times;
          </button>
        </header>

        <div class="availability-modal-body">
          <p v-if="availabilityMessage" class="notice success" role="status">{{ availabilityMessage }}</p>
          <p v-if="availabilityError" class="notice error" role="alert">{{ availabilityError }}</p>
          <p v-if="availabilityLoading" class="empty">Loading appointment availability settings…</p>

          <template v-else>
            <section class="availability-modal-section availability-weekend-settings">
              <div class="weekend-settings-heading">
                <div>
                  <h3>Weekend Availability</h3>
                  <p>Choose which weekend days students may use for new appointments.</p>
                </div>
                <button type="button" :disabled="savingWeekendSettings" @click="saveWeekendSettings">
                  {{ savingWeekendSettings ? 'Saving…' : 'Save weekend settings' }}
                </button>
              </div>
              <div class="weekend-toggles">
                <label class="availability-toggle">
                  <input
                    v-model="weekendSettings.block_saturday"
                    type="checkbox"
                    :disabled="savingWeekendSettings"
                  />
                  <span>
                    <strong>Block Saturday</strong>
                    <small>
                      {{
                        weekendSettings.block_saturday
                          ? 'Students cannot book Saturdays.'
                          : 'Students may book Saturdays unless manually blocked.'
                      }}
                    </small>
                  </span>
                </label>
                <label class="availability-toggle">
                  <input
                    v-model="weekendSettings.block_sunday"
                    type="checkbox"
                    :disabled="savingWeekendSettings"
                  />
                  <span>
                    <strong>Block Sunday</strong>
                    <small>
                      {{
                        weekendSettings.block_sunday
                          ? 'Students cannot book Sundays.'
                          : 'Students may book Sundays unless manually blocked.'
                      }}
                    </small>
                  </span>
                </label>
              </div>
            </section>

            <section ref="blockedDateForm" class="availability-modal-section">
              <h3>{{ editingBlockedDateId ? 'Edit blocked date' : 'Add blocked date' }}</h3>
              <form class="form-grid availability-form" @submit.prevent="saveBlockedDate">
                <label>
                  Date
                  <input v-model="blockedDate.blocked_date" type="date" required />
                </label>
                <label>
                  Type
                  <select v-model="blockedDate.type" required>
                    <option v-for="option in blockedDateTypes" :key="option.value" :value="option.value">
                      {{ option.label }}
                    </option>
                  </select>
                </label>
                <label class="wide">
                  Reason
                  <input
                    v-model.trim="blockedDate.reason"
                    maxlength="255"
                    placeholder="Why is this date unavailable?"
                    required
                  />
                </label>
                <label class="checkbox wide">
                  <input v-model="blockedDate.is_active" type="checkbox" />
                  Active block
                </label>
                <span class="actions wide">
                  <button :disabled="blockedDateSubmitting">
                    {{ blockedDateSubmitting ? 'Saving…' : editingBlockedDateId ? 'Save changes' : 'Add blocked date' }}
                  </button>
                  <button
                    v-if="editingBlockedDateId"
                    type="button"
                    class="secondary"
                    @click="resetBlockedDateForm"
                  >
                    Cancel edit
                  </button>
                </span>
              </form>
            </section>

            <section class="availability-modal-section">
              <div class="blocked-dates-heading">
                <h3>Blocked Dates</h3>
              </div>
              <p v-if="!blockedDates.length" class="empty">No dates have been blocked.</p>
              <div v-else class="modal-availability-list">
                <article v-for="item in blockedDates" :key="item.id" class="modal-availability-item">
                  <div class="blocked-date-summary">
                    <strong>{{ blockedDateLabel(item.blocked_date) }}</strong>
                    <span>{{ blockedDateTypeLabel(item.type) }}</span>
                  </div>
                  <p>{{ item.reason }}</p>
                  <span class="badge" :class="item.is_active ? 'active' : 'inactive'">
                    {{ item.is_active ? 'Active' : 'Inactive' }}
                  </span>
                  <span class="actions blocked-date-actions">
                    <button type="button" class="compact-button" @click="editBlockedDate(item)">Edit</button>
                    <button type="button" class="compact-button secondary" @click="toggleBlockedDate(item)">
                      {{ item.is_active ? 'Deactivate' : 'Activate' }}
                    </button>
                    <button type="button" class="compact-button danger" @click="deleteBlockedDate(item)">
                      Delete
                    </button>
                  </span>
                </article>
              </div>
            </section>
          </template>
        </div>
      </section>
    </div>
  </Teleport>
</template>

<style scoped src="./documentRequest.css"></style>
