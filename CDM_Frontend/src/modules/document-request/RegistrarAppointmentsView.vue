<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PaginationControls from '../../components/PaginationControls.vue'
import { READY_FOR_RELEASE_GROUP } from '../../config/navbarContexts'
import RegistrarRecentActivity from './RegistrarRecentActivity.vue'
import RegistrarWorkspaceSelector from './RegistrarWorkspaceSelector.vue'
import RequestWorkflowReasonModal from './RequestWorkflowReasonModal.vue'
import { useAppointmentAvailabilityState } from './appointmentAvailabilityState'
import { rememberDocumentRequestFocus } from './documentRequestFocus'
import { appointmentDocumentRequestQuery } from './documentRequestNavigation'
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
const cancellingAppointmentId = ref(null)
const cancellationTarget = ref(null)
const cancellationRequest = ref(null)
const cancellationDialogOpen = ref(false)
const returnTarget = ref(null)
const returnDialogOpen = ref(false)
const requestIdFilter = ref(null)
const appointmentIdFilter = ref(null)
const focusedAppointmentId = ref(null)
const updatingAppointmentId = ref(null)
const detailsOpen = ref(false)
const detailsLoading = ref(false)
const detailsError = ref('')
const detailsAppointment = ref(null)
const detailsRequest = ref(null)
const detailsConfirmed = ref(false)
const detailsEntryPoint = ref('view')
const detailsDialog = ref(null)
const recentActivityKey = ref(0)
const scheduleViewRef = ref(null)
const selectedScheduleSection = ref('awaiting')
const recentActivityOpen = ref(false)
const recentActivityDialog = ref(null)
let detailsTrigger = null
let previousRouteGroup = null
let appointmentHighlightTimer = null
const { isOpen: availabilityOpen, close: hideAvailabilitySettings } = useAppointmentAvailabilityState()
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
const activeStatuses = ['pending', 'confirmed']
const RETURN_REASONS = [
  'Wrong request selected',
  'Document preparation error',
  'Incorrect document',
  'Needs correction',
  'Other',
]
const CANCELLATION_REASONS = [
  'Student requested cancellation',
  'Student is unavailable',
  'Scheduling conflict',
  'Duplicate appointment',
  'Other',
]
const requestError = (err) => err.response?.data?.message || 'The appointment could not be completed.'
const appointmentDetailsError = (err) =>
  err.response?.data?.message || 'The appointment details could not be loaded.'
const availabilityRequestError = (err) =>
  Object.values(err.response?.data?.errors || {})[0]?.[0] ||
  err.response?.data?.message ||
  'The appointment availability settings could not be saved.'
const queryValue = (value) => (Array.isArray(value) ? value[0] : value)
const isReadyForReleaseView = computed(() => queryValue(route.query.group) === READY_FOR_RELEASE_GROUP)
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
const manilaToday = () =>
  new Intl.DateTimeFormat('en-CA', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    timeZone: 'Asia/Manila',
  }).format(new Date())
const appointmentDateKey = (appointment) => String(appointment?.appointment_date || '').slice(0, 10)
const appointmentSortValue = (appointment) =>
  `${appointmentDateKey(appointment)}T${String(appointment?.appointment_time || '00:00').slice(0, 8)}`
const sortedAppointments = computed(() =>
  [...appointments.value].sort((left, right) => appointmentSortValue(left).localeCompare(appointmentSortValue(right))),
)
const awaitingConfirmation = computed(() => sortedAppointments.value.filter((item) => item.status === 'pending'))
const todaysAppointments = computed(() => {
  const today = manilaToday()

  return sortedAppointments.value.filter((item) => item.status !== 'pending' && appointmentDateKey(item) === today)
})
const upcomingAppointments = computed(() => {
  const today = manilaToday()

  return sortedAppointments.value.filter(
    (item) => item.status === 'confirmed' && appointmentDateKey(item) > today,
  )
})
const appointmentSelectors = computed(() => [
  {
    key: 'awaiting',
    eyebrow: 'Action needed',
    title: 'Awaiting Confirmation',
    count: awaitingConfirmation.value.length,
    countLabel: 'waiting',
    description: 'Pending appointments that still need confirmation.',
    items: awaitingConfirmation.value,
  },
  {
    key: 'upcoming',
    eyebrow: 'Scheduled',
    title: 'Upcoming Appointments',
    count: upcomingAppointments.value.length,
    countLabel: 'upcoming',
    description: 'Confirmed appointments scheduled after today.',
    items: upcomingAppointments.value,
  },
])
const selectedAppointmentSection = computed(
  () => appointmentSelectors.value.find(({ key }) => key === selectedScheduleSection.value) || appointmentSelectors.value[0],
)
const selectedAppointmentEmptyMessage = computed(() =>
  selectedScheduleSection.value === 'upcoming'
    ? 'No upcoming appointments.'
    : 'No appointments awaiting confirmation.',
)

