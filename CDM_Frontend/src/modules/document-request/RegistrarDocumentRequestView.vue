<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import DocumentRequestPageHeader from './DocumentRequestPageHeader.vue'
import DocumentRequestDialog from './DocumentRequestDialog.vue'
import DocumentRequestStatusBadge from './DocumentRequestStatusBadge.vue'
import CdmButton from '../../design-system/CdmButton.vue'
import RegistrarPhysicalRetrieval from './RegistrarPhysicalRetrieval.vue'
import RegistrarStudentRequests from './RegistrarStudentRequests.vue'
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
import { documentRequestPurpose } from './documentRequestPurpose'
import { mergeDocumentRequestRow, requestDocumentName, requestStudentNumber } from './documentRequestRow'
import { documentRequestService as api } from './documentRequestService'
import { profilePhotoUrl } from '../../utils/profilePhoto'

const route = useRoute()
const router = useRouter()
const selected = ref(null)
const search = ref('')
const documentTypeId = ref('')
const timeFilter = ref('all')
const message = ref('')
const error = ref('')
const reasonDialogOpen = ref(false)
const reasonDialogBusy = ref(false)
const modalActionBusy = ref(false)
const assignmentDate = ref('')
const assignmentWindow = ref('')
const assigningDate = ref(false)
const resendingClaimCode = ref(false)
const pendingPage = ref(1)
const processingPage = ref(1)
const focusedRequestId = ref(null)
const unifiedWorkspace = ref(null)
const returnQueue = ref(null)
let selectedRequestSequence = 0
const requestError = (err) => err.response?.data?.message || 'The request could not be completed.'
const formatMoney = (value) => Number(value).toFixed(2)
const studentProfile = computed(() => selected.value?.student?.user_profile || null)
const studentPhotoFailed = ref(false)
const studentPhoto = computed(() => studentPhotoFailed.value ? '' : profilePhotoUrl(studentProfile.value))
watch(studentProfile, () => { studentPhotoFailed.value = false })
const physicalLocation = computed(() => selected.value?.student?.physical_record_location || null)
const currentAppointment = computed(() => {
  const appointments = selected.value?.appointments || []

  return appointments.find((appointment) => ['pending', 'confirmed'].includes(appointment.status)) || appointments[0] || null
})
const appointmentWindowOptions = computed(() => selected.value?.appointment_window_options || [])
watch(selected, (request) => {
  const options = request?.appointment_window_options || []
  const existing = request?.appointments?.find((appointment) => ['pending', 'confirmed'].includes(appointment.status))?.service_window
  assignmentWindow.value = options.includes(existing) ? existing : options.length === 1 ? options[0] : ''
})
const appointmentContext = computed(() => appointmentReturnContext(route.query))
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
  if (change?.actor_type === 'system') return 'System'
  const profile = change?.registrar_staff?.user?.profile

  return [profile?.first_name, profile?.last_name].filter(Boolean).join(' ') || 'Registrar Staff'
}
const queryValue = (value) => (Array.isArray(value) ? value[0] : value)
const positiveId = (value) => {
  const id = Number(queryValue(value))

  return Number.isInteger(id) && id > 0 ? id : null
}
function applyRouteQuery(query) {
  const nextTimeFilter = String(queryValue(query.time_filter) || 'all')

  search.value = String(queryValue(query.search) || '')
  documentTypeId.value = positiveId(query.document_type_id) || ''
  timeFilter.value = TIME_FILTERS.some((option) => option.value === nextTimeFilter) ? nextTimeFilter : 'all'
  focusedRequestId.value = documentRequestIdFromQuery(query)
  pendingPage.value = positiveId(query.pending_page) || 1
  processingPage.value = positiveId(query.processing_page) || 1
  selected.value = null
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
        ...unifiedWorkspace.value?.contextQuery(),
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
      studentId: selected.value.student.id,
      search: search.value,
      timeFilter: timeFilter.value,
      pendingPage: pendingPage.value,
      processingPage: processingPage.value,
      ...unifiedWorkspace.value?.contextQuery(),
    }),
  })
}

