import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const active = read('../src/modules/document-request/RegistrarDocumentRequestView.vue')
const queue = read('../src/modules/document-request/RegistrarRequestQueue.vue')
const history = read('../src/modules/document-request/RegistrarDocumentRequestHistoryView.vue')
const student = read('../src/modules/document-request/StudentDocumentRequestView.vue')
const types = read('../src/modules/document-request/DocumentTypesManagementView.vue')
const appointments = read('../src/modules/document-request/RegistrarAppointmentsView.vue')
const router = read('../src/router/index.js')
const dialog = read('../src/modules/document-request/DocumentRequestDialog.vue')
const badge = read('../src/modules/document-request/DocumentRequestStatusBadge.vue')
const sidebar = read('../src/components/Sidebar.vue')
const layout = read('../src/layouts/DashboardLayout.vue')
const styles = read('../src/modules/document-request/documentRequest.css')
const primitives = read('../src/modules/document-request/documentRequestPrimitives.css')
const theme = read('../src/assets/styles/student-theme.css')
const skeleton = read('../src/modules/document-request/DocumentRequestSkeleton.vue')
const tableSkeleton = read('../src/modules/document-request/DocumentRequestTableSkeleton.vue')
const appointmentSkeleton = read('../src/modules/document-request/DocumentRequestAppointmentSkeleton.vue')
const recentActivity = read('../src/modules/document-request/RegistrarRecentActivity.vue')

test('document request pages share headers, status badges, tables, and dialogs', () => {
  for (const source of [active, history, student, types]) assert.match(source, /DocumentRequestPageHeader/)
  for (const source of [active, history, student]) assert.match(source, /DocumentRequestStatusBadge/)
  for (const source of [active, history, student, types]) assert.match(source, /DocumentRequestDialog/)
  assert.match(badge, /documentHistoryStatus/)
  assert.match(dialog, /aria-modal="true"/)
  assert.match(dialog, /trigger\.focus/)
})

test('registrar active requests use operational filters and one formal queue table', () => {
  for (const label of ['Search', 'Document Type', 'Date Range', 'Reset']) assert.match(active, new RegExp(label))
  for (const column of ['Request ID', 'Student', 'Document', 'Requested', 'Status', 'Updated', 'Action']) {
    assert.match(queue, new RegExp(`<th scope="col">${column}`))
  }
  assert.match(queue, /View \/ Process/)
})

test('student requests provide creation, filtering, request records, real status history, and details', () => {
  assert.match(student, /Request a Document/)
  assert.match(student, /My Requests/)
  assert.match(student, /visibleStudentRequests/)
  assert.match(student, /requestProgressEvents/)
  assert.match(student, /item\.approved_at/)
  assert.match(student, /item\.completed_at/)
  assert.match(student, /You have not requested any documents yet\./)
})

test('document types use search, a records table, and modal create or edit flow', () => {
  assert.match(types, /Add Document Type/)
  assert.match(types, /filteredTypes/)
  for (const column of ['Document Type', 'Description', 'Requirements', 'Processing Time', 'Status', 'Action']) {
    assert.match(types, new RegExp(`<th scope="col">${column}`))
  }
  assert.match(types, /document-type-dialog-title/)
})

test('shared tokens, modal motion, route motion, and menu motion respect reduced motion', () => {
  for (const token of ['--bg-surface', '--bg-surface-alt', '--text-primary', '--text-secondary', '--text-muted', '--border-color', '--accent', '--danger', '--warning', '--success', '--shadow-soft']) {
    assert.match(`${styles}\n${primitives}`, new RegExp(token))
  }
  assert.match(primitives, /\.dr-modal-enter-active/)
  assert.match(primitives, /prefers-reduced-motion: reduce/)
  assert.match(sidebar, /<Transition name="nav-children">/)
  assert.match(sidebar, /\.nav-children-enter-active/)
  assert.match(layout, /<Transition name="portal-route"/)
  assert.match(layout, /<div :key="route\.path" class="portal-route-view">\s*<component :is="Component" \/>/)
  assert.match(layout, /prefers-reduced-motion: reduce/)
})

