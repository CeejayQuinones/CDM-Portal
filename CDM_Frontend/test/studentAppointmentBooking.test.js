import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const viewSource = readFileSync(
  new URL('../src/modules/document-request/StudentDocumentRequestView.vue', import.meta.url),
  'utf8',
)

test('selecting a date clears stale time state and loads slots once through the watcher', () => {
  assert.match(viewSource, /watch\(appointmentDate, async \(selectedDate\) =>/)
  assert.match(
    viewSource,
    /watch\(appointmentDate[\s\S]*?appointmentTime\.value = ''[\s\S]*?slots\.value = \[\][\s\S]*?await loadSlots\(selectedDate\)/,
  )

  const selectDateStart = viewSource.indexOf('function selectDate')
  const selectDateEnd = viewSource.indexOf('async function loadAvailability', selectDateStart)
  assert.doesNotMatch(viewSource.slice(selectDateStart, selectDateEnd), /loadSlots/)
})

test('slot response uses date and slots and stale responses cannot replace the current date', () => {
  assert.match(viewSource, /const response = await api\.slots\(date\)/)
  assert.match(viewSource, /response\.date === date && Array\.isArray\(response\.slots\)/)
  assert.match(viewSource, /requestSequence !== slotRequestSequence \|\| appointmentDate\.value !== date/)
})

test('available-time UI exposes loading, selection, unavailable, and empty states', () => {
  assert.match(viewSource, /Loading available times\.\.\./)
  assert.match(viewSource, /v-for="slot in slots"/)
  assert.match(viewSource, /:disabled="!slot\.available"/)
  assert.match(viewSource, /slot\.reason \|\| 'Unavailable'/)
  assert.match(viewSource, /slotDateReason \|\| 'No appointment times are available for this date\.'/)
  assert.match(viewSource, /:aria-pressed="appointmentTime === slot\.time"/)
  assert.match(viewSource, /No appointment times are available for this date\./)
  assert.match(viewSource, /Select an available appointment time\./)
})

test('booking is disabled until a current available time is selected and duplicate submission is blocked', () => {
  assert.match(viewSource, /availableSlots\.value\.some\(\(slot\) => slot\.time === appointmentTime\.value\)/)
  assert.match(viewSource, /!bookingInProgress\.value/)
  assert.match(viewSource, /:disabled="!canBook"/)
})

test('student cancellation is limited to active appointments and uses the dedicated endpoint', () => {
  assert.match(viewSource, /const activeAppointmentStatuses = \['pending', 'confirmed'\]/)
  assert.match(viewSource, /\['pending', 'confirmed'\]\.includes\(appointment\?\.status\)/)
  assert.match(viewSource, /v-if="appointmentCanBeCancelled\(selectedAppointment\)"/)
  assert.match(viewSource, /await api\.cancelAppointment\(appointmentId, \{ reason \}\)/)
  assert.match(viewSource, /Enter a reason for cancelling this appointment\./)
  assert.match(viewSource, /Keep Appointment/)
})

test('successful cancellation refreshes the shared request data without reloading the page', () => {
  assert.match(viewSource, /await loadRequests\(\)/)
  assert.doesNotMatch(viewSource, /api\.appointmentOverview/)
  assert.doesNotMatch(viewSource, /window\.location\.reload/)
})

test('unified page exposes request and appointment summary cards with transitioned details', () => {
  assert.match(viewSource, /class="student-request-workspace"/)
  assert.match(viewSource, /Document Request<\/small>/)
  assert.match(viewSource, /Appointment<\/small>/)
  assert.match(viewSource, /<Transition name="student-detail-swap" mode="out-in">/)
  assert.match(viewSource, /class="student-history-panel"/)
})

test('booking and document type data are lazy loaded only when their actions open', () => {
  assert.match(viewSource, /async function openRequestForm\(\)[\s\S]*?api\.documentTypes\(\)/)
  assert.match(viewSource, /async function openBooking\(documentRequest\)[\s\S]*?await loadAvailability\(\)/)
  assert.match(viewSource, /async function refresh\(\)[\s\S]*?await loadRequests\(\)/)
})

test('Book Appointment uses a modal and no longer expands the main detail panel', () => {
  assert.match(viewSource, /<Transition name="student-modal" appear>[\s\S]*?v-if="bookingRequest"/)
  assert.match(viewSource, /class="appointment-details-modal student-booking-modal"/)
  assert.match(viewSource, /Selected date:/)
  assert.match(viewSource, /Selected time:/)
  assert.match(viewSource, /@keydown\.esc="closeBooking"/)
  assert.doesNotMatch(viewSource, /class="student-booking-panel"/)
})

test('New Request uses the shared modal pattern and no longer expands the detail panel', () => {
  assert.match(viewSource, /v-if="showRequestForm"[\s\S]*?class="appointment-details-modal student-request-modal"/)
  assert.match(viewSource, /@keydown\.esc="closeRequestForm"/)
  assert.match(viewSource, /@mousedown\.self="closeRequestForm"/)
  assert.match(viewSource, /requestSubmitting \? 'Submitting…' : 'Submit Request'/)
  assert.doesNotMatch(viewSource, /class="student-inline-form"/)
})

test('newly submitted request refreshes and becomes the selected request without a page reload', () => {
  assert.match(
    viewSource,
    /const created = await api\.createRequest[\s\S]*?await loadRequests\(\)[\s\S]*?selectedRequestId\.value = created\.id[\s\S]*?selectedPanel\.value = 'request'/,
  )
  assert.doesNotMatch(viewSource, /window\.location\.reload/)
})

test('pending request cancellation uses a reasoned student modal and refreshes shared state', () => {
  assert.match(viewSource, /const canCancelSelectedRequest = computed\(\(\) => selectedRequest\.value\?\.status === 'pending'\)/)
  assert.match(viewSource, /v-if="canCancelSelectedRequest"/)
  assert.match(viewSource, /Cancel Document Request/)
  assert.match(viewSource, /await api\.cancelRequest\(requestId, \{ reason \}\)/)
  assert.match(viewSource, /Enter a reason for cancelling this document request\./)
  assert.match(viewSource, /await loadRequests\(\)/)
})
