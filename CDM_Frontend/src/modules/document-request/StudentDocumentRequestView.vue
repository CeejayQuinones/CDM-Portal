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
import { DOCUMENT_REQUEST_PURPOSES, documentRequestPurpose } from './documentRequestPurpose'
import { addCartDocument, cartIsComplete, cartSubmissionPayload } from './documentRequestCart'
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
const documentTypeSearch = ref('')
const cart = ref([])
const batchKey = ref('')
const purposeCategory = ref('')
const purposeDetails = ref('')

const currentDate = new Date()
const today = dateKey(currentDate.getFullYear(), currentDate.getMonth(), currentDate.getDate())

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
const filteredDocumentTypes = computed(() => documentTypes.value.filter((type) =>
  type.document_name.toLowerCase().includes(documentTypeSearch.value.trim().toLowerCase()),
))
const cartIsValid = computed(() => cartIsComplete(cart.value, purposeCategory.value, purposeDetails.value))
const requestGroups = computed(() => {
  const groups = new Map()
  for (const item of requests.value) {
    if (!item.batch?.id) continue
    if (!groups.has(item.batch.id)) groups.set(item.batch.id, { ...item.batch, items: [] })
    groups.get(item.batch.id).items.push(item)
  }
  return [...groups.values()].sort((left, right) => right.id - left.id)
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
      [requestReference(item.id), item.batch?.group_reference, item.document_type?.document_name, documentRequestPurpose(item)]
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
  if (!batchKey.value) batchKey.value = newSubmissionKey()
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
  documentTypeSearch.value = ''
  cart.value = []
  batchKey.value = ''
  purposeCategory.value = ''
  purposeDetails.value = ''
}

function newSubmissionKey() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID()
  const bytes = new Uint8Array(16)
  globalThis.crypto.getRandomValues(bytes)
  bytes[6] = (bytes[6] & 0x0f) | 0x40
  bytes[8] = (bytes[8] & 0x3f) | 0x80
  const hex = Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

function addToCart() {
  cart.value = addCartDocument(cart.value, selectedType.value)
  selectedTypeId.value = ''
  requestFormError.value = ''
}

function removeFromCart(id) {
  cart.value = cart.value.filter((item) => item.document_type_id !== id)
}

async function submitRequest() {
  if (requestSubmitting.value || requestFormLoading.value) return
  if (!cartIsValid.value) {
    requestFormError.value = 'Add at least one document and complete every required purpose and copy count.'
    return
  }
  requestSubmitting.value = true
  requestFormError.value = ''
  message.value = ''
  try {
    const created = await api.createRequestBatch(cartSubmissionPayload(cart.value, purposeCategory.value, purposeDetails.value, batchKey.value))
    closeRequestForm(true)
    selectedRequestId.value = created.document_requests?.[0]?.id || null
    selectedPanel.value = 'request'
    message.value = `${created.group_reference} submitted successfully.`
    try {
      await loadRequests()
    } catch {
      error.value = 'Your request was saved, but the list could not refresh. Use Refresh to load it.'
    }
  } catch (err) {
    requestFormError.value = requestError(err)
  } finally {
    requestSubmitting.value = false
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

watch(
  () => route.query.panel,
  (panel) => {
    if (panel === 'appointment') selectedPanel.value = 'appointment'
  },
)

watch([studentSearch, studentStatus], () => {
  studentRequestPage.value = 1
})

watch(purposeCategory, (category, previousCategory) => {
  if (previousCategory === 'Others' && category !== 'Others') purposeDetails.value = ''
  requestFormError.value = ''
})

watch([cart, purposeCategory, purposeDetails], () => {
  for (const item of cart.value) {
    if (item.purposeCategory !== 'Others' && item.purposeDetails) item.purposeDetails = ''
  }
  if (!requestSubmitting.value && batchKey.value) batchKey.value = newSubmissionKey()
}, { deep: true })

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
            <td><strong>{{ item.document_type.document_name }}</strong><small v-if="documentRequestPurpose(item)">{{ documentRequestPurpose(item) }}</small></td>
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

  <section v-if="requestGroups.length" class="dr-table-panel student-request-groups" aria-labelledby="student-groups-title">
    <header class="dr-records-heading"><div><h2 id="student-groups-title">Grouped submissions</h2><p>Each document keeps its own status and Registrar appointment.</p></div></header>
    <details v-for="group in requestGroups" :key="group.id" class="student-request-group">
      <summary><strong>{{ group.group_reference }}</strong><span>{{ group.items.length }} documents</span></summary>
      <ul>
        <li v-for="item in group.items" :key="item.id">
          <button type="button" @click="viewStudentRequest(item)">{{ item.document_type?.document_name }} · {{ requestReference(item.id) }}</button>
          <DocumentRequestStatusBadge :status="item.status" />
        </li>
      </ul>
    </details>
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
          <div class="wide"><dt>Purpose</dt><dd>{{ documentRequestPurpose(selectedRequest) || 'No purpose provided.' }}</dd></div>
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
              <p>Add documents to one cart, review each item, then submit them together.</p>
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
                  Search documents
                  <input v-model="documentTypeSearch" type="search" placeholder="Find an official document" :disabled="requestFormLoading || requestSubmitting" />
                </label>
                <label class="wide">
                  Document type
                  <select v-model="selectedTypeId" :disabled="requestFormLoading || requestSubmitting">
                    <option value="" disabled>{{ requestFormLoading && !documentTypes.length ? 'Loading documents…' : 'Select a document' }}</option>
                    <option v-for="type in filteredDocumentTypes" :key="type.id" :value="type.id" :disabled="cart.some((item) => item.document_type_id === type.id)">{{ documentTypeLabel(type) }}</option>
                  </select>
                </label>
                <button type="button" class="dr-button dr-button--secondary wide" :disabled="!selectedType || requestSubmitting || cart.some((item) => item.document_type_id === selectedType?.id)" @click="addToCart">Add to cart</button>
                <label class="wide">
                  Shared Reason / Purpose of Request
                  <select v-model="purposeCategory" :disabled="requestSubmitting" required>
                    <option value="" disabled>Select a purpose</option>
                    <option v-for="option in DOCUMENT_REQUEST_PURPOSES" :key="option" :value="option">{{ option }}</option>
                  </select>
                </label>
                <Transition name="purpose-field">
                  <label v-if="purposeCategory === 'Others'" class="wide document-purpose-details">
                    Please specify your purpose
                    <textarea
                      v-model="purposeDetails"
                      rows="4"
                      maxlength="500"
                      :disabled="requestSubmitting"
                      required
                      placeholder="Explain why you need this document."
                    ></textarea>
                    <small>{{ purposeDetails.length }} / 500</small>
                  </label>
                </Transition>
              </div>

              <section class="student-request-cart" aria-labelledby="request-cart-title">
                <h3 id="request-cart-title">Review cart · {{ cart.length }} document{{ cart.length === 1 ? '' : 's' }}</h3>
                <p v-if="!cart.length" class="student-request-cart-empty">No documents added yet.</p>
                <article v-for="item in cart" :key="item.document_type_id" class="student-request-cart-item">
                  <div class="student-request-cart-heading">
                    <strong>{{ item.type.document_name }}</strong>
                    <button type="button" class="dr-button dr-button--secondary" :disabled="requestSubmitting" :aria-label="`Remove ${item.type.document_name}`" @click="removeFromCart(item.document_type_id)">Remove</button>
                  </div>
                  <p>{{ item.type.processing_days }} processing day(s) · {{ item.type.requires_appointment ? 'Registrar appointment required' : 'No appointment required' }}</p>
                  <p v-if="item.type.description">{{ item.type.description }}</p>
                  <div class="student-request-cart-fields">
                    <label>Copies<input v-model.number="item.quantity" type="number" min="1" max="10" :disabled="requestSubmitting" required /></label>
                    <span>Estimated fee: ₱{{ formatMoney(Number(item.type.processing_fee) * Number(item.quantity || 0)) }}</span>
                  </div>
                  <label class="student-request-override"><input v-model="item.overridePurpose" type="checkbox" :disabled="requestSubmitting" /> Use a different purpose for this document</label>
                  <div v-if="item.overridePurpose" class="student-request-cart-fields">
                    <label>Purpose<select v-model="item.purposeCategory" :disabled="requestSubmitting" required><option value="" disabled>Select a purpose</option><option v-for="option in DOCUMENT_REQUEST_PURPOSES" :key="option" :value="option">{{ option }}</option></select></label>
                    <label v-if="item.purposeCategory === 'Others'">Please specify your purpose<textarea v-model="item.purposeDetails" maxlength="500" :disabled="requestSubmitting" required></textarea></label>
                  </div>
                </article>
              </section>

              <p v-if="requestFormError" class="notice error" role="alert">{{ requestFormError }}</p>
            </div>

            <footer class="appointment-details-footer">
              <button type="button" class="appointment-close-action" :disabled="requestSubmitting" @click="closeRequestForm">Cancel</button>
              <button type="submit" :disabled="requestFormLoading || requestSubmitting || !cartIsValid">
                {{ requestSubmitting ? 'Submitting…' : `Submit ${cart.length} Request${cart.length === 1 ? '' : 's'}` }}
              </button>
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

</template>

<style scoped src="./documentRequest.css"></style>
