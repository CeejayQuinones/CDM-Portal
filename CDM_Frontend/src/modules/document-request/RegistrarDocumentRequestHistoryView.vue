<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import PaginationControls from '../../components/PaginationControls.vue'
import DocumentRequestDialog from './DocumentRequestDialog.vue'
import DocumentRequestPageHeader from './DocumentRequestPageHeader.vue'
import DocumentRequestStatusBadge from './DocumentRequestStatusBadge.vue'
import DocumentRequestTableSkeleton from './DocumentRequestTableSkeleton.vue'
import {
  appointmentDateTime,
  documentHistoryStatus,
  formatExactDate,
  formatExactDateTime,
  requestReference,
  studentName,
  TIME_FILTERS,
} from './documentRequestPresentation'
import { documentRequestService as api } from './documentRequestService'

const route = useRoute()
const requests = ref([])
const documentTypes = ref([])
const search = ref('')
const requestStatus = ref('')
const documentTypeId = ref('')
const timeFilter = ref('all')
const loading = ref(false)
const error = ref('')
const requestPage = ref(1)
const lastRequestPage = ref(1)
const requestIdFilter = ref(null)
const appointmentIdFilter = ref(null)
const focusedRequestId = ref(null)
const focusedAppointmentRequestId = ref(null)
const selectedRequest = ref(null)
const detailLoading = ref(false)
const detailError = ref('')
const finalRequestStatuses = [
  { value: 'completed', label: 'Released' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'cancelled', label: 'Cancelled' },
]
const requestError = (err) => err.response?.data?.message || 'Document request history could not be loaded.'
const queryValue = (value) => (Array.isArray(value) ? value[0] : value)
const positiveId = (value) => {
  const id = Number(queryValue(value))

  return Number.isInteger(id) && id > 0 ? id : null
}
const focusId = (value, type) => {
  const match = String(queryValue(value) || '').match(new RegExp(`^${type}-(\\d+)$`))

  return match ? positiveId(match[1]) : null
}
const referenceFor = (item) => item?.request_reference || requestReference(item?.id)
const requestTimestamp = (item) => item?.request_date || item?.created_at || null
const lastUpdatedTimestamp = (item) =>
  item?.completed_at || item?.rejected_at || item?.cancelled_at || item?.updated_at || item?.release_date || null
const releaseDateLabel = (item) =>
  item?.completed_at ? formatExactDateTime(item.completed_at) : formatExactDate(item?.release_date)
const statusFor = (status) => documentHistoryStatus(status)
const hasActiveFilters = computed(
  () => Boolean(search.value.trim() || requestStatus.value || documentTypeId.value || timeFilter.value !== 'all'),
)
const selectedProfile = computed(
  () => selectedRequest.value?.student?.user_profile || selectedRequest.value?.student?.user?.profile || null,
)
const selectedStudentName = computed(() => studentName(selectedRequest.value?.student) || 'Student name unavailable')
const selectedStudentNumber = computed(() => selectedRequest.value?.student?.student_number || 'Not available')
const processingHistory = computed(() =>
  [...(selectedRequest.value?.status_changes || [])].sort((first, second) => {
    const timeDifference = new Date(first.created_at).getTime() - new Date(second.created_at).getTime()

    return timeDifference || Number(first.id || 0) - Number(second.id || 0)
  }),
)
const appointmentHistory = computed(() =>
  [...(selectedRequest.value?.appointments || [])].sort((first, second) => {
    const firstSchedule = `${first.appointment_date || ''}T${first.appointment_time || '00:00:00'}`
    const secondSchedule = `${second.appointment_date || ''}T${second.appointment_time || '00:00:00'}`

    return firstSchedule.localeCompare(secondSchedule) || Number(first.id || 0) - Number(second.id || 0)
  }),
)
const releaseEvent = computed(() =>
  [...processingHistory.value]
    .reverse()
    .find((change) => ['complete', 'completed', 'released'].includes(change.action) || change.to_status === 'completed'),
)
const releaseTimestamp = computed(
  () => selectedRequest.value?.completed_at || selectedRequest.value?.release_date || null,
)
const hasReleaseInformation = computed(
  () => Boolean(releaseTimestamp.value || releaseEvent.value || selectedRequest.value?.code_verified_at),
)

let detailRequestSequence = 0

function applyRouteQuery(query) {
  const nextRequestStatus = String(queryValue(query.request_status) || '')
  const nextTimeFilter = String(queryValue(query.time_filter) || 'all')

  search.value = String(queryValue(query.search) || '')
  requestStatus.value = finalRequestStatuses.some(({ value }) => value === nextRequestStatus) ? nextRequestStatus : ''
  documentTypeId.value = positiveId(query.document_type_id) || ''
  timeFilter.value = TIME_FILTERS.some((option) => option.value === nextTimeFilter) ? nextTimeFilter : 'all'
  requestIdFilter.value = positiveId(query.request_id)
  appointmentIdFilter.value = positiveId(query.appointment_id)
  focusedRequestId.value = focusId(query.focus, 'request') || (appointmentIdFilter.value ? null : requestIdFilter.value)
  requestPage.value = 1
}