test('all document request routes retain content and explicit loading, empty, or error states', () => {
  for (const component of [
    'DocumentTypesManagementView',
    'RegistrarDocumentRequestView',
    'RegistrarAppointmentsView',
    'RegistrarDocumentRequestHistoryView',
    'StudentDocumentRequestView',
  ]) assert.match(router, new RegExp(`component: ${component}`))

  assert.match(types, /v-if="error"/)
  assert.match(types, /No document types match your search\./)
  assert.match(active, /v-if="error"/)
  assert.match(active, /No pending document requests\./)
  assert.match(active, /No approved document requests\./)
  assert.match(appointments, /v-if="error"[^>]*role="alert"/)
  assert.match(appointments, /No approved appointments scheduled today\./)
  assert.match(history, /v-if="error"/)
  assert.match(history, /No requests match the selected filters\./)
  assert.match(student, /v-if="error"[^>]*role="alert"/)
  assert.match(student, /You have not requested any documents yet\./)
})

test('shared skeletons match redesigned tables and appointments without legacy loaders', () => {
  assert.match(skeleton, /dr-skeleton--\$\{kind\}/)
  assert.match(tableSkeleton, /dr-skeleton-table-header/)
  assert.match(tableSkeleton, /dr-skeleton-table-row/)
  assert.match(appointmentSkeleton, /dr-appointment-skeleton-row/)
  for (const source of [active, history, types, student, queue]) assert.match(source, /DocumentRequestTableSkeleton/)
  assert.match(appointments, /DocumentRequestAppointmentSkeleton/)
  assert.match(recentActivity, /DocumentRequestSkeleton/)
  for (const source of [active, history, types, student, appointments, recentActivity]) {
    assert.doesNotMatch(source, /skeleton-shimmer|appointment-row-skeleton|appointment-filter-skeleton/)
  }
  assert.match(primitives, /--skeleton-base/)
  assert.match(primitives, /@keyframes dr-skeleton-shimmer/)
  assert.match(primitives, /prefers-reduced-motion: reduce[\s\S]*\.dr-skeleton \{ animation: none/)
})

test('dark mode exposes layered shared surfaces and appointments keep distinct data and actions', () => {
  for (const token of ['--bg-surface-raised', '--border-strong', '--surface-1', '--surface-2', '--surface-3', '--skeleton-base', '--skeleton-highlight']) {
    assert.match(theme, new RegExp(token))
  }
  assert.match(appointments, /class="release-request-document"/)
  assert.match(appointments, /class="release-request-schedule"/)
  assert.match(appointments, /formatTime\(appointment\.appointment_time\)/)
  assert.match(appointments, /Resend Claim Code/)
  assert.match(styles, /\.release-request-row:hover/)
  assert.match(styles, /\.verification-submit:disabled/)
})

test('Teleported Student request dialogs inherit dark semantic surfaces and readable controls', () => {
  for (const alias of ['student-surface', 'student-surface-soft', 'student-field', 'student-text', 'student-muted', 'student-border']) {
    assert.match(theme, new RegExp(`html\\[data-student-theme='dark'\\][\\s\\S]*--${alias}: var\\(`))
  }
  assert.match(styles, /\.student-request-form-grid :is\(input, select, textarea\)[\s\S]*background: var\(--bg-input\)/)
  assert.match(styles, /\.student-request-form-grid select option[\s\S]*background: var\(--bg-input\)/)
  assert.match(styles, /\.student-request-form-grid :is\(input, textarea\)::placeholder[\s\S]*opacity: 1/)
  assert.match(styles, /\.appointment-details-header[\s\S]*background: var\(--surface-2\)/)
  assert.match(styles, /\.appointment-details-footer[\s\S]*background: var\(--surface-2\)/)
  assert.match(styles, /\.appointment-close-action[\s\S]*background: var\(--surface-3\)/)
})
