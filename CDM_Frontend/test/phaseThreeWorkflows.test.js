import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { parseBulkAssessments } from '../src/modules/grading/bulkAssessmentParser.js'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')

test('bulk assessment parser supports pipe and tab formats with editable preview data', () => {
  const parsed = parseBulkAssessments('Quiz 1 | Quiz | 20\nLab 1\tActivity\t50', 3)
  assert.deepEqual(parsed.errors, [])
  assert.deepEqual(parsed.rows, [
    { label: 'Quiz 1', category: 'Quiz', max_score: 20, display_order: 3 },
    { label: 'Lab 1', category: 'Activity', max_score: 50, display_order: 4 },
  ])
  assert.match(read('../src/modules/grading/GradingView.vue'), /addAssessmentsBulk/)
  assert.match(read('../src/modules/grading/GradingView.vue'), /Parse &amp; Preview/)
})

test('bulk assessment parser reports malformed, unsupported, and duplicate rows', () => {
  assert.match(parseBulkAssessments('Incomplete row').errors[0], /Line 1/)
  assert.match(parseBulkAssessments('Exam | Project | 10').errors[0], /category must be/)
  assert.match(parseBulkAssessments('Quiz 1 | Quiz | 10\nquiz 1 | Quiz | 20').errors.at(-1), /unique/)
})

test('document request rejection closes the nested confirmation before details', () => {
  const source = read('../src/modules/document-request/RegistrarDocumentRequestView.vue')
  const closeReason = source.indexOf('reasonDialogOpen.value = false', source.indexOf('async function confirmReject'))
  const nextTick = source.indexOf('await nextTick()', closeReason)
  const closeDetails = source.indexOf('closeDetails()', nextTick)
  assert.ok(closeReason > 0 && nextTick > closeReason && closeDetails > nextTick)
  assert.match(read('../src/modules/document-request/DocumentTypesManagementView.vue'), /Official documents cannot be uploaded or issued by Students/)
})
