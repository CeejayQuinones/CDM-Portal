import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import {
  consumeDocumentRequestFocus,
  rememberDocumentRequestFocus,
} from '../src/modules/document-request/documentRequestFocus.js'

const activeView = readFileSync(
  new URL('../src/modules/document-request/RegistrarDocumentRequestView.vue', import.meta.url),
  'utf8',
)
const appointmentsView = readFileSync(
  new URL('../src/modules/document-request/RegistrarAppointmentsView.vue', import.meta.url),
  'utf8',
)
const queueComponent = readFileSync(
  new URL('../src/modules/document-request/RegistrarRequestQueue.vue', import.meta.url),
  'utf8',
)
const physicalRecordsView = readFileSync(
  new URL('../src/modules/student-management/PhysicalRecordsView.vue', import.meta.url),
  'utf8',
)
const styles = readFileSync(new URL('../src/modules/document-request/documentRequest.css', import.meta.url), 'utf8')

test('active request queues use one selected workspace before recent activity', () => {
  assert.match(activeView, /const selectedQueueKey = ref\('pending'\)/)
  assert.match(activeView, /class="registrar-workspace-grid"/)
  assert.match(activeView, /<RegistrarWorkspaceSelector[\s\S]*?v-model="selectedQueueKey"/)
  assert.match(activeView, /<Transition name="appointment-workspace-swap" mode="out-in">/)
  assert.equal((activeView.match(/<RegistrarRequestQueue/g) || []).length, 1)
  assert.ok(activeView.indexOf('class="registrar-workspace-grid"') < activeView.indexOf('<RegistrarRecentActivity'))
  assert.match(activeView, /@select="selectRequest"/)
  assert.match(queueComponent, /@click="\$emit\('select', item\)"/)
})

test('ready for release is a processing row action and not a processing modal action', () => {
  assert.match(activeView, /actionLabel: 'Ready for Release'/)
  assert.match(activeView, /:action-label="selectedQueue\.actionLabel"/)
  assert.match(activeView, /@action="markReadyForRelease"/)

  const modalFooter = activeView.slice(
    activeView.indexOf('<footer class="request-detail-footer">'),
    activeView.indexOf('</footer>', activeView.indexOf('<footer class="request-detail-footer">')),
  )
  assert.doesNotMatch(modalFooter, /ready_for_release|Ready for Release/)
})

test('queues are height constrained with internal vertical scrolling', () => {
  assert.match(styles, /\.work-queue-panel\s*\{[^}]*display:\s*flex[^}]*flex-direction:\s*column[^}]*height:\s*540px/s)
  assert.match(styles, /\.work-queue-list\s*\{[^}]*flex:\s*1 1 auto[^}]*min-height:\s*0[^}]*overflow-y:\s*auto/s)
  assert.match(styles, /\.work-queue-footer\s*\{[^}]*flex:\s*0 0 auto/s)
  assert.match(styles, /@media \(max-width: 900px\)[^{]*\{[\s\S]*?\.registrar-workspace-grid[\s\S]*?grid-template-columns:\s*1fr/s)
})

test('pending approval switches to Processing and preserves the focused request feedback', () => {
  assert.match(activeView, /if \(previousStatus === 'pending'\) selectedQueueKey\.value = 'processing'/)
  assert.match(activeView, /highlightRequest\(updated\.id, previousStatus !== updated\.status\)/)
  assert.match(activeView, /emptyMessage: 'No pending document requests\.'/)
  assert.match(activeView, /emptyMessage: 'No document requests are currently being processed\.'/)
})

test('document request workspace and recent activity load with independent skeletons', () => {
  assert.match(activeView, /class="appointment-workspace-skeleton request-workspace-skeleton"/)
  assert.match(activeView, /v-for="index in 6"[\s\S]*?appointment-row-skeleton/)
  assert.match(activeView, /class="request-recent-activity-section"/)
  assert.match(styles, /\.recent-activity-skeleton[\s\S]*?height: 58px/)
})

