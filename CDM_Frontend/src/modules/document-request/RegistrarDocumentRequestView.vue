<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PaginationControls from '../../components/PaginationControls.vue'
import RegistrarRecentActivity from './RegistrarRecentActivity.vue'
import {
  formatExactDateTime,
  formatRelativeTime,
  requestReference,
  TIME_FILTERS,
} from './documentRequestPresentation'
import { documentRequestService as api } from './documentRequestService'

const route = useRoute()
const router = useRouter()
const requests = ref([])
const selected = ref(null)
const status = ref('')
const search = ref('')
const timeFilter = ref('all')
const loading = ref(false)
const message = ref('')
const error = ref('')
const statusEditorOpen = ref(false)
const page = ref(1)
const lastPage = ref(1)
const requestIdFilter = ref(null)
const focusedRequestId = ref(null)
const activeStatuses = ['pending', 'processing', 'ready_for_release']
const requestError = (err) => err.response?.data?.message || 'The request could not be completed.'
const formatMoney = (value) => Number(value).toFixed(2)
const studentProfile = computed(() => selected.value?.student?.user_profile || null)
const physicalLocation = computed(() => selected.value?.student?.physical_record_location || null)
const currentAppointment = computed(() => {
  const appointments = selected.value?.appointments || []

  return appointments.find((appointment) => ['pending', 'confirmed'].includes(appointment.status)) || appointments[0] || null
})
const studentFullName = computed(() =>
  [
    studentProfile.value?.first_name,
    studentProfile.value?.middle_name,
    studentProfile.value?.last_name,
    studentProfile.value?.suffix,
  ]
    .filter(Boolean)
    .join(' '),
)
const studentInitials = computed(
  () =>
    studentFullName.value
      .split(' ')
      .map((part) => part[0])
      .slice(0, 2)
      .join('')
      .toUpperCase() || 'ST',
)
const requestedDocumentAvailable = computed(() =>
  (selected.value?.student?.documents || []).some(
    (document) =>
      document.document_type_id === selected.value?.document_type?.id && document.availability_status === 'available',
  ),
)
const formatStatus = (value) =>
  value ? value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Missing'
const formatDate = (value) => {
  if (!value) return 'Not available'

  return new Intl.DateTimeFormat('en-PH', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    timeZone: 'Asia/Manila',
  }).format(new Date(`${String(value).slice(0, 10)}T00:00:00+08:00`))
}
const formatAppointment = (appointment) => {
  if (!appointment) return 'No appointment booked'

  const date = formatDate(appointment.appointment_date)
  const time = new Intl.DateTimeFormat('en-PH', {
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
  }).format(new Date(`2000-01-01T${String(appointment.appointment_time).slice(0, 8)}`))

  return `${date} · ${time}`
}
const referenceFor = (item) => item?.request_reference || requestReference(item?.id)
const requestTimestamp = (item) => item?.updated_at || item?.created_at || item?.request_date || null
const queryValue = (value) => (Array.isArray(value) ? value[0] : value)
const positiveId = (value) => {
  const id = Number(queryValue(value))

  return Number.isInteger(id) && id > 0 ? id : null
}
const focusedId = (value) => {
  const match = String(queryValue(value) || '').match(/^request-(\d+)$/)

  return match ? positiveId(match[1]) : null
}

function applyRouteQuery(query) {
  const nextStatus = String(queryValue(query.status) || '')
  const nextTimeFilter = String(queryValue(query.time_filter) || 'all')

  search.value = String(queryValue(query.search) || '')
  status.value = activeStatuses.includes(nextStatus) ? nextStatus : ''
  timeFilter.value = TIME_FILTERS.some((option) => option.value === nextTimeFilter) ? nextTimeFilter : 'all'
  requestIdFilter.value = positiveId(query.request_id)
  focusedRequestId.value = focusedId(query.focus) || requestIdFilter.value
  page.value = 1
  selected.value = null
}

function viewCabinet() {
  if (!physicalLocation.value) return
  router.push({
    name: 'physical-records',
    query: {
      cabinet: physicalLocation.value.cabinet_slot.cabinet.id,
      slot: physicalLocation.value.cabinet_slot.id,
    },
  })
}

function viewStudentProfile() {
  if (!selected.value) return

  router.push({
    name: 'student-details',
    params: { id: selected.value.student.id },
  })
}

function closeDetails() {
  selected.value = null
  statusEditorOpen.value = false
}

function handleEscape(event) {
  if (event.key === 'Escape' && selected.value) closeDetails()
}

