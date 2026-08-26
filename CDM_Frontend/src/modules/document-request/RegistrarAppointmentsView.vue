<script setup>
import { nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PaginationControls from '../../components/PaginationControls.vue'
import RegistrarRecentActivity from './RegistrarRecentActivity.vue'
import {
  appointmentDateTime,
  formatExactDateTime,
  formatRelativeTime,
  requestReference,
  studentName,
  TIME_FILTERS,
} from './documentRequestPresentation'
import { documentRequestService as api } from './documentRequestService'

const route = useRoute()
const router = useRouter()
const appointments = ref([])
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
const requestIdFilter = ref(null)
const appointmentIdFilter = ref(null)
const focusedAppointmentId = ref(null)
const statuses = ['pending', 'confirmed']
const activeStatuses = ['pending', 'confirmed']
const activeGroups = [
  { value: 'active', label: 'All Active' },
  { value: 'recent', label: 'Recent' },
  { value: 'upcoming', label: 'Upcoming' },
  { value: 'today', label: 'Today' },
]
const requestError = (err) => err.response?.data?.message || 'The appointment could not be completed.'
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
  page.value = 1
}

async function refresh(resetPage = false) {
  if (resetPage) page.value = 1
  loading.value = true
  error.value = ''
  try {
    const result = await api.registrarAppointments({
      search: search.value || undefined,
      date: date.value || undefined,
      status: status.value || undefined,
      group: group.value,
      time_filter: timeFilter.value,
      request_id: requestIdFilter.value || undefined,
      appointment_id: appointmentIdFilter.value || undefined,
      page: page.value,
    })
    appointments.value = result.data
    page.value = result.current_page || page.value
    lastPage.value = result.last_page
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

onMounted(async () => {
  applyRouteQuery(route.query)
  await refresh()
  await revealFocusedAppointment()
})

watch(
  () => route.fullPath,
  async () => {
    applyRouteQuery(route.query)
    await refresh()
    await revealFocusedAppointment()
  },
  { flush: 'post' },
)
</script>

<template>
  <section class="page-header">
    <p class="page-kicker">Registrar Staff</p>
    <h1 class="page-title">Document Request Appointments</h1>
    <p class="page-description">Review and update appointments associated with document requests.</p>
  </section>

  <p v-if="message" class="notice success">{{ message }}</p>
  <p v-if="error" class="notice error">{{ error }}</p>

  <RegistrarRecentActivity />

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
</template>

<style scoped src="./documentRequest.css"></style>
