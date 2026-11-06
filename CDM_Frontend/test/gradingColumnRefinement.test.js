import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import {
  collectColumnLabelChanges,
  createColumnLabelDrafts,
  validateColumnLabels,
} from '../src/modules/grading/gradeColumnManagement.js'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')
const assessments = [
  { id: 1, label: 'Quiz 1', category: 'Quiz', max_score: 20 },
  { id: 2, label: 'Activity 1', category: 'Activity', max_score: 50 },
]

test('column label drafts preserve identity and report only renamed assessments', () => {
  const drafts = createColumnLabelDrafts(assessments)
  assert.deepEqual(drafts, { 1: 'Quiz 1', 2: 'Activity 1' })
  assert.deepEqual(collectColumnLabelChanges(assessments, drafts), [])

  drafts[1] = '  Preliminary Quiz  '
  assert.deepEqual(collectColumnLabelChanges(assessments, drafts), [
    { assessment: assessments[0], label: 'Preliminary Quiz' },
  ])
})

test('column labels are required, bounded, and unique without case sensitivity', () => {
  assert.equal(validateColumnLabels(assessments, { 1: '', 2: 'Activity 1' })[1], 'A column label is required.')
  assert.equal(validateColumnLabels(assessments, { 1: 'x'.repeat(81), 2: 'Activity 1' })[1], 'Use 80 characters or fewer.')

  const duplicateErrors = validateColumnLabels(assessments, { 1: 'Final Quiz', 2: ' final quiz ' })
  assert.equal(duplicateErrors[1], 'Column labels must be unique in this grading period.')
  assert.equal(duplicateErrors[2], 'Column labels must be unique in this grading period.')
})

test('website grade sheet has separate score and column workflows', async () => {
  const [view, service] = await Promise.all([
    read('src/modules/grading/GradingView.vue'),
    read('src/modules/grading/gradingService.js'),
  ])

  assert.match(view, /Edit Columns/)
  assert.match(view, /Save column changes/)
  assert.match(view, /@click="saveAllScores"/)
  assert.match(view, /Save changes/)
  assert.match(view, /gradingService\.updateAssessment\(workspace\.value\.sheet\.id,assessment\.id,\{period:assessment\.period,label,category:assessment\.category,max_score:assessment\.max_score,display_order:assessment\.display_order,version:assessment\.version\}\)/)
  assert.match(service, /updateAssessment: \(sheet, id, payload\) => apiClient\.put/)
})

test('assessment removal is confirmed, archival, and protects pending score work', async () => {
  const view = await read('src/modules/grading/GradingView.vue')

  assert.match(view, /Remove Assessment Column/)
  assert.match(view, /Existing student scores remain stored and are not deleted/)
  assert.match(view, /Save or discard pending score changes before removing an assessment column/)
  assert.match(view, /Save or discard pending score changes before editing assessment columns/)
  assert.match(view, /Save or cancel column label changes before removing an assessment column/)
  assert.match(view, /gradingService\.assessmentStatus\(workspace\.value\.sheet\.id,assessment\.id,\{version:assessment\.version,status:'archived'\}\)/)
  assert.match(view, /await openClass\(workspace\.value\.class\)/)
})

test('borderless score cells retain accessible focus and keyboard editing', async () => {
  const [view, styles] = await Promise.all([
    read('src/modules/grading/GradingView.vue'),
    read('src/modules/grading/grading.css'),
  ])

  assert.match(view, /v-for="\(column,j\) in gradeTableModel\.assessmentColumns"/)
  assert.match(view, /@keydown="handleScoreKeydown"/)
  assert.match(view, /if\(event\.key==='Enter'\)[\s\S]*moveScoreSelection\(1,0\)/)
  assert.match(view, /:disabled="!editable\|\|editingColumns\|\|recoveryPending"/)
  assert.match(view, /:aria-label="`\$\{column\.assessment\.label\} score for/)
  assert.match(styles, /\.website-professor-grading \.grade-score-cell input \{[\s\S]*border: 0 !important;[\s\S]*border-radius: 0 !important;[\s\S]*box-shadow: none !important;/)
  assert.match(styles, /\.website-professor-grading \.grade-score-cell\.is-active \{[\s\S]*inset 0 0 0 2px var\(--accent\)/)
})

test('column management remains website-only and legacy clients retain their branch', async () => {
  const view = await read('src/modules/grading/GradingView.vue')

  assert.match(view, /<template v-if="isWebsitePresentation">/)
  assert.match(view, /<template v-else><section class="grading-window"/)
  assert.match(view, /isWebsitePresentation\?assessments\.value\.filter/)
  assert.match(view, /aria-label="`Remove \$\{column\.assessment\.label\} assessment column`"/)
})
