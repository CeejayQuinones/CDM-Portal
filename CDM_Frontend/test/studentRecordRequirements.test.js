import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = (path) => readFile(new URL(path, import.meta.url), 'utf8')

test('Student Settings exposes the complete Record Requirements workflow', async () => {
  const settings = await read('../src/views/SettingsView.vue')

  assert.match(settings, /Record Requirements/)
  for (const state of ['Missing', 'Submitted', 'Under Review', 'Verified', 'Needs Resubmission']) assert.match(settings, new RegExp(state))
  assert.match(settings, /Physical Copy Required/)
  assert.match(settings, /Upload Document/)
  assert.match(settings, /Upload Replacement/)
  assert.match(settings, /record_requirements\.unresolved_count/)
  assert.match(settings, /Your Student Record requirements are complete/)
  assert.match(settings, /@media \(max-width: 720px\)/)
  assert.match(settings, /var\(--settings-surface/)
})

test('Registrar Needs Attention links exact document context and keeps review actions separate', async () => {
  const [records, documents] = await Promise.all([
    read('../src/modules/student-management/StudentRecordsView.vue'),
    read('../src/modules/student-management/StudentDocumentsView.vue'),
  ])

  for (const category of ['Digital Missing', 'Awaiting Review', 'Needs Resubmission', 'Hard Copy Required', 'Physical Records']) assert.match(records, new RegExp(category))
  assert.match(records, /query: \{ document: item\.document_type_id \}/)
  assert.match(records, /documentRequirementsOpen = ref\(false\)/)
  assert.match(records, /class="productivity-card document-requirements-card"/)
  assert.match(records, /aria-haspopup="dialog"/)
  assert.match(records, /aria-controls="document-requirements-dialog"/)
  assert.match(records, /v-if="documentRequirementsOpen"/)
  assert.match(records, /id="document-requirements-dialog"/)
  assert.match(records, /aria-modal="true"/)
  assert.match(records, /@keydown="trapDocumentRequirementsFocus"/)
  assert.match(records, /<Transition name="modal-fade">/)
  assert.match(records, /documentRequirementsOpen\.value = true/)
  assert.match(records, /documentRequirementsOpen\.value = false/)
  assert.match(records, /documentRequirementCount.*unresolved/)
  assert.match(records, /class="attention-table"/)
  assert.match(records, /Digital Status/)
  assert.match(records, /Physical Status/)
  assert.match(records, /nonDocumentAttentionStudents/)
  assert.match(records, /filter\(issue => issue\.area !== 'documents'\)/)
  assert.match(records, /<article v-if="nonDocumentAttentionStudents\.length" class="productivity-card attention-card">/)
  assert.doesNotMatch(records, /No other Student Records currently need attention\./)
  assert.match(records, /:class="\{ 'has-attention': nonDocumentAttentionStudents\.length \}"/)
  assert.match(records, /productivity-grid \{[^}]*grid-template-areas: "recent documents" "recent activity"/)
  assert.match(records, /productivity-grid\.has-attention \{ grid-template-areas: "recent documents" "recent activity" "attention attention"/)
  assert.match(records, /document-requirements-card \{[^}]*grid-area: documents/)
  assert.doesNotMatch(records, /document-requirements-card \{[^}]*grid-column: 1 \/ -1/)
  assert.doesNotMatch(records, /class="attention-items"/)
  assert.doesNotMatch(records, /document-attention-details/)
  assert.doesNotMatch(records, /grid-template-columns: repeat\(auto-fit, minmax\(230px, 1fr\)\)/)
  assert.match(records, /prefers-reduced-motion: reduce/)
  assert.match(records, /var\(--color-surface\)/)
  for (const action of ['Verify', 'Needs Resubmission', 'Require Hard Copy', 'Mark Hard Copy Received', 'Require Again']) assert.match(documents, new RegExp(action))
  assert.match(documents, /role="dialog"/)
  assert.match(documents, /aria-modal="true"/)
  assert.match(documents, /version: document\.version/)
})

test('Student Records keeps listing search primary and advanced controls collapsed', async () => {
  const records = await read('../src/modules/student-management/StudentRecordsView.vue')

  assert.match(records, /filtersOpen = ref\(false\)/)
  assert.match(records, /class="listing-search"/)
  assert.match(records, /aria-controls="advanced-student-filters"/)
  assert.match(records, /:aria-expanded="filtersOpen"/)
  assert.match(records, /v-if="filtersOpen" id="advanced-student-filters"/)
  assert.match(records, /activeAdvancedFilterCount/)
  assert.match(records, /filters\.sort !== 'student_number'/)
  assert.match(records, /filters\.direction !== 'asc'/)
  assert.match(records, /function clearAdvancedFilters\(\)/)
  assert.match(records, /Object\.assign\(filters, \{ course: ''/)
  assert.doesNotMatch(records, /function resetFilters\(\)/)
  assert.match(records, /Saved filter/)
  assert.match(records, /Visible Columns/)
  assert.match(records, /advanced-filters-enter-active.*200ms/)
})