async function refresh(resetPage = false) {
  if (resetPage) page.value = 1
  loading.value = true
  error.value = ''
  try {
    const queue = await api.registrarRequests({
      status: status.value || undefined,
      search: search.value || undefined,
      time_filter: timeFilter.value,
      request_id: requestIdFilter.value || undefined,
      page: page.value,
    })
    requests.value = queue.data
    page.value = queue.current_page || page.value
    lastPage.value = queue.last_page
  } catch (err) {
    error.value = requestError(err)
  } finally {
    loading.value = false
  }
}

async function revealFocusedRequest() {
  if (!focusedRequestId.value) return

  await selectRequest({ id: focusedRequestId.value })
  await nextTick()
  document.getElementById(`request-${focusedRequestId.value}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

async function applyFilters() {
  requestIdFilter.value = null
  focusedRequestId.value = null
  selected.value = null
  await refresh(true)
}

async function selectTimeFilter(value) {
  timeFilter.value = value
  await applyFilters()
}

async function changePage(nextPage) {
  page.value = nextPage
  selected.value = null
  focusedRequestId.value = null
  await refresh()
}

async function selectRequest(item) {
  error.value = ''
  statusEditorOpen.value = false
  try {
    selected.value = await api.registrarRequest(item.id)
  } catch (err) {
    error.value = requestError(err)
  }
}

function updateQueueItem(updated) {
  const remainsInQueue = activeStatuses.includes(updated.status) && (!status.value || updated.status === status.value)

  requests.value = remainsInQueue
    ? requests.value.map((item) =>
        item.id === updated.id
          ? {
              ...item,
              status: updated.status,
              updated_at: updated.updated_at,
              request_reference: updated.request_reference || item.request_reference,
            }
          : item,
      )
    : requests.value.filter((item) => item.id !== updated.id)
}

async function action(nextAction) {
  if (!selected.value) return
  error.value = ''
  message.value = ''
  try {
    const updated = await api.updateRequest(selected.value.id, {
      action: nextAction,
      remarks: selected.value.remarks || null,
    })
    selected.value = updated
    statusEditorOpen.value = false
    updateQueueItem(updated)
    message.value = `Request ${nextAction.replaceAll('_', ' ')}.`
    if (!requests.value.length && page.value > 1) page.value -= 1
    await refresh()
  } catch (err) {
    error.value = requestError(err)
  }
}

onMounted(async () => {
  window.addEventListener('keydown', handleEscape)
  applyRouteQuery(route.query)
  await refresh()
  await revealFocusedRequest()
})

onBeforeUnmount(() => window.removeEventListener('keydown', handleEscape))

watch(
  () => route.fullPath,
  async () => {
    applyRouteQuery(route.query)
    await refresh()
    await revealFocusedRequest()
  },
  { flush: 'post' },
)
</script>

<template>
  <section class="page-header">
    <p class="page-kicker">Registrar Staff</p>
    <h1 class="page-title">Document Request Queue</h1>
    <p class="page-description">Review requests and move documents through processing to direct release.</p>
  </section>

  <p v-if="message" class="notice success">{{ message }}</p>
  <p v-if="error" class="notice error">{{ error }}</p>

  <RegistrarRecentActivity />

  <section class="dr-panel">
    <nav class="group-tabs time-filter-tabs" aria-label="Request activity period">
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
      <select v-model="status">
        <option value="">All active statuses</option>
        <option v-for="value in activeStatuses" :key="value" :value="value">
          {{ value.replaceAll('_', ' ') }}
        </option>
      </select>
      <button :disabled="loading">Search</button>
    </form>
    <p v-if="loading && !requests.length" class="empty">Loading requests…</p>
    <p v-else-if="!requests.length" class="empty">No matching requests.</p>
    <button
      v-for="item in requests"
      :id="`request-${item.id}`"
      :key="item.id"
      class="queue-item"
      :class="{ 'focused-record': focusedRequestId === item.id || selected?.id === item.id }"
      type="button"
      @click="selectRequest(item)"
    >
      <span>
        <strong>{{ referenceFor(item) }} · {{ item.document_type.document_name }}</strong>
        <small>
          {{ item.student.student_number }} ·
          {{ item.student.user.profile.first_name }}
          {{ item.student.user.profile.last_name }}
        </small>
        <time
          v-if="requestTimestamp(item)"
          class="time-display"
          :datetime="requestTimestamp(item)"
          :title="formatExactDateTime(requestTimestamp(item))"
        >
          {{ formatRelativeTime(requestTimestamp(item)) }}
          <small>{{ formatExactDateTime(requestTimestamp(item)) }}</small>
        </time>
      </span>
      <span class="badge" :class="item.status">{{ item.status.replaceAll('_', ' ') }}</span>
    </button>
    <PaginationControls
      :current-page="page"
      :last-page="lastPage"
      :busy="loading"
      aria-label="Document request queue pages"
      @page-change="changePage"
    />
  </section>

  <Teleport to="body">
    <div v-if="selected" class="request-detail-backdrop" @click.self="closeDetails">
      <section class="request-detail-dialog" role="dialog" aria-modal="true" aria-labelledby="request-detail-title">
        <header class="request-detail-header">
          <div>
            <p>Registrar document queue</p>
            <h2 id="request-detail-title">Request Details</h2>
          </div>
          <button type="button" class="request-detail-close" aria-label="Close request details" @click="closeDetails">
            ×
          </button>
        </header>

        <div class="request-detail-scroll">
          <section class="request-identity">
            <div class="request-student-avatar">
              <img
                v-if="studentProfile?.profile_photo"
                :src="studentProfile.profile_photo"
                :alt="`${studentFullName} photo`"
              />
              <span v-else>{{ studentInitials }}</span>
            </div>
            <div>
              <strong class="request-reference">{{ referenceFor(selected) }}</strong>
              <h3>{{ studentFullName }}</h3>
              <p>{{ selected.document_type.document_name }}</p>
            </div>
            <span class="badge request-detail-status" :class="selected.status">{{ formatStatus(selected.status) }}</span>
          </section>

          <dl class="request-facts">
            <div><dt>Requested</dt><dd>{{ formatDate(selected.request_date) }}</dd></div>
            <div><dt>Appointment</dt><dd>{{ formatAppointment(currentAppointment) }}</dd></div>
            <div><dt>Fee</dt><dd>₱{{ formatMoney(selected.total_fee || 0) }}</dd></div>
            <div class="physical-record-fact">
              <dt>Physical Record Location</dt>
              <dd v-if="physicalLocation">
                Cabinet {{ physicalLocation.cabinet_slot.cabinet.cabinet_code }} · Slot
                {{ physicalLocation.cabinet_slot.slot_code }}
              </dd>
              <dd v-else>Not assigned</dd>
            </div>
          </dl>

          <section class="request-detail-section" aria-labelledby="request-student-heading">
            <div class="request-section-heading"><span>01</span><h3 id="request-student-heading">Student Details</h3></div>
            <dl class="student-facts">
              <div><dt>Student No.</dt><dd>{{ selected.student.student_number }}</dd></div>
              <div>
                <dt>Course</dt>
                <dd>{{ selected.student.course?.course_code || selected.student.course?.course_name || 'Not available' }}</dd>
              </div>
              <div><dt>Year Level</dt><dd>Year {{ selected.student.year_level || 'Not available' }}</dd></div>
            </dl>
          </section>

          <section class="request-detail-section" aria-labelledby="request-notes-heading">
            <div class="request-section-heading"><span>02</span><h3 id="request-notes-heading">Request Notes / Purpose</h3></div>
            <p class="request-purpose">{{ selected.purpose || selected.remarks || 'No notes or purpose provided.' }}</p>
          </section>

          <p v-if="!requestedDocumentAvailable" class="record-warning request-detail-warning">
            The requested document is not marked available in this student's document record. Registrar Staff may still
            continue processing the request.
          </p>

          <section v-if="statusEditorOpen" class="request-status-editor" aria-labelledby="request-status-heading">
            <div class="request-section-heading"><span>03</span><h3 id="request-status-heading">Update Status</h3></div>
            <label>
              Registrar remarks
              <textarea v-model="selected.remarks" rows="3" placeholder="Optional internal remarks"></textarea>
            </label>
            <div class="request-status-actions">
              <button v-if="selected.status === 'pending'" type="button" @click="action('approve')">Approve & process</button>
              <button v-if="['pending', 'processing'].includes(selected.status)" type="button" class="danger" @click="action('reject')">Reject</button>
              <button v-if="selected.status === 'processing'" type="button" @click="action('process')">Mark processed</button>
              <button v-if="selected.status === 'processing'" type="button" @click="action('ready_for_release')">Ready for release</button>
              <button v-if="selected.status === 'ready_for_release'" type="button" @click="action('release')">Release document</button>
              <button v-if="activeStatuses.includes(selected.status)" type="button" class="secondary" @click="action('cancel')">Cancel request</button>
            </div>
          </section>
        </div>

        <footer class="request-detail-footer">
          <button type="button" class="secondary-action" @click="viewStudentProfile">View Student Profile</button>
          <button type="button" class="secondary-action" :disabled="!physicalLocation" @click="viewCabinet">View Physical Record</button>
          <button type="button" class="primary-action" @click="statusEditorOpen = !statusEditorOpen">
            {{ statusEditorOpen ? 'Hide Status Update' : 'Update Status' }}
          </button>
          <button type="button" class="close-action" @click="closeDetails">Close</button>
        </footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped src="./documentRequest.css"></style>
