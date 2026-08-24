<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
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
const page = ref(1)
const lastPage = ref(1)
const requestIdFilter = ref(null)
const focusedRequestId = ref(null)
const activeStatuses = ['pending', 'processing', 'ready_for_release']
const requestError = (err) => err.response?.data?.message || 'The request could not be completed.'
const formatMoney = (value) => Number(value).toFixed(2)
const studentProfile = computed(() => selected.value?.student?.user_profile || null)
const physicalLocation = computed(() => selected.value?.student?.physical_record_location || null)
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
const availableDocuments = computed(() =>
  (selected.value?.student?.documents || []).filter((document) => document.availability_status === 'available'),
)
const missingDocuments = computed(() => {
  if (!selected.value) return []
  const documents = selected.value.student?.documents || []
  const missing = documents.filter((document) => document.availability_status !== 'available')
  const requestedTypeId = selected.value.document_type?.id
  const requestedRecord = documents.find((document) => document.document_type_id === requestedTypeId)

  if (!requestedRecord) {
    missing.push({
      id: `requested-${requestedTypeId}`,
      document_type: {
        document_name: selected.value.document_type.document_name,
      },
      availability_status: 'missing',
      remarks: 'No student document record exists.',
      is_requested_document: true,
    })
  }

  return missing
})
const requestedDocumentAvailable = computed(() =>
  (selected.value?.student?.documents || []).some(
    (document) =>
      document.document_type_id === selected.value?.document_type?.id && document.availability_status === 'available',
  ),
)
const formatStatus = (value) =>
  value ? value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Missing'
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
    updateQueueItem(updated)
    message.value = `Request ${nextAction.replaceAll('_', ' ')}.`
    if (!requests.value.length && page.value > 1) page.value -= 1
    await refresh()
  } catch (err) {
    error.value = requestError(err)
  }
}

onMounted(async () => {
  applyRouteQuery(route.query)
  await refresh()
  await revealFocusedRequest()
})

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

  <section v-if="selected" class="dr-panel">
    <h2>Request {{ referenceFor(selected) }}</h2>
    <time
      v-if="requestTimestamp(selected)"
      class="time-display detail-updated-time"
      :datetime="requestTimestamp(selected)"
      :title="formatExactDateTime(requestTimestamp(selected))"
    >
      Updated {{ formatRelativeTime(requestTimestamp(selected)) }}
      <small>{{ formatExactDateTime(requestTimestamp(selected)) }}</small>
    </time>
    <p>
      <strong>Student:</strong>
      {{ studentFullName }} ({{ selected.student.student_number }})
    </p>
    <p>
      <strong>Document:</strong>
      {{ selected.document_type.document_name }} · {{ selected.quantity }} copy/copies
      <template v-if="Number(selected.total_fee) > 0">· ₱{{ formatMoney(selected.total_fee) }}</template>
    </p>

    <section class="record-check" aria-labelledby="student-record-check-heading">
      <div class="record-check-heading">
        <div class="record-student-photo">
          <img
            v-if="studentProfile?.profile_photo"
            :src="studentProfile.profile_photo"
            :alt="`${studentFullName} photo`"
          />
          <span v-else>{{ studentInitials }}</span>
        </div>
        <div>
          <p class="record-eyebrow">Student Management</p>
          <h3 id="student-record-check-heading">Student Record Check</h3>
          <strong>{{ studentFullName }}</strong>
          <p>
            {{ selected.student.student_number }} · {{ selected.student.course?.course_code }} —
            {{ selected.student.course?.course_name }}
          </p>
          <p>
            Year {{ selected.student.year_level }} ·
            {{ formatStatus(selected.student.student_status) }}
          </p>
        </div>
        <button
          type="button"
          class="record-link"
          @click="
            router.push({
              name: 'student-details',
              params: { id: selected.student.id },
            })
          "
        >
          View Student Record
        </button>
      </div>

      <div class="physical-location-check">
        <div>
          <span>Physical Record Location</span>
          <strong v-if="physicalLocation">
            Cabinet {{ physicalLocation.cabinet_slot.cabinet.cabinet_code }} / Slot
            {{ physicalLocation.cabinet_slot.slot_code }}
          </strong>
          <strong v-else class="not-assigned">Not assigned</strong>
          <small v-if="physicalLocation?.remarks">{{ physicalLocation.remarks }}</small>
        </div>
        <button v-if="physicalLocation" type="button" class="record-link" @click="viewCabinet">View Cabinet</button>
      </div>

      <p v-if="!requestedDocumentAvailable" class="record-warning">
        The requested document is not currently marked available in this student's document record. Registrar Staff may
        still continue processing the request.
      </p>

      <div class="record-document-columns">
        <div>
          <h4>Available Documents</h4>
          <p v-if="!availableDocuments.length" class="empty">No documents are marked available.</p>
          <ul v-else>
            <li v-for="document in availableDocuments" :key="document.id">
              <span>{{ document.document_type.document_name }}</span>
              <strong class="available-label">Available</strong>
            </li>
          </ul>
        </div>
        <div>
          <h4>Missing Documents</h4>
          <p v-if="!missingDocuments.length" class="empty">No documents are marked missing.</p>
          <ul v-else>
            <li v-for="document in missingDocuments" :key="document.id">
              <span>{{ document.document_type.document_name }}</span>
              <strong class="missing-label">Missing</strong>
            </li>
          </ul>
        </div>
      </div>
    </section>

    <label>
      Registrar remarks
      <textarea v-model="selected.remarks" rows="3"></textarea>
    </label>
    <div class="actions">
      <button v-if="selected.status === 'pending'" @click="action('approve')">Approve & process</button>
      <button v-if="['pending', 'processing'].includes(selected.status)" class="danger" @click="action('reject')">
        Reject
      </button>
      <button v-if="selected.status === 'processing'" @click="action('process')">Mark processed</button>
      <button v-if="selected.status === 'processing'" @click="action('ready_for_release')">Ready for release</button>
      <button v-if="selected.status === 'ready_for_release'" @click="action('release')">Release document</button>
      <button v-if="activeStatuses.includes(selected.status)" class="secondary" @click="action('cancel')">
        Cancel
      </button>
    </div>

    <h3>Appointments</h3>
    <p v-if="!selected.appointments.length" class="empty">No appointment booked.</p>
    <div v-for="appointment in selected.appointments" :key="appointment.id" class="appointment">
      <span>
        {{ appointment.appointment_date }} at {{ String(appointment.appointment_time).slice(0, 5) }} —
        {{ appointment.status }}
      </span>
    </div>
  </section>
</template>

<style scoped src="./documentRequest.css"></style>