function returnToAppointment() {
  if (appointmentContext.value) router.push(appointmentContext.value.to)
}

function closeDetails() {
  selectedRequestSequence++
  selected.value = null
  reasonDialogOpen.value = false

  if (route.query.request_id || route.query.focus) {
    focusedRequestId.value = null
    router.replace({
      name: 'registrar-document-requests',
      query: withoutDocumentRequestFocus(route.query),
    })
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

  const opened = await selectRequest({ id: focusedRequestId.value })
  if (!opened) {
    router.replace({
      name: 'registrar-document-requests',
      query: withoutDocumentRequestFocus(route.query),
    })
    return
  }

}

async function selectRequest(item) {
  const sequence = ++selectedRequestSequence
  error.value = ''
  try {
    const detail = await api.registrarRequest(item.id)
    if (sequence !== selectedRequestSequence) return false
    selected.value = mergeDocumentRequestRow(item, detail)
    return true
  } catch (err) {
    if (sequence !== selectedRequestSequence) return false
    error.value = requestError(err)
    return false
  }
}

function openReject() {
  if (!selected.value || selected.value.status !== 'pending') return

  reasonDialogOpen.value = true
}

async function updateRequestStatus(item, nextAction, reason = null) {
  if (!item) return false
  error.value = ''
  message.value = ''
  try {
    const payload = {
      action: nextAction,
      reason,
    }
    if (Object.hasOwn(item, 'remarks')) payload.remarks = item.remarks || null

    const updateResponse = await api.updateRequest(item.id, payload)
    const updated = mergeDocumentRequestRow(item, updateResponse)
    if (selected.value?.id === updated.id) selected.value = updated
    unifiedWorkspace.value?.refresh()
    message.value = updateResponse._demo_claim_code
      ? `DEMO ONLY — claim code: ${updateResponse._demo_claim_code}. No email is sent in offline demo mode.`
      : {
      approve: 'Request approved. Appointment instructions were sent to the registered email.',
      reject: 'Request rejected and moved to History.',
      cancel: 'Request cancelled and moved to History.',
    }[nextAction]

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

async function assignSelectedDate() {
  if (!selected.value || !assignmentDate.value || !assignmentWindow.value) return
  assigningDate.value = true
  error.value = ''
  try {
    const updated = await api.assignAppointment(selected.value.id, { appointment_date: assignmentDate.value, service_window: assignmentWindow.value })
    selected.value = mergeDocumentRequestRow(selected.value, updated)
    message.value = 'Appointment date assigned. The request remains Pending until approval.'
  } catch (err) {
    error.value = requestError(err)
  } finally {
    assigningDate.value = false
  }
}

async function resendSelectedClaimCode() {
  if (!selected.value || selected.value.status !== 'approved') return
  resendingClaimCode.value = true; error.value = ''
  try {
    const updated = await api.resendClaimCode(selected.value.id)
    message.value = updated._demo_claim_code
      ? `DEMO ONLY — new claim code: ${updated._demo_claim_code}. No email is sent in offline demo mode.`
      : 'A new claim code was sent to the student.'
  } catch (err) { error.value = requestError(err) } finally { resendingClaimCode.value = false }
}

async function confirmReject({ reason }) {
  if (reasonDialogBusy.value) return
  reasonDialogBusy.value = true
  try {
    const updated = await updateRequestStatus(selected.value, 'reject', reason)
    if (updated) {
      reasonDialogOpen.value = false
      await nextTick()
      closeDetails()
    }
  } finally {
    reasonDialogBusy.value = false
  }
}

onMounted(async () => {
  applyRouteQuery(route.query)
  const rememberedRequestId = consumeDocumentRequestFocus()
  if (rememberedRequestId) focusedRequestId.value = rememberedRequestId
  await revealFocusedRequest()
})

watch(
  () => route.fullPath,
  async () => {
    applyRouteQuery(route.query)
    await revealFocusedRequest()
  },
  { flush: 'post' },
)
</script>

<template>
  <DocumentRequestPageHeader
    eyebrow="Registrar Staff"
    title="Document Requests"
    description="Review and process active student document requests."
  >
    <template v-if="appointmentContext" #actions>
      <CdmButton type="button" variant="outline" @click="returnToAppointment">
        {{ appointmentContext.label }}
      </CdmButton>
    </template>
  </DocumentRequestPageHeader>

  <p v-if="message" class="notice success">{{ message }}</p>
  <p v-if="error && !selected" class="notice error" role="alert">{{ error }}</p>

  <RegistrarStudentRequests ref="unifiedWorkspace" @process="selectRequest" @retrieval-change="returnQueue?.refresh()" />
  <RegistrarPhysicalRetrieval ref="returnQueue" return-only @changed="unifiedWorkspace?.refresh()" />

  <DocumentRequestDialog
    v-if="selected"
    labelledby="request-detail-title"
    panel-class="request-detail-dialog"
    wide
    :busy="modalActionBusy || assigningDate || resendingClaimCode"
    @cancel="closeDetails"
  >
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
          <p v-if="error" class="notice error" role="alert">{{ error }}</p>
          <section class="request-identity">
            <div class="request-student-avatar">
              <img
                v-if="studentPhoto"
                :src="studentPhoto"
                :alt="`${studentFullName} photo`"
                @error="studentPhotoFailed = true"
              />
              <span v-else>{{ studentInitials }}</span>
            </div>
            <div>
              <strong class="request-reference">{{ referenceFor(selected) }}</strong>
              <h3>{{ studentFullName }}</h3>
              <p>{{ requestDocumentName(selected) }}</p>
            </div>
            <DocumentRequestStatusBadge class="request-detail-status" :status="selected.status" />
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
            <p class="request-purpose">{{ documentRequestPurpose(selected) || selected.remarks || 'No notes or purpose provided.' }}</p>
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
          <CdmButton
            v-if="appointmentContext"
            type="button"
            variant="outline"
            @click="returnToAppointment"
          >
            {{ appointmentContext.label }}
          </CdmButton>
          <CdmButton type="button" variant="outline" @click="viewStudentProfile">View Student Profile</CdmButton>
          <CdmButton type="button" variant="outline" :disabled="!physicalLocation" @click="viewCabinet">View Physical Record</CdmButton>
          <CdmButton
            v-if="selected.status === 'pending'"
            type="button"
            :disabled="modalActionBusy || !currentAppointment"
            @click="approveSelected"
          >
            {{ modalActionBusy ? 'Approving…' : 'Approve' }}
          </CdmButton>
          <CdmButton v-if="selected.status === 'approved' && !selected.code_verified_at" type="button" variant="outline" :disabled="resendingClaimCode" @click="resendSelectedClaimCode">
            {{ resendingClaimCode ? 'Sending…' : 'Resend Claim Code' }}
          </CdmButton>
          <label v-if="selected.status === 'pending'" class="appointment-date-assignment">
            <span>Appointment date</span>
            <input v-model="assignmentDate" type="date" :min="new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' })" />
          </label>
          <label v-if="selected.status === 'pending' && appointmentWindowOptions.length > 1" class="appointment-date-assignment">
            <span>Registrar window</span>
            <select v-model="assignmentWindow"><option value="" disabled>Select window</option><option v-for="window in appointmentWindowOptions" :key="window" :value="window">{{ window }}</option></select>
          </label>
          <span v-else-if="selected.status === 'pending' && assignmentWindow" class="appointment-date-assignment">{{ assignmentWindow }}</span>
          <CdmButton v-if="selected.status === 'pending'" type="button" variant="outline" :disabled="assigningDate || !assignmentDate || !assignmentWindow" @click="assignSelectedDate">
            {{ assigningDate ? 'Assigning…' : currentAppointment ? 'Change Date' : 'Assign Date' }}
          </CdmButton>
          <CdmButton
            v-if="selected.status === 'pending'"
            type="button"
            variant="danger"
            :disabled="modalActionBusy"
            @click="openReject"
          >
            Reject
          </CdmButton>
          <CdmButton type="button" variant="outline" @click="closeDetails">Close</CdmButton>
        </footer>
  </DocumentRequestDialog>

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