test('release workflow exposes a reasoned return-to-processing action', () => {
  assert.match(appointmentsView, /Return to Processing/)
  assert.match(appointmentsView, /action:\s*'return_to_processing'/)
  assert.match(appointmentsView, /:reason-options="RETURN_REASONS"/)
})

test('returned requests preserve one-time focus for the active processing queue', () => {
  rememberDocumentRequestFocus(722)
  assert.equal(consumeDocumentRequestFocus(), 722)
  assert.equal(consumeDocumentRequestFocus(), null)
})

test('request focus uses a temporary branded highlight without refetching queues', () => {
  assert.match(activeView, /const HIGHLIGHT_DURATION_MS = 3_000/)
  assert.match(activeView, /highlightRequest\(updated\.id, previousStatus !== updated\.status\)/)
  assert.match(activeView, /if \(highlightedRequestId\.value === id\)[\s\S]*?highlightedRequestId\.value = null[\s\S]*?nextTick\(applyHighlight\)/)
  assert.match(queueComponent, /'focused-request': highlightedId === item\.id/)
  assert.match(styles, /\.queue-item\.focused-request[\s\S]*?request-focus-fade 3s ease-in-out both/)
  assert.match(styles, /background-color 220ms ease/)
})

test('queue movement merges complete request data before inserting a processing row', () => {
  assert.match(activeView, /selected\.value = mergeDocumentRequestRow\(existingQueueItem, item, detail\)/)
  assert.match(activeView, /const updated = mergeDocumentRequestRow\(requestBeforeUpdate, updateResponse\)/)
  assert.match(activeView, /processingRequests\.value = \[updated, \.\.\.processingRequests\.value\]/)
  assert.match(queueComponent, /requestDocumentName\(item\)/)
  assert.match(queueComponent, /requestStudentNumber\(item\)/)
  assert.doesNotMatch(queueComponent, /item\.document_type\.document_name|item\.student\.student_number/)
})

test('queue rows prioritize student identity over request metadata', () => {
  const nameIndex = queueComponent.indexOf('class="compact-student-name"')
  const referenceIndex = queueComponent.indexOf('class="compact-request-reference"')
  const documentTypeIndex = queueComponent.indexOf('class="document-type-chip"')

  assert.ok(nameIndex < referenceIndex)
  assert.ok(referenceIndex < documentTypeIndex)
  assert.match(styles, /\.compact-student-heading > \.compact-student-name\s*\{[^}]*font-size:\s*0\.96rem[^}]*font-weight:\s*800/s)
  assert.match(styles, /\.compact-request-reference\s*\{[^}]*font-size:\s*0\.74rem[^}]*font-weight:\s*700/s)
})

test('physical records exposes request return context only when the helper resolves it', () => {
  assert.match(physicalRecordsView, /documentRequestReturnContext\(route\.query\)/)
  assert.match(physicalRecordsView, /v-if="returnContext" class="contextual-back"/)
  assert.match(physicalRecordsView, /\.\.\.route\.query,[\s\S]*cabinet:/)
})

test('appointment recent activity follows release and appointment work sections', () => {
  const releaseIndex = appointmentsView.indexOf('class="dr-panel release-queue-panel"')
  const appointmentPaginationIndex = appointmentsView.indexOf('aria-label="Registrar appointment pages"')
  const activityIndex = appointmentsView.indexOf('<RegistrarRecentActivity')

  assert.ok(releaseIndex < appointmentPaginationIndex)
  assert.ok(appointmentPaginationIndex < activityIndex)
})

test('request details uses direct approve and reject actions without an update workflow menu', () => {
  assert.doesNotMatch(activeView, /Update Workflow|Hide Workflow Actions/)
  assert.match(activeView, /@click="approveSelected"/)
  assert.match(activeView, /@click="openReject"/)
  assert.match(activeView, /title="Reject Request"/)
  assert.match(activeView, /reason-label="Reason for rejection"/)
})