async function activateScheduleView() {
  await nextTick()
  scheduleViewRef.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

async function selectScheduleSection(key) {
  selectedScheduleSection.value = key
  await nextTick()
}

async function openRecentActivity() {
  recentActivityOpen.value = true
  await nextTick()
  recentActivityDialog.value?.focus()
}

function closeRecentActivity() {
  recentActivityOpen.value = false
}

function highlightAppointment(appointmentId) {
  window.clearTimeout(appointmentHighlightTimer)
  focusedAppointmentId.value = appointmentId
  appointmentHighlightTimer = window.setTimeout(() => {
    if (focusedAppointmentId.value === appointmentId) focusedAppointmentId.value = null
  }, 3_000)
}
const detailStudent = computed(() => detailsRequest.value?.student || detailsAppointment.value?.student || null)
const detailAppointmentRecord = computed(
  () =>
    detailsRequest.value?.appointments?.find(({ id }) => id === detailsAppointment.value?.id) || detailsAppointment.value,
)
const detailAppointmentStatus = computed(() => detailAppointmentRecord.value?.status || null)
const terminalAppointmentStatuses = ['completed', 'cancelled', 'no_show']
const detailsAreReadOnly = computed(() => terminalAppointmentStatuses.includes(detailAppointmentStatus.value))
const isReleaseCompletionFlow = computed(() =>
  ['ready-release', 'appointment-complete'].includes(detailsEntryPoint.value),
)
const canCancelAppointment = computed(
  () =>
    !detailsLoading.value &&
    detailsEntryPoint.value === 'view' &&
    ['pending', 'confirmed'].includes(detailAppointmentStatus.value),
)
const showCompletionAction = computed(
  () =>
    !detailsLoading.value &&
    isReleaseCompletionFlow.value &&
    detailsRequest.value?.status === 'ready_for_release',
)
const showGoToRequestAction = computed(
  () =>
    !detailsLoading.value &&
    detailsEntryPoint.value === 'appointment-complete' &&
    ['pending', 'processing'].includes(detailsRequest.value?.status),
)
const canCompleteRelease = computed(
  () =>
    showCompletionAction.value &&
    Boolean(detailsRequest.value) &&
    detailsRequest.value?.status === 'ready_for_release' &&
    detailAppointmentStatus.value === 'confirmed',
)
const releaseRequirementMessage = computed(() => {
  if (detailsLoading.value || !isReleaseCompletionFlow.value) return ''
  if (!detailsRequest.value) return 'The document request details could not be loaded. Completion is unavailable.'
  if (detailsRequest.value.status === 'pending') return 'This document request is still awaiting approval.'
  if (detailsRequest.value.status === 'processing') return 'This document is still being prepared.'
  if (detailsRequest.value.status === 'released') {
    return 'This document has already been released. No further completion action is available.'
  }
  if (!detailAppointmentRecord.value) {
    return 'A confirmed appointment is required before this document can be released.'
  }
  if (detailAppointmentStatus.value !== 'confirmed') {
    return `This appointment is ${formatStatus(detailAppointmentStatus.value)}. Only confirmed appointments can be completed.`
  }
  if (detailsRequest.value.status !== 'ready_for_release') {
    return `This document request is ${formatStatus(detailsRequest.value.status)} and cannot be released from the appointment.`
  }

  return 'Document is ready for release.'
})
const releaseMessageTone = computed(() => {
  if (detailsRequest.value?.status === 'released') return 'neutral'
  if (canCompleteRelease.value) return 'success'

  return 'warning'
})
const detailDocumentName = computed(
  () =>
    detailsRequest.value?.document_type?.document_name ||
    detailsAppointment.value?.document_request?.document_type?.document_name ||
    'Not available',
)
const detailTitle = computed(() => {
  if (detailsEntryPoint.value === 'ready-release') return 'Release Document'
  if (detailsEntryPoint.value === 'appointment-complete') return 'Complete Appointment'
  if (detailsConfirmed.value) return 'Appointment Confirmed'
  if (detailsAreReadOnly.value) return 'Appointment Details'
  if (detailsRequest.value?.status === 'ready_for_release') return 'Release Document'

  return 'Appointment Details'
})
const completionActionLabel = computed(() =>
  detailsEntryPoint.value === 'ready-release' ? 'Complete Appointment' : 'Release Document',
)

function formatAppointmentTime(value) {
  const time = String(value || '').slice(0, 5)
  if (!time) return 'Time not available'

  const [hours, minutes] = time.split(':').map(Number)

  return new Intl.DateTimeFormat('en-PH', { hour: 'numeric', minute: '2-digit' }).format(
    new Date(2000, 0, 1, hours, minutes),
  )
}

function formatDate(value) {
  if (!value) return 'Not available'

  return new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeZone: 'Asia/Manila',
  }).format(new Date(`${String(value).slice(0, 10)}T00:00:00+08:00`))
}