async function loadDocumentTypes() {
  try {
    documentTypes.value = await api.registrarDocumentTypes()
  } catch (err) {
    error.value = requestError(err)
  }
}

async function refresh(resetPage = false) {
  if (resetPage) requestPage.value = 1
  loading.value = true
  error.value = ''
  focusedAppointmentRequestId.value = null
  try {
    const history = await api.registrarHistory({
      request_status: requestStatus.value || undefined,
      document_type_id: documentTypeId.value || undefined,
      search: search.value.trim() || undefined,
      time_filter: timeFilter.value,
      request_id: requestIdFilter.value || undefined,
      appointment_id: appointmentIdFilter.value || undefined,
      section: appointmentIdFilter.value ? undefined : 'requests',
      request_page: requestPage.value,
      appointment_page: 1,
    })
    requests.value = history.requests?.data || []
    requestPage.value = history.requests?.current_page || requestPage.value
    lastRequestPage.value = history.requests?.last_page || 1
    const focusedAppointment = history.appointments?.data?.find(
      (appointment) => appointment.id === appointmentIdFilter.value,
    )
    focusedAppointmentRequestId.value =
      focusedAppointment?.document_request_id || focusedAppointment?.document_request?.id || null
  } catch (err) {
    error.value = requestError(err)
  } finally {
    loading.value = false
  }
}

async function applyFilters() {
  requestIdFilter.value = null
  appointmentIdFilter.value = null
  focusedRequestId.value = null
  focusedAppointmentRequestId.value = null
  closeDetails()
  await refresh(true)
}

async function resetFilters() {
  search.value = ''
  requestStatus.value = ''
  documentTypeId.value = ''
  timeFilter.value = 'all'
  await applyFilters()
}

async function changeRequestPage(nextPage) {
  requestPage.value = nextPage
  focusedRequestId.value = null
  closeDetails()
  await refresh()
}

function auditActor(change) {
  if (change?.actor_type === 'system') return 'System'
  const profile = change?.registrar_staff?.user?.profile

  return [profile?.first_name, profile?.last_name].filter(Boolean).join(' ') || 'Registrar Staff'
}

function workflowLabel(change) {
  const action = String(change?.action || change?.to_status || '').toLowerCase()
  const labels = {
    submitted: 'Request submitted',
    appointment_assigned: 'Appointment assigned',
    approved: 'Request approved',
    code_verified: 'Claim code verified',
    claim_code_resent: 'Claim code resent',
    complete: 'Released',
    completed: 'Released',
    released: 'Released',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
  }

  return labels[action] || statusFor(action).label
}

function appointmentStatus(status) {
  const normalized = String(status || '').toLowerCase()
  const labels = {
    pending: 'Pending',
    confirmed: 'Confirmed',
    completed: 'Completed',
    cancelled: 'Cancelled',
    no_show: 'No-show',
  }
  const classes = {
    pending: 'status-pending',
    confirmed: 'status-processing',
    completed: 'status-released',
    cancelled: 'status-cancelled',
    no_show: 'status-rejected',
  }

  return {
    label: labels[normalized] || statusFor(normalized).label,
    className: classes[normalized] || 'status-neutral',
  }
}

async function openDetails(item) {
  selectedRequest.value = item
  detailLoading.value = true
  detailError.value = ''
  const sequence = ++detailRequestSequence

  try {
    const detail = await api.registrarRequest(item.id)
    if (sequence === detailRequestSequence && selectedRequest.value?.id === item.id) selectedRequest.value = detail
  } catch (err) {
    if (sequence === detailRequestSequence) detailError.value = requestError(err)
  } finally {
    if (sequence === detailRequestSequence) detailLoading.value = false
  }
}

function closeDetails() {
  detailRequestSequence += 1
  selectedRequest.value = null
  detailLoading.value = false
  detailError.value = ''
}

async function openFocusedRecord() {
  const requestId = focusedRequestId.value || focusedAppointmentRequestId.value
  if (!requestId) return

  const row = requests.value.find((item) => item.id === requestId)
  if (row) await openDetails(row)
  else error.value = 'The requested document request could not be opened from history.'
}

onMounted(async () => {
  applyRouteQuery(route.query)
  await Promise.allSettled([loadDocumentTypes(), refresh()])
  await openFocusedRecord()
})

