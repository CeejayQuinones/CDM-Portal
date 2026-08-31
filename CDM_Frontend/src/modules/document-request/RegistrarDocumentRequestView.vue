<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import RegistrarRecentActivity from './RegistrarRecentActivity.vue'
import RegistrarRequestQueue from './RegistrarRequestQueue.vue'
import RegistrarWorkspaceSelector from './RegistrarWorkspaceSelector.vue'
import RequestWorkflowReasonModal from './RequestWorkflowReasonModal.vue'
import { formatExactDateTime, requestReference, TIME_FILTERS } from './documentRequestPresentation'
import {
  appointmentReturnContext,
  documentRequestIdFromQuery,
  documentRequestProfileQuery,
  documentRequestSourceQuery,
  withoutDocumentRequestFocus,
} from './documentRequestNavigation'
import { consumeDocumentRequestFocus } from './documentRequestFocus'
import { mergeDocumentRequestRow, requestDocumentName, requestStudentNumber } from './documentRequestRow'
import { documentRequestService as api } from './documentRequestService'

const route = useRoute()
const router = useRouter()
const pendingRequests = ref([])
const processingRequests = ref([])
const selected = ref(null)
const selectedSummary = ref(null)
const search = ref('')
const timeFilter = ref('all')
const loading = ref(false)
const message = ref('')
const error = ref('')
const reasonDialogOpen = ref(false)
const reasonDialogBusy = ref(false)
const modalActionBusy = ref(false)
const rowActionBusyId = ref(null)
const recentActivity = ref(null)
const highlightedRequestId = ref(null)
const pendingPage = ref(1)
const pendingLastPage = ref(1)
const pendingTotal = ref(0)
const processingPage = ref(1)
const processingLastPage = ref(1)
const processingTotal = ref(0)
const focusedRequestId = ref(null)
const selectedQueueKey = ref('pending')
let appliedListQuery = ''
let highlightTimer = null
const PROCESSING_PAGE_SIZE = 10
const HIGHLIGHT_DURATION_MS = 3_000
const requestError = (err) => err.response?.data?.message || 'The request could not be completed.'
const formatMoney = (value) => Number(value).toFixed(2)
const studentProfile = computed(() => selected.value?.student?.user_profile || null)
const physicalLocation = computed(() => selected.value?.student?.physical_record_location || null)
const currentAppointment = computed(() => {
  const appointments = selected.value?.appointments || []

  return appointments.find((appointment) => ['pending', 'confirmed'].includes(appointment.status)) || appointments[0] || null
})
const appointmentContext = computed(() => appointmentReturnContext(route.query))
const requestWorkspaceSelectors = computed(() => [
  {
    key: 'pending',
    eyebrow: 'Active queue',
    title: 'Pending Requests',
    count: pendingTotal.value,
    countLabel: 'waiting',
    description: 'New submissions awaiting review.',
  },
  {
    key: 'processing',
    eyebrow: 'Active work',
    title: 'Processing Requests',
    count: processingTotal.value,
    countLabel: 'active',
    description: 'Approved documents currently being prepared.',
  },
])
const selectedQueue = computed(() =>
  selectedQueueKey.value === 'processing'
    ? {
        key: 'processing',
        title: 'Processing Requests',
        description: 'Approved documents currently being prepared.',
        items: processingRequests.value,
        total: processingTotal.value,
        currentPage: processingPage.value,
        lastPage: processingLastPage.value,
        emptyMessage: 'No document requests are currently being processed.',
        actionLabel: 'Ready for Release',
      }
    : {
        key: 'pending',
        title: 'Pending Requests',
        description: 'New submissions awaiting review.',
        items: pendingRequests.value,
        total: pendingTotal.value,
        currentPage: pendingPage.value,
        lastPage: pendingLastPage.value,
        emptyMessage: 'No pending document requests.',
        actionLabel: '',
      },
)
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
const auditActor = (change) => {
  const profile = change?.registrar_staff?.user?.profile

  return [profile?.first_name, profile?.last_name].filter(Boolean).join(' ') || 'Registrar Staff'
}
const queryValue = (value) => (Array.isArray(value) ? value[0] : value)
const positiveId = (value) => {
  const id = Number(queryValue(value))

  return Number.isInteger(id) && id > 0 ? id : null
}
const listQuerySignature = (query) =>
  JSON.stringify([
    queryValue(query.search) || '',
    queryValue(query.time_filter) || 'all',
    positiveId(query.pending_page) || 1,
    positiveId(query.processing_page) || 1,
  ])