function formatMoney(value) {
  return Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function formatStatus(value) {
  return value ? value.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Not available'
}

function physicalRecordLabel(student) {
  const location = student?.physical_record_location
  const slot = location?.cabinet_slot
  const cabinet = slot?.cabinet

  return location ? `Cabinet ${cabinet?.cabinet_code || '—'} · Slot ${slot?.slot_code || '—'}` : 'Not assigned'
}

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

async function prepareAvailabilitySettings() {
  resetBlockedDateForm()
  availabilityMessage.value = ''
  availabilityError.value = ''
  await nextTick()
  availabilityDialog.value?.focus()
  await loadAvailabilitySettings()
}

function closeAvailabilitySettings() {
  hideAvailabilitySettings()
}

function resetAvailabilitySettings() {
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
  const nextGroup = String(queryValue(query.group) || 'active')
  const nextTimeFilter = String(queryValue(query.time_filter) || 'all')

  search.value = String(queryValue(query.search) || '')
  date.value = String(queryValue(query.date) || '')
  timeFilter.value = TIME_FILTERS.some((option) => option.value === nextTimeFilter) ? nextTimeFilter : 'all'
  requestIdFilter.value = positiveId(query.request_id)
  appointmentIdFilter.value = positiveId(query.appointment_id)
  focusedAppointmentId.value = focusedId(query.focus) || appointmentIdFilter.value
  page.value = positiveId(query.page) || 1
  releasePage.value = positiveId(query.release_page) || 1

  if (nextGroup === 'upcoming') selectedScheduleSection.value = 'upcoming'
  else if (previousRouteGroup === READY_FOR_RELEASE_GROUP || previousRouteGroup === null)
    selectedScheduleSection.value = 'awaiting'

  previousRouteGroup = nextGroup
}

function shouldLoadTodayHistory() {
  const today = manilaToday()

  return (
    page.value === 1 &&
    (!date.value || date.value === today) &&
    ['all', 'today', 'last_7_days', 'this_month'].includes(timeFilter.value)
  )
}

async function refresh(resetPage = false) {
  if (resetPage) {
    page.value = 1
    releasePage.value = 1
  }
  loading.value = true
  error.value = ''
  try {
    if (isReadyForReleaseView.value) {
      const releaseResult = await api.registrarRequests({
        status: 'ready_for_release',
        search: search.value || undefined,
        time_filter: timeFilter.value,
        request_id: requestIdFilter.value || undefined,
        page: releasePage.value,
      })
      readyRequests.value = releaseResult.data
      releasePage.value = releaseResult.current_page || releasePage.value
      releaseLastPage.value = releaseResult.last_page
      releaseTotal.value = releaseResult.total

      return
    }

    const [appointmentResult, todayHistoryResult] = await Promise.all([
      api.registrarAppointments({
        search: search.value || undefined,
        date: date.value || undefined,
        time_filter: timeFilter.value,
        request_id: requestIdFilter.value || undefined,
        appointment_id: appointmentIdFilter.value || undefined,
        page: page.value,
      }),
      shouldLoadTodayHistory()
        ? api.registrarHistory({
            section: 'appointments',
            search: search.value || undefined,
            time_filter: 'today',
            request_id: requestIdFilter.value || undefined,
            appointment_id: appointmentIdFilter.value || undefined,
            appointment_page: 1,
          })
        : Promise.resolve(null),
    ])

    const historicalToday = (todayHistoryResult?.appointments?.data || []).filter(
      (appointment) => appointmentDateKey(appointment) === manilaToday(),
    )
    const appointmentsById = new Map(
      [...appointmentResult.data, ...historicalToday].map((appointment) => [appointment.id, appointment]),
    )

    appointments.value = [...appointmentsById.values()]
    page.value = appointmentResult.current_page || page.value
    lastPage.value = appointmentResult.last_page
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

async function reopenAppointmentFromRoute() {
  if (queryValue(route.query.open) !== 'completion' || detailsOpen.value) return

  const appointmentId = positiveId(route.query.appointment_id)
  const appointment = appointments.value.find(({ id }) => id === appointmentId)
  if (appointment) await openAppointmentDetails(appointment, { completion: true })
}

async function applyFilters() {
  requestIdFilter.value = null
  appointmentIdFilter.value = null
  focusedAppointmentId.value = null
  await refresh(true)
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

function goToRequestFromAppointment() {
  const requestId = detailsRequest.value?.id || detailsAppointment.value?.document_request_id
  const appointmentId = detailAppointmentRecord.value?.id
  if (!requestId || !appointmentId) return

  router.push({
    name: 'registrar-document-requests',
    query: appointmentDocumentRequestQuery({ requestId, appointmentId }),
  })
}

async function openAppointmentDetails(appointment, { confirmed = false, completion = false } = {}) {
  detailsTrigger = document.activeElement
  detailsAppointment.value = appointment
  detailsRequest.value = null
  detailsConfirmed.value = confirmed
  detailsEntryPoint.value = completion ? 'appointment-complete' : confirmed ? 'confirmation' : 'view'
  detailsError.value = ''
  detailsLoading.value = true
  detailsOpen.value = true
  await nextTick()
  detailsDialog.value?.focus()

  try {
    detailsRequest.value = await api.registrarRequest(
      appointment.document_request_id || appointment.document_request?.id,
    )
  } catch (err) {
    detailsError.value = appointmentDetailsError(err)
  } finally {
    detailsLoading.value = false
  }
}

async function openRequestReleaseDetails(request) {
  detailsTrigger = document.activeElement
  detailsAppointment.value = null
  detailsRequest.value = request
  detailsConfirmed.value = false
  detailsEntryPoint.value = 'ready-release'
  detailsError.value = ''
  detailsLoading.value = true
  detailsOpen.value = true
  await nextTick()
  detailsDialog.value?.focus()

  try {
    const detailedRequest = await api.registrarRequest(request.id)
    const relatedAppointments = [...(detailedRequest.appointments || [])].sort((left, right) => right.id - left.id)
    const relatedAppointment =
      relatedAppointments.find(({ status }) => status === 'confirmed') ||
      relatedAppointments.find(({ status }) => status === 'pending') ||
      relatedAppointments[0] ||
      null

    detailsRequest.value = detailedRequest
    detailsAppointment.value = relatedAppointment
      ? {
          ...relatedAppointment,
          student: detailedRequest.student,
          document_request: {
            id: detailedRequest.id,
            request_reference: detailedRequest.request_reference,
            status: detailedRequest.status,
            document_type: detailedRequest.document_type,
          },
        }
      : null
  } catch (err) {
    detailsError.value = appointmentDetailsError(err)
  } finally {
    detailsLoading.value = false
  }
}

function closeAppointmentDetails() {
  const confirmedAppointment = detailsEntryPoint.value === 'confirmation' ? detailsAppointment.value : null
  detailsOpen.value = false
  detailsLoading.value = false
  detailsError.value = ''
  detailsRequest.value = null
  detailsConfirmed.value = false
  detailsEntryPoint.value = 'view'
  if (confirmedAppointment) {
    if (appointmentDateKey(confirmedAppointment) > manilaToday()) selectedScheduleSection.value = 'upcoming'
    nextTick(() => highlightAppointment(confirmedAppointment.id))
  }
  nextTick(() => detailsTrigger?.focus?.())
}

function applyLocalAppointmentUpdate(updated) {
  const current = appointments.value.find(({ id }) => id === updated.id)
  const merged = current ? { ...current, ...updated } : updated
  const remainsInSchedule =
    activeStatuses.includes(merged.status) ||
    (appointmentDateKey(merged) === manilaToday() && terminalAppointmentStatuses.includes(merged.status))

  appointments.value = remainsInSchedule
    ? appointments.value.map((item) => (item.id === merged.id ? merged : item))
    : appointments.value.filter((item) => item.id !== merged.id)
}

function openCancelAppointment() {
  if (!canCancelAppointment.value) return

  cancellationTarget.value = { ...detailAppointmentRecord.value }
  cancellationRequest.value = detailsRequest.value
  detailsOpen.value = false
  cancellationDialogOpen.value = true
}

function closeCancellationDialog() {
  if (cancellingAppointmentId.value) return
  cancellationDialogOpen.value = false
  cancellationTarget.value = null
  cancellationRequest.value = null
  nextTick(() => detailsTrigger?.focus?.())
}

async function confirmAppointmentCancellation({ reason }) {
  if (!cancellationTarget.value) return

  error.value = ''
  message.value = ''
  cancellingAppointmentId.value = cancellationTarget.value.id
  try {
    const updated = await api.updateAppointment(cancellationTarget.value.id, {
      status: 'cancelled',
      remarks: reason,
    })
    applyLocalAppointmentUpdate(updated)
    message.value = 'Appointment cancelled. The document request remains in its current workflow state.'
    recentActivityKey.value += 1
    cancellationDialogOpen.value = false
    cancellationTarget.value = null
    cancellationRequest.value = null
  } catch (err) {
    error.value = requestError(err)
  } finally {
    cancellingAppointmentId.value = null
  }
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

async function completeReleaseWorkflow() {
  const request = detailsRequest.value
  if (!request || !canCompleteRelease.value) return

  error.value = ''
  detailsError.value = ''
  message.value = ''
  releasingId.value = request.id
  try {
    const releasedRequest = await api.updateRequest(request.id, {
      action: 'release',
      appointment_id: detailAppointmentRecord.value?.id || undefined,
      remarks: request.remarks || null,
    })
    const completedAppointment = releasedRequest.appointments?.find(
      ({ id }) => id === detailAppointmentRecord.value?.id,
    )
    if (completedAppointment) applyLocalAppointmentUpdate(completedAppointment)
    readyRequests.value = readyRequests.value.filter((item) => item.id !== request.id)
    releaseTotal.value = Math.max(0, releaseTotal.value - 1)
    message.value = `${request.request_reference || requestReference(request.id)} released${completedAppointment ? ' and its appointment completed' : ''}.`
    recentActivityKey.value += 1
    closeAppointmentDetails()

    if (!readyRequests.value.length && releasePage.value > 1) {
      releasePage.value -= 1
      await refresh()
    }
  } catch (err) {
    detailsError.value = requestError(err)
  } finally {
    releasingId.value = null
  }
}

async function updateStatus(appointment, nextStatus) {
  error.value = ''
  message.value = ''
  updatingAppointmentId.value = appointment.id
  try {
    const updated = await api.updateAppointment(appointment.id, {
      status: nextStatus,
    })
    applyLocalAppointmentUpdate(updated)
    message.value = nextStatus === 'confirmed' ? 'Appointment confirmed.' : 'Appointment updated.'
    recentActivityKey.value += 1
    if (!appointments.value.length && page.value > 1) page.value -= 1
    if (nextStatus === 'confirmed') await openAppointmentDetails(updated, { confirmed: true })
  } catch (err) {
    error.value = requestError(err)
  } finally {
    updatingAppointmentId.value = null
  }
}

watch(
  [
    () => queryValue(route.query.search),
    () => queryValue(route.query.date),
    () => queryValue(route.query.group),
    () => queryValue(route.query.time_filter),
    () => queryValue(route.query.request_id),
    () => queryValue(route.query.appointment_id),
    () => queryValue(route.query.focus),
    () => queryValue(route.query.page),
    () => queryValue(route.query.release_page),
    () => queryValue(route.query.open),
  ],
  async () => {
    applyRouteQuery(route.query)
    await refresh()
    await revealFocusedAppointment()
    await reopenAppointmentFromRoute()
  },
  { flush: 'post', immediate: true },
)

watch(availabilityOpen, (isOpen) => (isOpen ? prepareAvailabilitySettings() : resetAvailabilitySettings()), {
  flush: 'post',
})

onBeforeUnmount(() => {
  window.clearTimeout(appointmentHighlightTimer)
  hideAvailabilitySettings()
  detailsOpen.value = false
  recentActivityOpen.value = false
})
</script>

<template>
  <section class="page-header registrar-appointments-header">
    <p class="page-kicker">Registrar Staff</p>
    <div class="appointments-title-row">
      <h1 class="page-title">{{ isReadyForReleaseView ? 'Ready to Release' : 'Appointments' }}</h1>
    </div>
    <p class="page-description">
      {{
        isReadyForReleaseView
          ? 'Release prepared documents through the dedicated handoff queue.'
          : 'Review pending requests and scan today’s and upcoming appointment schedule.'
      }}
    </p>
  </section>

  <p v-if="message" class="notice success">{{ message }}</p>
  <p v-if="error" class="notice error">{{ error }}</p>

  <section
    v-if="isReadyForReleaseView"
    class="dr-panel release-queue-panel"
    aria-labelledby="release-queue-title"
  >
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
          @click="openRequestReleaseDetails(request)"
        >
          Release Document
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

  <RequestWorkflowReasonModal
    :open="cancellationDialogOpen"
    title="Cancel Appointment"
    description="Provide the reason for cancelling this appointment. The document request will remain in its current workflow state."
    confirm-label="Cancel Appointment"
    :reason-options="CANCELLATION_REASONS"
    :request="cancellationRequest"
    :busy="Boolean(cancellingAppointmentId)"
    @close="closeCancellationDialog"
    @confirm="confirmAppointmentCancellation"
  />

  <nav v-if="!isReadyForReleaseView" class="appointment-view-nav" aria-label="Appointment page view">
    <button type="button" class="active" aria-current="page" @click="activateScheduleView">Schedule View</button>
  </nav>

  <div v-if="!isReadyForReleaseView" ref="scheduleViewRef" class="schedule-view-container">
    <div v-if="loading && !appointments.length" class="appointment-workspace-skeleton" aria-label="Loading appointment schedule" aria-busy="true">
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
      <section class="appointment-today-skeleton">
        <span class="appointment-heading-skeleton skeleton-shimmer"></span>
        <span v-for="index in 3" :key="index" class="appointment-row-skeleton skeleton-shimmer"></span>
      </section>
    </div>

    <template v-else>
      <section class="dr-panel appointment-filter-panel" aria-labelledby="appointment-filters-title">
        <div class="appointment-filter-heading">
          <div>
            <p class="record-eyebrow">Schedule view</p>
            <h2 id="appointment-filters-title">Appointment Schedule</h2>
          </div>
        </div>
        <form class="toolbar appointment-search-toolbar" @submit.prevent="applyFilters">
          <input v-model="search" aria-label="Search appointments" placeholder="Reference, student, or document" />
          <input v-model="date" aria-label="Appointment date" type="date" />
          <select v-model="timeFilter" aria-label="Appointment time period">
            <option v-for="option in TIME_FILTERS" :key="option.value" :value="option.value">{{ option.label }}</option>
          </select>
          <button :disabled="loading">{{ loading ? 'Loading…' : 'Apply filters' }}</button>
        </form>
      </section>

      <section class="registrar-workspace-grid" aria-label="Appointment schedule workspace">
        <RegistrarWorkspaceSelector
          :model-value="selectedScheduleSection"
          :items="appointmentSelectors"
          aria-label="Select appointment section"
          @update:model-value="selectScheduleSection"
        />

        <section class="dr-panel appointment-main-panel" aria-live="polite">
          <header class="appointment-main-heading">
            <div>
              <p class="record-eyebrow">Selected schedule</p>
              <h2>{{ selectedAppointmentSection.title }}</h2>
              <p>{{ selectedAppointmentSection.description }}</p>
            </div>
            <strong class="appointment-section-count">{{ selectedAppointmentSection.items.length }}</strong>
          </header>

          <Transition name="appointment-workspace-swap" mode="out-in">
            <div :key="selectedAppointmentSection.key" class="appointment-main-table">
              <p v-if="!selectedAppointmentSection.items.length" class="appointment-section-empty">{{ selectedAppointmentEmptyMessage }}</p>
              <article
                v-for="appointment in selectedAppointmentSection.items"
                :id="`appointment-${appointment.id}`"
                :key="appointment.id"
                class="compact-appointment-row"
                :class="[`appointment-status-${appointment.status}`, { 'focused-record': focusedAppointmentId === appointment.id }]"
                role="button"
                tabindex="0"
                :aria-label="`View appointment details for ${studentName(appointment.student)}`"
                @click="openAppointmentDetails(appointment)"
                @keydown.enter.self.prevent="openAppointmentDetails(appointment)"
                @keydown.space.self.prevent="openAppointmentDetails(appointment)"
              >
                <time class="appointment-row-time" :datetime="appointmentTimestamp(appointment)" :title="appointmentDateTime(appointment.appointment_date, appointment.appointment_time)">
                  <strong>{{ formatAppointmentTime(appointment.appointment_time) }}</strong>
                  <small>{{ formatDate(appointment.appointment_date) }}</small>
                </time>
                <span class="appointment-row-student"><strong>{{ studentName(appointment.student) }}</strong><small>{{ appointment.student.student_number }}</small></span>
                <span class="appointment-row-request"><strong>{{ appointment.document_request.document_type.document_name }}</strong><small>{{ referenceFor(appointment) }}<template v-if="appointment.remarks"> · {{ appointment.remarks }}</template></small></span>
                <span class="badge appointment-row-status" :class="appointment.status">{{ formatStatus(appointment.status) }}</span>
                <span class="actions appointment-row-actions">
                  <button
                    v-if="appointment.status === 'pending'"
                    type="button"
                    class="confirm-appointment-button"
                    :disabled="updatingAppointmentId === appointment.id"
                    @click.stop="updateStatus(appointment, 'confirmed')"
                  >{{ updatingAppointmentId === appointment.id ? 'Confirming…' : 'Confirm' }}</button>
                  <button
                    v-if="appointment.status === 'confirmed'"
                    type="button"
                    class="completion-appointment-button"
                    :disabled="Boolean(releasingId)"
                    @click.stop="openAppointmentDetails(appointment, { completion: true })"
                  >Complete</button>
                </span>
              </article>
            </div>
          </Transition>

          <footer class="appointment-main-footer">
            <PaginationControls
              :current-page="page"
              :last-page="lastPage"
              :busy="loading"
              aria-label="Registrar appointment pages"
              @page-change="changePage"
            />
            <button type="button" class="secondary appointment-recent-button" @click="openRecentActivity">Recent Activity</button>
          </footer>
        </section>
      </section>

      <section class="dr-panel appointment-today-panel" aria-labelledby="today-appointments-title">
        <header class="appointment-main-heading">
          <div><p class="record-eyebrow">Today</p><h2 id="today-appointments-title">Today's Appointments</h2><p>Confirmed and finalized appointments scheduled for today.</p></div>
          <strong class="appointment-section-count">{{ todaysAppointments.length }}</strong>
        </header>
        <p v-if="!todaysAppointments.length" class="appointment-section-empty">No appointments scheduled for today.</p>
        <article
          v-for="appointment in todaysAppointments"
          :id="`appointment-${appointment.id}`"
          :key="appointment.id"
          class="compact-appointment-row"
          :class="[`appointment-status-${appointment.status}`, { 'focused-record': focusedAppointmentId === appointment.id }]"
          role="button"
          tabindex="0"
          :aria-label="`View appointment details for ${studentName(appointment.student)}`"
          @click="openAppointmentDetails(appointment)"
          @keydown.enter.self.prevent="openAppointmentDetails(appointment)"
          @keydown.space.self.prevent="openAppointmentDetails(appointment)"
        >
          <time class="appointment-row-time" :datetime="appointmentTimestamp(appointment)" :title="appointmentDateTime(appointment.appointment_date, appointment.appointment_time)">
            <strong>{{ formatAppointmentTime(appointment.appointment_time) }}</strong><small>{{ formatDate(appointment.appointment_date) }}</small>
          </time>
          <span class="appointment-row-student"><strong>{{ studentName(appointment.student) }}</strong><small>{{ appointment.student.student_number }}</small></span>
          <span class="appointment-row-request"><strong>{{ appointment.document_request.document_type.document_name }}</strong><small>{{ referenceFor(appointment) }}</small></span>
          <span class="badge appointment-row-status" :class="appointment.status">{{ formatStatus(appointment.status) }}</span>
          <span v-if="appointment.status === 'confirmed'" class="actions appointment-row-actions">
            <button type="button" class="completion-appointment-button" :disabled="Boolean(releasingId)" @click.stop="openAppointmentDetails(appointment, { completion: true })">Complete</button>
          </span>
        </article>
      </section>
    </template>
  </div>

  <Teleport to="body">
    <Transition name="student-modal" appear>
      <div v-if="recentActivityOpen" class="appointment-details-backdrop" role="presentation" @mousedown.self="closeRecentActivity" @keydown.esc="closeRecentActivity">
        <section ref="recentActivityDialog" class="appointment-details-modal appointment-recent-modal" role="dialog" aria-modal="true" aria-labelledby="appointment-recent-title" tabindex="-1">
          <header class="appointment-details-header student-modal-header">
            <div><p class="page-kicker">Registrar appointments</p><h2 id="appointment-recent-title">Recent Activity</h2><p>Latest document request and appointment updates.</p></div>
            <button type="button" class="student-modal-close" aria-label="Close recent activity" @click="closeRecentActivity">×</button>
          </header>
          <div class="appointment-details-body appointment-recent-modal-body">
            <RegistrarRecentActivity :key="recentActivityKey" read-only />
          </div>
          <footer class="appointment-details-footer"><button type="button" class="appointment-close-action" @click="closeRecentActivity">Close</button></footer>
        </section>
      </div>
    </Transition>
  </Teleport>

  <Teleport to="body">
    <div
      v-if="detailsOpen"
      class="appointment-details-backdrop"
      role="presentation"
      @mousedown.self="closeAppointmentDetails"
      @keydown.esc="closeAppointmentDetails"
    >
      <section
        ref="detailsDialog"
        class="appointment-details-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="appointment-details-title"
        tabindex="-1"
      >
        <header class="appointment-details-header">
          <div>
            <p class="page-kicker">Registrar appointment</p>
            <h2 id="appointment-details-title">{{ detailTitle }}</h2>
            <p>
              {{
                detailsAreReadOnly
                  ? 'This finalized appointment is read-only.'
                  : 'Review the appointment and document request before taking action.'
              }}
            </p>
          </div>
          <span v-if="detailsConfirmed" class="appointment-confirmed-mark" aria-hidden="true">✓</span>
        </header>

        <div class="appointment-details-body">
          <p v-if="detailsLoading" class="appointment-details-state">Loading appointment details…</p>
          <p v-if="detailsError" class="notice error" role="alert">{{ detailsError }}</p>

          <template v-if="detailsRequest || detailsAppointment">
            <section class="appointment-detail-section" aria-labelledby="appointment-student-title">
              <h3 id="appointment-student-title">Student</h3>
              <dl class="appointment-detail-grid">
                <div>
                  <dt>Name</dt>
                  <dd>{{ studentName(detailStudent) || 'Not available' }}</dd>
                </div>
                <div>
                  <dt>Student number</dt>
                  <dd>{{ detailStudent?.student_number || 'Not available' }}</dd>
                </div>
                <div v-if="detailStudent?.course">
                  <dt>Course</dt>
                  <dd>{{ detailStudent.course.course_code || detailStudent.course.course_name }}</dd>
                </div>
                <div v-if="detailStudent?.year_level">
                  <dt>Year level</dt>
                  <dd>Year {{ detailStudent.year_level }}</dd>
                </div>
              </dl>
            </section>

            <section class="appointment-detail-section" aria-labelledby="appointment-request-title">
              <h3 id="appointment-request-title">Document Request</h3>
              <dl class="appointment-detail-grid">
                <div>
                  <dt>Request reference</dt>
                  <dd>{{ detailsRequest?.request_reference || referenceFor(detailsAppointment) }}</dd>
                </div>
                <div>
                  <dt>Requested document</dt>
                  <dd>{{ detailDocumentName }}</dd>
                </div>
                <div v-if="detailsRequest?.request_date">
                  <dt>Request date</dt>
                  <dd>{{ formatDate(detailsRequest.request_date) }}</dd>
                </div>
                <div v-if="detailsRequest?.status">
                  <dt>Request status</dt>
                  <dd>
                    <span class="badge" :class="detailsRequest.status">
                      {{ formatStatus(detailsRequest.status) }}
                    </span>
                  </dd>
                </div>
                <div v-if="detailsRequest?.total_fee !== undefined">
                  <dt>Fee</dt>
                  <dd>₱{{ formatMoney(detailsRequest.total_fee) }}</dd>
                </div>
                <div v-if="detailsRequest">
                  <dt>Physical record location</dt>
                  <dd>{{ physicalRecordLabel(detailStudent) }}</dd>
                </div>
              </dl>
            </section>

            <section
              v-if="detailAppointmentRecord"
              class="appointment-detail-section"
              aria-labelledby="appointment-schedule-title"
            >
              <h3 id="appointment-schedule-title">Appointment</h3>
              <dl class="appointment-detail-grid">
                <div>
                  <dt>Date</dt>
                  <dd>{{ formatDate(detailAppointmentRecord?.appointment_date) }}</dd>
                </div>
                <div>
                  <dt>Time</dt>
                  <dd>{{ formatAppointmentTime(detailAppointmentRecord?.appointment_time) }}</dd>
                </div>
                <div>
                  <dt>Status</dt>
                  <dd>
                    <span class="badge" :class="detailAppointmentRecord?.status">
                      {{ formatStatus(detailAppointmentRecord?.status) }}
                    </span>
                  </dd>
                </div>
              </dl>
            </section>

            <section v-else class="appointment-detail-section" aria-labelledby="appointment-schedule-title">
              <h3 id="appointment-schedule-title">Appointment</h3>
              <p class="appointment-details-state">No appointment is linked to this document request.</p>
            </section>

            <section
              v-if="detailAppointmentRecord?.remarks || detailsRequest?.purpose || detailsRequest?.remarks"
              class="appointment-detail-section appointment-detail-notes"
              aria-labelledby="appointment-notes-title"
            >
              <h3 id="appointment-notes-title">Notes / Purpose</h3>
              <p>{{ detailAppointmentRecord?.remarks || detailsRequest?.purpose || detailsRequest?.remarks }}</p>
            </section>

            <p
              v-if="releaseRequirementMessage"
              class="appointment-release-requirement"
              :class="`is-${releaseMessageTone}`"
              role="status"
            >
              {{ releaseRequirementMessage }}
            </p>
          </template>
        </div>

        <footer class="appointment-details-footer">
          <button
            v-if="showGoToRequestAction"
            type="button"
            class="appointment-request-action"
            @click="goToRequestFromAppointment"
          >
            Go to Request
          </button>
          <button
            v-if="showCompletionAction"
            type="button"
            class="appointment-release-action"
            :disabled="!canCompleteRelease || Boolean(releasingId)"
            @click="completeReleaseWorkflow"
          >
            {{ releasingId ? 'Completing…' : completionActionLabel }}
          </button>
          <button
            v-if="canCancelAppointment"
            type="button"
            class="appointment-cancel-action"
            :disabled="Boolean(releasingId)"
            @click="openCancelAppointment"
          >
            Cancel Appointment
          </button>
          <button type="button" class="appointment-close-action" :disabled="Boolean(releasingId)" @click="closeAppointmentDetails">
            Close
          </button>
        </footer>
      </section>
    </div>
  </Teleport>

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