watch(
  [
    () => queryValue(route.query.search),
    () => queryValue(route.query.request_status),
    () => queryValue(route.query.document_type_id),
    () => queryValue(route.query.time_filter),
    () => queryValue(route.query.request_id),
    () => queryValue(route.query.appointment_id),
    () => queryValue(route.query.focus),
  ],
  async () => {
    closeDetails()
    applyRouteQuery(route.query)
    await refresh()
    await openFocusedRecord()
  },
  { flush: 'post' },
)

</script>

<template>
  <DocumentRequestPageHeader
    eyebrow="Registrar Staff"
    title="Document Request History"
    description="Review completed, rejected, cancelled, and released document requests."
  />

  <p v-if="error" class="notice error">{{ error }}</p>

  <section class="dr-panel history-filter-panel" aria-label="Document request history filters">
    <form class="history-filter-row" @submit.prevent="applyFilters">
      <label class="history-search-field">
        <span>Search</span>
        <input v-model="search" type="search" placeholder="Student name, number, or request ID" />
      </label>
      <label>
        <span>Status</span>
        <select v-model="requestStatus">
          <option value="">All statuses</option>
          <option v-for="option in finalRequestStatuses" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </label>
      <label>
        <span>Document Type</span>
        <select v-model="documentTypeId">
          <option value="">All document types</option>
          <option v-for="type in documentTypes" :key="type.id" :value="type.id">
            {{ type.document_name }}
          </option>
        </select>
      </label>
      <label>
        <span>Date Range</span>
        <select v-model="timeFilter">
          <option v-for="option in TIME_FILTERS" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </label>
      <button type="submit" class="history-apply-button" :disabled="loading">
        {{ loading ? 'Loading…' : 'Apply' }}
      </button>
      <button type="button" class="history-reset-button" :disabled="loading || !hasActiveFilters" @click="resetFilters">
        Reset filters
      </button>
    </form>
  </section>

  <section class="dr-panel history-records-panel" aria-labelledby="history-records-title">
    <div class="history-records-heading">
      <div>
        <h2 id="history-records-title">Request records</h2>
        <p>Final document request outcomes, ordered by the latest update.</p>
      </div>
    </div>

    <DocumentRequestTableSkeleton v-if="loading && !requests.length" :columns="7" :rows="6" label="Loading document request history" />
    <p v-else-if="!requests.length" class="history-empty-state">
      {{ hasActiveFilters ? 'No requests match the selected filters.' : 'No document requests found.' }}
    </p>

    <div v-else class="history-table-shell">
      <table class="history-records-table">
        <thead>
          <tr>
            <th scope="col">Request ID</th>
            <th scope="col">Student</th>
            <th scope="col">Document</th>
            <th scope="col">Requested</th>
            <th scope="col">Status</th>
            <th scope="col">Last Updated</th>
            <th scope="col"><span class="visually-hidden">Action</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in requests" :key="item.id" :id="`request-${item.id}`">
            <td><strong class="history-request-reference">{{ referenceFor(item) }}</strong></td>
            <td>
              <strong>{{ studentName(item.student) || 'Name unavailable' }}</strong>
              <small>{{ item.student?.student_number || 'Student number unavailable' }}</small>
            </td>
            <td>
              <strong>{{ item.document_type?.document_name || 'Document type unavailable' }}</strong>
              <small v-if="item.purpose">{{ item.purpose }}</small>
            </td>
            <td>
              <time v-if="requestTimestamp(item)" :datetime="requestTimestamp(item)">
                {{ formatExactDate(requestTimestamp(item)) }}
              </time>
              <span v-else>—</span>
            </td>
            <td>
              <DocumentRequestStatusBadge :status="item.status" />
            </td>
            <td>
              <time v-if="lastUpdatedTimestamp(item)" :datetime="lastUpdatedTimestamp(item)">
                {{ formatExactDateTime(lastUpdatedTimestamp(item)) }}
              </time>
              <span v-else>—</span>
            </td>
            <td class="history-row-action">
              <button type="button" class="history-view-button" @click="openDetails(item)">View</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <PaginationControls
      v-if="requests.length || lastRequestPage > 1"
      :current-page="requestPage"
      :last-page="lastRequestPage"
      :busy="loading"
      aria-label="Document request history pages"
      @page-change="changeRequestPage"
    />
  </section>

  <DocumentRequestDialog
    v-if="selectedRequest"
    labelledby="history-modal-title"
    panel-class="history-modal-dialog"
    wide
    :busy="detailLoading"
    @cancel="closeDetails"
  >
        <header class="history-modal-header">
          <div>
            <p>Document request record</p>
            <h2 id="history-modal-title">{{ referenceFor(selectedRequest) }}</h2>
          </div>
          <button type="button" aria-label="Close request details" @click="closeDetails">×</button>
        </header>

        <div class="history-modal-body">
          <p v-if="detailError" class="notice error">{{ detailError }}</p>
          <p v-if="detailLoading" class="history-detail-loading" aria-live="polite">Loading full request record…</p>

          <section class="history-detail-section" aria-labelledby="history-student-heading">
            <h3 id="history-student-heading">Student</h3>
            <dl class="history-detail-grid">
              <div><dt>Name</dt><dd>{{ selectedStudentName }}</dd></div>
              <div><dt>Student number</dt><dd>{{ selectedStudentNumber }}</dd></div>
              <div v-if="selectedProfile?.email"><dt>Email</dt><dd>{{ selectedProfile.email }}</dd></div>
              <div v-if="selectedProfile?.contact_number"><dt>Contact number</dt><dd>{{ selectedProfile.contact_number }}</dd></div>
            </dl>
          </section>

          <section class="history-detail-section" aria-labelledby="history-request-heading">
            <h3 id="history-request-heading">Document Request</h3>
            <dl class="history-detail-grid">
              <div><dt>Request ID</dt><dd>{{ referenceFor(selectedRequest) }}</dd></div>
              <div><dt>Document type</dt><dd>{{ selectedRequest.document_type?.document_name || 'Not available' }}</dd></div>
              <div><dt>Requested copies</dt><dd>{{ selectedRequest.quantity || 1 }}</dd></div>
              <div><dt>Request date</dt><dd>{{ formatExactDate(requestTimestamp(selectedRequest)) }}</dd></div>
              <div class="history-detail-wide"><dt>Purpose</dt><dd>{{ selectedRequest.purpose || 'No purpose provided.' }}</dd></div>
              <div v-if="selectedRequest.remarks" class="history-detail-wide"><dt>Registrar remarks</dt><dd>{{ selectedRequest.remarks }}</dd></div>
              <div v-if="selectedRequest.cancellation_reason" class="history-detail-wide">
                <dt>Cancellation reason</dt><dd>{{ selectedRequest.cancellation_reason }}</dd>
              </div>
            </dl>
            <div v-if="appointmentHistory.length" class="history-appointment-records">
              <h4>Appointment records</h4>
              <ul>
                <li v-for="appointment in appointmentHistory" :key="appointment.id">
                  <span>
                    <strong>{{ appointmentDateTime(appointment.appointment_date, appointment.appointment_time) }}</strong>
                    <small v-if="appointment.remarks">{{ appointment.remarks }}</small>
                  </span>
                  <span class="history-status-badge" :class="appointmentStatus(appointment.status).className">
                    {{ appointmentStatus(appointment.status).label }}
                  </span>
                </li>
              </ul>
            </div>
          </section>

          <section class="history-detail-section" aria-labelledby="history-status-heading">
            <h3 id="history-status-heading">Status</h3>
            <dl class="history-detail-grid">
              <div>
                <dt>Current status</dt>
                <dd>
                  <DocumentRequestStatusBadge :status="selectedRequest.status" />
                </dd>
              </div>
              <div><dt>Last updated</dt><dd>{{ formatExactDateTime(lastUpdatedTimestamp(selectedRequest)) }}</dd></div>
            </dl>
          </section>

          <section class="history-detail-section" aria-labelledby="history-processing-heading">
            <h3 id="history-processing-heading">Processing History</h3>
            <p v-if="!detailLoading && !processingHistory.length" class="history-detail-empty">
              No recorded workflow events.
            </p>
            <ol v-else-if="processingHistory.length" class="history-processing-list">
              <li v-for="change in processingHistory" :key="change.id">
                <time :datetime="change.created_at">{{ formatExactDateTime(change.created_at) }}</time>
                <div>
                  <strong>{{ workflowLabel(change) }}</strong>
                  <small>Handled by {{ auditActor(change) }}</small>
                  <p v-if="change.reason">{{ change.reason }}</p>
                </div>
              </li>
            </ol>
          </section>

          <section v-if="hasReleaseInformation" class="history-detail-section" aria-labelledby="history-release-heading">
            <h3 id="history-release-heading">Release Information</h3>
            <dl class="history-detail-grid">
              <div v-if="releaseTimestamp"><dt>Released date</dt><dd>{{ releaseDateLabel(selectedRequest) }}</dd></div>
              <div v-if="releaseEvent"><dt>Released by</dt><dd>{{ auditActor(releaseEvent) }}</dd></div>
              <div v-if="selectedRequest.code_verified_at">
                <dt>Claim verified</dt><dd>{{ formatExactDateTime(selectedRequest.code_verified_at) }}</dd>
              </div>
            </dl>
          </section>
        </div>

        <footer class="history-modal-footer">
          <button type="button" @click="closeDetails">Close</button>
        </footer>
  </DocumentRequestDialog>
</template>

<style scoped src="./documentRequest.css"></style>