function highlightRequest(requestId, scrollIfNeeded = false) {
  const id = positiveId(requestId)
  if (!id) return

  window.clearTimeout(highlightTimer)

  const applyHighlight = () => {
    highlightedRequestId.value = id

    nextTick(() => {
      const row = document.getElementById(`request-${id}`)
      const list = row?.closest('.work-queue-list')
      if (scrollIfNeeded && row && list) {
        const rowBounds = row.getBoundingClientRect()
        const listBounds = list.getBoundingClientRect()
        const outsideView = rowBounds.top < listBounds.top || rowBounds.bottom > listBounds.bottom

        if (outsideView) row.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
      }
    })

    highlightTimer = window.setTimeout(() => {
      if (highlightedRequestId.value === id) highlightedRequestId.value = null
    }, HIGHLIGHT_DURATION_MS)
  }

  if (highlightedRequestId.value === id) {
    highlightedRequestId.value = null
    nextTick(applyHighlight)
  } else {
    applyHighlight()
  }
}

function applyRouteQuery(query) {
  const nextTimeFilter = String(queryValue(query.time_filter) || 'all')

  search.value = String(queryValue(query.search) || '')
  timeFilter.value = TIME_FILTERS.some((option) => option.value === nextTimeFilter) ? nextTimeFilter : 'all'
  focusedRequestId.value = documentRequestIdFromQuery(query)
  pendingPage.value = positiveId(query.pending_page) || 1
  processingPage.value = positiveId(query.processing_page) || 1
  selected.value = null
  selectedSummary.value = null
}

