import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const viewSource = readFileSync(
  new URL('../src/modules/document-request/RegistrarAppointmentsView.vue', import.meta.url),
  'utf8',
)
const requestViewSource = readFileSync(
  new URL('../src/modules/document-request/RegistrarDocumentRequestView.vue', import.meta.url),
  'utf8',
)
const styles = readFileSync(
  new URL('../src/modules/document-request/documentRequest.css', import.meta.url),
  'utf8',
)

test('appointments use a two-selector workspace instead of schedule controls and accordions', () => {
  assert.match(viewSource, /const selectedScheduleSection = ref\('awaiting'\)/)
  assert.match(viewSource, /class="registrar-workspace-grid"/)
  assert.match(viewSource, /<RegistrarWorkspaceSelector[\s\S]*?:items="appointmentSelectors"/)
  assert.match(viewSource, /<Transition name="appointment-workspace-swap" mode="out-in">/)
  assert.doesNotMatch(viewSource, /schedule-section-controls|appointment-section-toggle|appointment-curtain/)
})

test("today's appointments stay full width below the switched main table", () => {
  const workspacePosition = viewSource.indexOf('class="registrar-workspace-grid"')
  const todayPosition = viewSource.indexOf('class="dr-panel appointment-today-panel"')

  assert.ok(workspacePosition >= 0 && todayPosition > workspacePosition)
  assert.match(viewSource, /v-for="appointment in todaysAppointments"/)
  assert.match(viewSource, /appointment\.status === 'confirmed'[\s\S]*?>Complete</)
})

test('recent activity is lazy-mounted in a read-only modal', () => {
  assert.match(viewSource, /@click="openRecentActivity"[\s\S]*?>Recent Activity</)
  assert.match(viewSource, /v-if="recentActivityOpen"/)
  assert.match(viewSource, /<RegistrarRecentActivity :key="recentActivityKey" read-only \/>/)
  assert.doesNotMatch(viewSource, /<RegistrarRecentActivity :key="recentActivityKey" \/>/)
})

test('new appointment workspace includes skeletons and restrained transitions', () => {
  assert.match(viewSource, /class="appointment-workspace-skeleton"/)
  assert.match(viewSource, /v-for="index in 6"[\s\S]*?appointment-row-skeleton/)
  assert.match(styles, /\.appointment-workspace-swap-enter-active[\s\S]*?transform 240ms ease/)
  assert.match(styles, /\.registrar-workspace-selector-card[\s\S]*?transform 200ms ease/)
  assert.match(styles, /@media \(prefers-reduced-motion: reduce\)[\s\S]*?\.appointment-workspace-swap-enter-active/)
})

test('confirmed appointments receive local focused-row feedback after details close', () => {
  assert.match(viewSource, /detailsEntryPoint\.value === 'confirmation'/)
  assert.match(viewSource, /nextTick\(\(\) => highlightAppointment\(confirmedAppointment\.id\)\)/)
  assert.match(styles, /\.compact-appointment-row\.focused-record[\s\S]*?appointment-row-focus-fade 3s ease-in-out/)
})

test('Ready to Release opens details without changing workflow state', () => {
  assert.match(viewSource, /@click="openRequestReleaseDetails\(request\)"[\s\S]*?Release Document/)

  const openDetailsStart = viewSource.indexOf('async function openRequestReleaseDetails')
  const openDetailsEnd = viewSource.indexOf('function closeAppointmentDetails', openDetailsStart)
  const openDetailsSource = viewSource.slice(openDetailsStart, openDetailsEnd)

  assert.match(openDetailsSource, /detailsEntryPoint\.value = 'ready-release'/)
  assert.match(openDetailsSource, /detailsOpen\.value = true/)
  assert.doesNotMatch(openDetailsSource, /api\.update(?:Request|Appointment)/)
})

test('release details expose appointment data and Complete Appointment confirmation', () => {
  for (const label of [
    'Student number',
    'Request reference',
    'Requested document',
    'Request status',
    'Date',
    'Time',
    'Status',
    'Physical record location',
    'Fee',
    'Notes / Purpose',
  ]) {
    assert.ok(viewSource.includes(label), `Expected release details to include ${label}`)
  }

  assert.match(
    viewSource,
    /detailsEntryPoint\.value === 'ready-release' \? 'Complete Appointment' : 'Release Document'/,
  )
  assert.match(viewSource, /:disabled="!canCompleteRelease \|\| Boolean\(releasingId\)"/)
})

test('confirmed schedule appointments use Complete and the shared release action', () => {
  assert.match(
    viewSource,
    /v-if="appointment\.status === 'confirmed'"[\s\S]*?@click\.stop="openAppointmentDetails\(appointment, \{ completion: true \}\)"[\s\S]*?>\s*Complete\s*</,
  )
  assert.equal((viewSource.match(/action: 'release'/g) || []).length, 1)
  assert.match(viewSource, /async function completeReleaseWorkflow\(\)[\s\S]*?api\.updateRequest/)
})

test('appointment completion actions follow the related request status', () => {
  assert.match(
    viewSource,
    /\['pending', 'processing'\]\.includes\(detailsRequest\.value\?\.status\)/,
  )
  assert.match(viewSource, /detailsRequest\.value\?\.status === 'ready_for_release'/)
  assert.match(viewSource, /This document request is still awaiting approval\./)
  assert.match(viewSource, /This document is still being prepared\./)
  assert.match(viewSource, /This document has already been released\./)
  assert.match(viewSource, /v-if="showGoToRequestAction"[\s\S]*?>\s*Go to Request\s*</)
  assert.match(viewSource, /v-if="showCompletionAction"[\s\S]*?completionActionLabel/)
})

test('Go to Request preserves request and appointment context and return reopens completion details', () => {
  assert.match(
    viewSource,
    /appointmentDocumentRequestQuery\(\{ requestId, appointmentId \}\)/,
  )
  assert.match(viewSource, /queryValue\(route\.query\.open\) !== 'completion'/)
  assert.match(viewSource, /openAppointmentDetails\(appointment, \{ completion: true \}\)/)
  assert.match(requestViewSource, /v-if="appointmentContext"[\s\S]*?returnToAppointment/)
  assert.match(requestViewSource, /await revealFocusedRequest\(\)/)
})
