<script setup>
import { nextTick, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import PaginationControls from '../../components/PaginationControls.vue'
import RegistrarRecentActivity from './RegistrarRecentActivity.vue'
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
import { documentRequestService as api } from './documentRequestService'

const route = useRoute()
const requests = ref([])
const appointments = ref([])
const search = ref('')
const requestStatus = ref('')
const appointmentStatus = ref('')
const timeFilter = ref('all')
const loading = ref(false)
const error = ref('')
const requestPage = ref(1)
const appointmentPage = ref(1)
const lastRequestPage = ref(1)
const lastAppointmentPage = ref(1)
const requestIdFilter = ref(null)
const appointmentIdFilter = ref(null)
const focusedRequestId = ref(null)
const focusedAppointmentId = ref(null)
const focusedSection = ref('')
const finalRequestStatuses = ['released', 'rejected', 'cancelled']
const historicalAppointmentStatuses = ['cancelled', 'completed', 'no_show']
const requestError = (err) => err.response?.data?.message || 'Document request history could not be loaded.'
const queryValue = (value) => (Array.isArray(value) ? value[0] : value)
const positiveId = (value) => {
  const id = Number(queryValue(value))

  return Number.isInteger(id) && id > 0 ? id : null
}
const referenceFor = (item) => item?.request_reference || requestReference(item?.id)
const appointmentReference = (appointment) =>
  appointment?.document_request?.request_reference ||
  requestReference(appointment?.document_request_id || appointment?.document_request?.id)
const requestTimestamp = (item) => item?.created_at || item?.request_date || null
const completionTimestamp = (item) =>
  item?.released_at || item?.rejected_at || item?.cancelled_at || item?.updated_at || item?.release_date || null
const focusId = (value, type) => {
  const match = String(queryValue(value) || '').match(new RegExp(`^${type}-(\\d+)$`))

  return match ? positiveId(match[1]) : null
}

function applyRouteQuery(query) {
  const nextRequestStatus = String(queryValue(query.request_status) || '')
  const nextAppointmentStatus = String(queryValue(query.appointment_status) || '')
  const nextTimeFilter = String(queryValue(query.time_filter) || 'all')

  search.value = String(queryValue(query.search) || '')
  requestStatus.value = finalRequestStatuses.includes(nextRequestStatus) ? nextRequestStatus : ''
  appointmentStatus.value = historicalAppointmentStatuses.includes(nextAppointmentStatus) ? nextAppointmentStatus : ''
  timeFilter.value = TIME_FILTERS.some((option) => option.value === nextTimeFilter) ? nextTimeFilter : 'all'
  requestIdFilter.value = positiveId(query.request_id)
  appointmentIdFilter.value = positiveId(query.appointment_id)
  focusedRequestId.value = focusId(query.focus, 'request') || (appointmentIdFilter.value ? null : requestIdFilter.value)
  focusedAppointmentId.value = focusId(query.focus, 'appointment') || appointmentIdFilter.value
  focusedSection.value = ['requests', 'appointments'].includes(String(queryValue(query.section)))
    ? String(queryValue(query.section))
    : ''
  requestPage.value = 1
  appointmentPage.value = 1
}

async function refresh(resetPages = false, section = null) {
  if (resetPages) {
    requestPage.value = 1
    appointmentPage.value = 1
  }
  loading.value = true
  error.value = ''
  try {
    const history = await api.registrarHistory({
      request_status: requestStatus.value || undefined,
      appointment_status: appointmentStatus.value || undefined,
      search: search.value || undefined,
      time_filter: timeFilter.value,
      request_id: requestIdFilter.value || undefined,
      appointment_id: appointmentIdFilter.value || undefined,
      section: section || undefined,
      request_page: requestPage.value,
      appointment_page: appointmentPage.value,
    })
    if (history.requests) {
      requests.value = history.requests.data
      requestPage.value = history.requests.current_page || requestPage.value
      lastRequestPage.value = history.requests.last_page
    }
    if (history.appointments) {
      appointments.value = history.appointments.data
      appointmentPage.value = history.appointments.current_page || appointmentPage.value
      lastAppointmentPage.value = history.appointments.last_page
    }
  } catch (err) {
    error.value = requestError(err)
  } finally {
    loading.value = false
  }
}

async function revealFocusedRecord() {
  await nextTick()

  const target =
    focusedSection.value === 'appointments'
      ? focusedAppointmentId.value && document.getElementById(`appointment-${focusedAppointmentId.value}`)
      : focusedRequestId.value && document.getElementById(`request-${focusedRequestId.value}`)
  const fallback =
    target ||
    (focusedAppointmentId.value && document.getElementById(`appointment-${focusedAppointmentId.value}`)) ||
    (focusedRequestId.value && document.getElementById(`request-${focusedRequestId.value}`))

  fallback?.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

async function applyFilters() {
  requestIdFilter.value = null
  appointmentIdFilter.value = null
  focusedRequestId.value = null
  focusedAppointmentId.value = null
  focusedSection.value = ''
  await refresh(true)
}

async function selectTimeFilter(value) {
  timeFilter.value = value
  await applyFilters()
}

async function changeRequestPage(nextPage) {
  requestPage.value = nextPage
  focusedRequestId.value = null
  await refresh(false, 'requests')
}

async function changeAppointmentPage(nextPage) {
  appointmentPage.value = nextPage
  focusedAppointmentId.value = null
  await refresh(false, 'appointments')
}

onMounted(async () => {
  applyRouteQuery(route.query)
  await refresh()
  await revealFocusedRecord()
})

watch(
  [
    () => queryValue(route.query.search),
    () => queryValue(route.query.request_status),
    () => queryValue(route.query.appointment_status),
    () => queryValue(route.query.time_filter),
    () => queryValue(route.query.request_id),
    () => queryValue(route.query.appointment_id),
    () => queryValue(route.query.focus),
    () => queryValue(route.query.section),
  ],
  async () => {
    applyRouteQuery(route.query)
    await refresh()
    await revealFocusedRecord()
  },
  { flush: 'post' },
)
</script>

<template>
  <section class="page-header">
    <p class="page-kicker">Registrar Staff</p>
    <h1 class="page-title">Document Request History</h1>
    <p class="page-description">Finalized requests and cancelled, completed, or no-show document appointments.</p>
  </section>

  <p v-if="error" class="notice error">{{ error }}</p>

  <RegistrarRecentActivity />

  <section class="dr-panel">
    <nav class="group-tabs time-filter-tabs" aria-label="History time period">
      <button
        v-for="option in TIME_FILTERS"
        :key="option.value"
        type="button"
        :class="{ active: timeFilter === option.value }"
        :aria-pressed="timeFilter === option.value"
        @click="selectTimeFilter(option.value)"
      >
        {{ option.label }}
      </button>
    </nav>
    <form class="toolbar" @submit.prevent="applyFilters">
      <input v-model="search" placeholder="REQ-000001, student number, name, or document" />
      <select v-model="requestStatus">
        <option value="">All final request statuses</option>
        <option v-for="value in finalRequestStatuses" :key="value" :value="value">
          {{ value }}
        </option>
      </select>
      <select v-model="appointmentStatus">
        <option value="">All historical appointment statuses</option>
        <option v-for="value in historicalAppointmentStatuses" :key="value" :value="value">
          {{ value.replaceAll('_', ' ') }}
        </option>
      </select>
      <button :disabled="loading">Search</button>
    </form>
  </section>

  <section class="dr-panel">
    <h2>Appointment history</h2>
    <p v-if="loading && !appointments.length" class="empty">Loading appointment history…</p>
    <p v-else-if="!appointments.length" class="empty">No matching historical appointments.</p>

    <div v-if="appointments.length" class="history-table" role="table">
      <div class="history-row appointment-history-row history-head" role="row">
        <strong>Student</strong>
        <strong>Document</strong>
        <strong>Appointment</strong>
        <strong>Appointment status</strong>
        <strong>Request status</strong>
        <strong>Registrar remarks</strong>
      </div>
      <div
        v-for="appointment in appointments"
        :id="`appointment-${appointment.id}`"
        :key="appointment.id"
        class="history-row appointment-history-row"
        :class="{ 'focused-record': focusedAppointmentId === appointment.id }"
        role="row"
      >
        <span>
          <strong>{{ studentName(appointment.student) }}</strong>
          <small>{{ appointment.student.student_number }}</small>
        </span>
        <span>
          <strong>{{ appointmentReference(appointment) }}</strong>
          <small>{{ appointment.document_request.document_type.document_name }}</small>
        </span>
        <span>
          <strong>{{ appointmentDateTime(appointment.appointment_date, appointment.appointment_time) }}</strong>
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
        <span>
          <span class="badge" :class="appointment.status">{{ appointment.status.replaceAll('_', ' ') }}</span>
        </span>
        <span>
          <span class="badge" :class="appointment.document_request.status">
            {{ appointment.document_request.status.replaceAll('_', ' ') }}
          </span>
        </span>
        <span>{{ appointment.remarks || appointment.document_request.remarks || '—' }}</span>
      </div>
    </div>
    <PaginationControls
      :current-page="appointmentPage"
      :last-page="lastAppointmentPage"
      :busy="loading"
      aria-label="Appointment history pages"
      @page-change="changeAppointmentPage"
    />
  </section>

  <section class="dr-panel">
    <h2>Request history</h2>
    <p v-if="loading && !requests.length" class="empty">Loading request history…</p>
    <p v-else-if="!requests.length" class="empty">No matching finalized requests.</p>

    <div v-if="requests.length" class="history-table" role="table">
      <div class="history-row history-head" role="row">
        <strong>Student</strong>
        <strong>Document</strong>
        <strong>Request date</strong>
        <strong>Status</strong>
        <strong>Appointment</strong>
        <strong>Completed</strong>
        <strong>Registrar remarks</strong>
      </div>
      <div
        v-for="item in requests"
        :id="`request-${item.id}`"
        :key="item.id"
        class="history-row"
        :class="[
          'request-history-row',
          requestStatusAccentClass(item.status),
          { 'focused-record': focusedRequestId === item.id },
        ]"
        role="row"
      >
        <span>
          <strong>{{ studentName(item.student) }}</strong>
          <small>{{ item.student.student_number }}</small>
        </span>
        <span>
          <strong>{{ referenceFor(item) }}</strong>
          <small>
            <span class="document-type-chip" :class="documentTypeAccentClass(item.document_type.document_name)">
              {{ item.document_type.document_name }}
            </span>
          </small>
        </span>
        <time
          v-if="requestTimestamp(item)"
          class="time-display"
          :datetime="requestTimestamp(item)"
          :title="formatExactDateTime(requestTimestamp(item))"
        >
          {{ formatRelativeTime(requestTimestamp(item)) }}
          <small>{{ formatExactDateTime(requestTimestamp(item)) }}</small>
        </time>
        <span v-else>—</span>
        <span>
          <span class="badge" :class="item.status">{{ item.status }}</span>
        </span>
        <span v-if="item.latest_appointment">
          {{ item.latest_appointment.appointment_date }} at
          {{ String(item.latest_appointment.appointment_time).slice(0, 5) }} ·
          {{ item.latest_appointment.status.replaceAll('_', ' ') }}
        </span>
        <span v-else>—</span>
        <time
          v-if="completionTimestamp(item)"
          class="time-display"
          :datetime="completionTimestamp(item)"
          :title="formatExactDateTime(completionTimestamp(item))"
        >
          {{ formatRelativeTime(completionTimestamp(item)) }}
          <small>{{ formatExactDateTime(completionTimestamp(item)) }}</small>
        </time>
        <span v-else>—</span>
        <span>{{ item.remarks || '—' }}</span>
      </div>
    </div>
    <PaginationControls
      :current-page="requestPage"
      :last-page="lastRequestPage"
      :busy="loading"
      aria-label="Document request history pages"
      @page-change="changeRequestPage"
    />
  </section>
</template>

<style scoped src="./documentRequest.css"></style>