function viewCabinet() {
  if (!physicalLocation.value) return
  router.push({
    name: 'physical-records',
    query: {
      ...documentRequestSourceQuery({
        requestId: selected.value.id,
        studentId: selected.value.student.id,
        search: search.value,
        timeFilter: timeFilter.value,
        pendingPage: pendingPage.value,
        processingPage: processingPage.value,
      }),
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
    query: documentRequestProfileQuery({
      requestId: selected.value.id,
      search: search.value,
      timeFilter: timeFilter.value,
      pendingPage: pendingPage.value,
      processingPage: processingPage.value,
    }),
  })
}

function returnToAppointment() {
  if (appointmentContext.value) router.push(appointmentContext.value.to)
}

function closeDetails() {
  const requestId = selected.value?.id
  selected.value = null
  selectedSummary.value = null
  reasonDialogOpen.value = false
  highlightRequest(requestId)

  if (route.query.request_id || route.query.focus) {
    focusedRequestId.value = null
    router.replace({
      name: 'registrar-document-requests',
      query: withoutDocumentRequestFocus(route.query),
    })
  }
}

function handleEscape(event) {
  if (event.key === 'Escape' && selected.value) closeDetails()
}

async function refresh(resetPages = false) {
  if (resetPages) {
    pendingPage.value = 1
    processingPage.value = 1
  }
  loading.value = true
  error.value = ''
  try {
    const queues = await api.registrarRequests({
      view: 'work_queues',
      search: search.value || undefined,
      time_filter: timeFilter.value,
      pending_page: pendingPage.value,
      processing_page: processingPage.value,
    })
    pendingRequests.value = queues.pending.data
    pendingPage.value = queues.pending.current_page || pendingPage.value
    pendingLastPage.value = queues.pending.last_page
    pendingTotal.value = queues.pending.total
    processingRequests.value = queues.processing.data
    processingPage.value = queues.processing.current_page || processingPage.value
    processingLastPage.value = queues.processing.last_page
    processingTotal.value = queues.processing.total
  } catch (err) {
    error.value = requestError(err)
  } finally {
    loading.value = false
  }
}

async function revealFocusedRequest() {
  if (!focusedRequestId.value) {
    if (route.query.request_id || route.query.focus) {
      error.value = 'The requested document request could not be opened.'
      router.replace({
        name: 'registrar-document-requests',
        query: withoutDocumentRequestFocus(route.query),
      })
    }
    return
  }

  if (processingRequests.value.some(({ id }) => id === focusedRequestId.value)) selectedQueueKey.value = 'processing'
  else if (pendingRequests.value.some(({ id }) => id === focusedRequestId.value)) selectedQueueKey.value = 'pending'

  const opened = await selectRequest({ id: focusedRequestId.value })
  if (!opened) {
    router.replace({
      name: 'registrar-document-requests',
      query: withoutDocumentRequestFocus(route.query),
    })
    return
  }

  await nextTick()
  document.getElementById(`request-${focusedRequestId.value}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

async function applyFilters() {
  focusedRequestId.value = null
  selected.value = null
  await refresh(true)
}

async function selectTimeFilter(value) {
  timeFilter.value = value
  await applyFilters()
}

async function changePage(queue, nextPage) {
  if (queue === 'pending') pendingPage.value = nextPage
  else processingPage.value = nextPage
  selected.value = null
  selectedSummary.value = null
  focusedRequestId.value = null
  await refresh()
}

async function selectRequest(item) {
  error.value = ''
  selectedSummary.value = item
  highlightRequest(item.id)
  try {
    const detail = await api.registrarRequest(item.id)
    const existingQueueItem =
      pendingRequests.value.find((request) => request.id === item.id) ||
      processingRequests.value.find((request) => request.id === item.id)

    selected.value = mergeDocumentRequestRow(existingQueueItem, item, detail)
    selectedSummary.value = selected.value
    return true
  } catch (err) {
    selectedSummary.value = null
    error.value = requestError(err)
    return false
  }
}

function openReject() {
  if (!selected.value || !['pending', 'processing'].includes(selected.value.status)) return

  reasonDialogOpen.value = true
}

function updateWorkQueues(updated, previousStatus) {
  if (!updated?.id) return

  const wasPendingVisible = pendingRequests.value.some((item) => item.id === updated.id)
  const wasProcessingVisible = processingRequests.value.some((item) => item.id === updated.id)

  pendingRequests.value = pendingRequests.value.filter((item) => item.id !== updated.id)
  processingRequests.value = processingRequests.value.filter((item) => item.id !== updated.id)

  if (wasPendingVisible) pendingTotal.value = Math.max(0, pendingTotal.value - 1)
  if (wasProcessingVisible) processingTotal.value = Math.max(0, processingTotal.value - 1)

  if (updated.status === 'processing') {
    processingTotal.value += previousStatus === 'pending' && wasPendingVisible ? 1 : 0
    if (processingPage.value === 1) {
      processingRequests.value = [updated, ...processingRequests.value].slice(0, PROCESSING_PAGE_SIZE)
    }

    if (previousStatus === 'pending') selectedQueueKey.value = 'processing'
  }

  selectedSummary.value = updated
}

async function updateRequestStatus(item, nextAction, reason = null) {
  if (!item) return false
  error.value = ''
  message.value = ''
  try {
    const previousStatus = item.status
    const existingQueueItem =
      pendingRequests.value.find((request) => request.id === item.id) ||
      processingRequests.value.find((request) => request.id === item.id)
    const requestBeforeUpdate = mergeDocumentRequestRow(
      existingQueueItem,
      selectedSummary.value?.id === item.id ? selectedSummary.value : null,
      selected.value?.id === item.id ? selected.value : null,
      item,
    )
    const payload = {
      action: nextAction,
      reason,
    }
    if (Object.hasOwn(item, 'remarks')) payload.remarks = item.remarks || null

    const updateResponse = await api.updateRequest(item.id, payload)
    const updated = mergeDocumentRequestRow(requestBeforeUpdate, updateResponse)
    if (selected.value?.id === updated.id) selected.value = updated
    updateWorkQueues(updated, previousStatus)
    if (['pending', 'processing'].includes(updated.status)) {
      highlightRequest(updated.id, previousStatus !== updated.status)
    }
    recentActivity.value?.refresh()
    message.value = {
      approve: 'Request approved and moved to Processing.',
      reject: 'Request rejected and moved to History.',
      cancel: 'Request cancelled and moved to History.',
      ready_for_release: 'Request moved to Appointments & Release.',
    }[nextAction]

    if (!pendingRequests.value.length && pendingPage.value > 1) {
      pendingPage.value -= 1
      await refresh()
    } else if (!processingRequests.value.length && processingPage.value > 1) {
      processingPage.value -= 1
      await refresh()
    }
    return true
  } catch (err) {
    error.value = requestError(err)
    return false
  }
}

async function approveSelected() {
  modalActionBusy.value = true
  try {
    await updateRequestStatus(selected.value, 'approve')
  } finally {
    modalActionBusy.value = false
  }
}

async function markReadyForRelease(item) {
  rowActionBusyId.value = item.id
  try {
    await updateRequestStatus(item, 'ready_for_release')
  } finally {
    rowActionBusyId.value = null
  }
}

async function confirmReject({ reason }) {
  reasonDialogBusy.value = true
  try {
    const updated = await updateRequestStatus(selected.value, 'reject', reason)
    if (updated) closeDetails()
  } finally {
    reasonDialogBusy.value = false
  }
}

onMounted(async () => {
  window.addEventListener('keydown', handleEscape)
  appliedListQuery = listQuerySignature(route.query)
  applyRouteQuery(route.query)
  await refresh()
  const rememberedRequestId = consumeDocumentRequestFocus()
  if (rememberedRequestId) {
    if (processingRequests.value.some(({ id }) => id === rememberedRequestId)) selectedQueueKey.value = 'processing'
    highlightRequest(rememberedRequestId, true)
  }
  await revealFocusedRequest()
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', handleEscape)
  window.clearTimeout(highlightTimer)
})

watch(
  () => route.fullPath,
  async () => {
    const nextListQuery = listQuerySignature(route.query)
    const listChanged = nextListQuery !== appliedListQuery
    appliedListQuery = nextListQuery
    applyRouteQuery(route.query)
    if (listChanged) await refresh()
    await revealFocusedRequest()
  },
  { flush: 'post' },
)
</script>

<template>
  <section class="page-header">
    <button
      v-if="appointmentContext"
      class="appointment-context-back"
      type="button"
      @click="returnToAppointment"
    >
      {{ appointmentContext.label }}
    </button>
    <p class="page-kicker">Registrar Staff</p>
    <h1 class="page-title">Document Request Work Queues</h1>
    <p class="page-description">Review new submissions, then move approved requests through document processing.</p>
  </section>

  <p v-if="message" class="notice success">{{ message }}</p>
  <p v-if="error" class="notice error">{{ error }}</p>

  <div v-if="loading && !pendingRequests.length && !processingRequests.length" class="appointment-workspace-skeleton request-workspace-skeleton" aria-label="Loading document request queues" aria-busy="true">
    <div class="appointment-filter-skeleton skeleton-shimmer"></div>
    <div class="appointment-workspace-skeleton-grid">
      <aside class="appointment-selector-skeletons">
        <span v-for="index in 2" :key="index" class="appointment-selector-skeleton skeleton-shimmer"></span>
      </aside>
      <section class="appointment-table-skeleton">
        <span class="appointment-heading-skeleton skeleton-shimmer"></span>
        <span v-for="index in 6" :key="index" class="appointment-row-skeleton skeleton-shimmer"></span>
      </section>
    </div>
  </div>

  <template v-else>
    <section class="dr-panel queue-filter-panel appointment-filter-panel request-workspace-filter">
      <nav class="group-tabs time-filter-tabs" aria-label="Request activity period">
        <button
          v-for="option in TIME_FILTERS"
          :key="option.value"
          type="button"
          :class="{ active: timeFilter === option.value }"
          :aria-pressed="timeFilter === option.value"
          @click="selectTimeFilter(option.value)"
        >{{ option.label }}</button>
      </nav>
      <form class="toolbar" @submit.prevent="applyFilters">
        <input v-model="search" aria-label="Search document requests" placeholder="REQ-000001, student number, name, or document" />
        <button :disabled="loading">{{ loading ? 'Loading…' : 'Search' }}</button>
      </form>
    </section>

    <section class="registrar-workspace-grid" aria-label="Active document request work queues">
      <RegistrarWorkspaceSelector
        v-model="selectedQueueKey"
        :items="requestWorkspaceSelectors"
        aria-label="Select document request queue"
      />

      <Transition name="appointment-workspace-swap" mode="out-in">
        <RegistrarRequestQueue
          :key="selectedQueue.key"
          :title="selectedQueue.title"
          :description="selectedQueue.description"
          :items="selectedQueue.items"
          :total="selectedQueue.total"
          :current-page="selectedQueue.currentPage"
          :last-page="selectedQueue.lastPage"
          :loading="loading"
          :empty-message="selectedQueue.emptyMessage"
          :selected-id="selected?.id"
          :highlighted-id="highlightedRequestId"
          :action-label="selectedQueue.actionLabel"
          :action-busy-id="rowActionBusyId"
          @select="selectRequest"
          @page-change="changePage(selectedQueue.key, $event)"
          @action="markReadyForRelease"
        />
      </Transition>
    </section>
  </template>

  <section class="request-recent-activity-section" aria-label="Recent document request activity">
    <RegistrarRecentActivity ref="recentActivity" />
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
              <p>{{ requestDocumentName(selected) }}</p>
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
              <div><dt>Student No.</dt><dd>{{ requestStudentNumber(selected) }}</dd></div>
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

          <section class="request-detail-section" aria-labelledby="request-audit-heading">
            <div class="request-section-heading"><span>03</span><h3 id="request-audit-heading">Workflow Audit</h3></div>
            <p v-if="!selected.status_changes?.length" class="request-purpose">No recorded workflow changes yet.</p>
            <ol v-else class="workflow-audit-list">
              <li v-for="change in selected.status_changes" :key="change.id">
                <span>
                  <strong>{{ formatStatus(change.action) }}</strong>
                  <small>{{ formatStatus(change.from_status) }} → {{ formatStatus(change.to_status) }}</small>
                </span>
                <span>
                  <small>{{ auditActor(change) }} · {{ formatExactDateTime(change.created_at) }}</small>
                  <p v-if="change.reason">{{ change.reason }}</p>
                </span>
              </li>
            </ol>
          </section>

          <p v-if="!requestedDocumentAvailable" class="record-warning request-detail-warning">
            The requested document is not marked available in this student's document record. Registrar Staff may still
            continue processing the request.
          </p>

        </div>

        <footer class="request-detail-footer">
          <button
            v-if="appointmentContext"
            type="button"
            class="secondary-action"
            @click="returnToAppointment"
          >
            {{ appointmentContext.label }}
          </button>
          <button type="button" class="secondary-action" @click="viewStudentProfile">View Student Profile</button>
          <button type="button" class="secondary-action" :disabled="!physicalLocation" @click="viewCabinet">View Physical Record</button>
          <button
            v-if="selected.status === 'pending'"
            type="button"
            class="primary-action"
            :disabled="modalActionBusy"
            @click="approveSelected"
          >
            {{ modalActionBusy ? 'Approving…' : 'Approve' }}
          </button>
          <button
            v-if="['pending', 'processing'].includes(selected.status)"
            type="button"
            class="danger-action"
            :disabled="modalActionBusy"
            @click="openReject"
          >
            Reject
          </button>
          <button type="button" class="close-action" @click="closeDetails">Close</button>
        </footer>
      </section>
    </div>
  </Teleport>

  <RequestWorkflowReasonModal
    :open="reasonDialogOpen"
    title="Reject Request"
    description="Confirm the request and provide a reason for rejection. This action is recorded in the workflow audit."
    confirm-label="Reject Request"
    reason-label="Reason for rejection"
    :request="selected"
    :busy="reasonDialogBusy"
    @close="reasonDialogOpen = false"
    @confirm="confirmReject"
  />
</template>

<style scoped src="./documentRequest.css"></style>
