import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const history = read('../src/modules/document-request/RegistrarDocumentRequestHistoryView.vue')
const dialog = read('../src/modules/document-request/DocumentRequestDialog.vue')
const styles = read('../src/modules/document-request/documentRequest.css')
const service = read('../src/modules/document-request/documentRequestService.js')

test('registrar history renders the formal header, compact filters, and request table', () => {
  assert.match(history, /Document Request History/)
  assert.match(history, /Review completed, rejected, cancelled, and released document requests\./)
  for (const label of ['Search', 'Status', 'Document Type', 'Date Range', 'Reset filters']) {
    assert.match(history, new RegExp(label))
  }
  for (const column of ['Request ID', 'Student', 'Document', 'Requested', 'Status', 'Last Updated']) {
    assert.match(history, new RegExp(`<th scope="col">${column}`))
  }
})

test('history uses real list and detail endpoints and opens details from one View action', () => {
  assert.match(service, /registrarHistory:.*document-requests\/history/)
  assert.match(service, /registrarRequest:.*document-requests\/\$\{id\}/)
  assert.match(history, /@click="openDetails\(item\)">View/)
  assert.match(history, /await api\.registrarRequest\(item\.id\)/)
  assert.doesNotMatch(history, /Appointment history/)
})

test('detail modal presents real student, request, status, audit, and conditional release data', () => {
  assert.match(history, /<DocumentRequestDialog/)
  assert.match(dialog, /role="dialog"/)
  assert.match(dialog, /aria-modal="true"/)
  for (const section of ['Student', 'Document Request', 'Status', 'Processing History', 'Release Information']) {
    assert.match(history, new RegExp(`>${section}<`))
  }
  assert.match(history, /processingHistory/)
  assert.match(history, /appointmentHistory/)
  assert.match(history, /Appointment records/)
  assert.match(history, /Registrar remarks/)
  assert.match(history, /change\.reason/)
  assert.match(history, /v-if="hasReleaseInformation"/)
  assert.match(dialog, /event\.key === 'Escape'/)
  assert.match(dialog, /event\.key !== 'Tab'/)
})

test('history has concise empty states and token-based light and dark theme inheritance', () => {
  assert.match(history, /No document requests found\./)
  assert.match(history, /No requests match the selected filters\./)
  assert.match(styles, /\.history-modal-dialog[\s\S]*background: var\(--bg-surface\)/)
  assert.match(styles, /\.history-records-table th[\s\S]*background: var\(--bg-surface-alt\)/)
  assert.match(styles, /\.history-filter-row input,[\s\S]*background: var\(--bg-input\)/)
  assert.match(styles, /\.history-status-badge\.status-released[\s\S]*var\(--success-bg\)/)
})
