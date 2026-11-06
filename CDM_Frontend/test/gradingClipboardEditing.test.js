import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { performance } from 'node:perf_hooks'
import test from 'node:test'
import {
  SCORE_HISTORY_LIMIT,
  applyDraftOperation,
  boundedHistory,
  createDraftOperation,
  draftSnapshot,
  planScorePaste,
  scoreCoordinate,
  selectedScoreCoordinates,
  selectionToTsv,
} from '../src/modules/grading/gradeSpreadsheetInteraction.js'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')
const students = Array.from({ length: 12 }, (_, index) => ({
  enrollment_subject_id: 100 + index,
  student_number: `24-${index + 1}`,
}))
const columns = Array.from({ length: 8 }, (_, index) => ({
  key: `assessment-${index + 1}`,
  assessment: { id: 200 + index, label: `Quiz ${index + 1}`, max_score: index === 0 ? 20 : 100 },
}))
const persisted = new Map([
  ['100:200', '10'],
  ['100:201', '0'],
  ['101:200', ''],
  ['101:201', '15'],
])
const valueFor = (student, assessment) => persisted.get(`${student.enrollment_subject_id}:${assessment.id}`) ?? ''
const cell = (row, column) => scoreCoordinate(students[row], columns[column], row, column)

test('single and rectangular selections use stable enrollment and assessment IDs', () => {
  assert.deepEqual(selectedScoreCoordinates({ anchor: cell(2, 3), focus: cell(2, 3) }, students, columns).map(item => item.key), ['102:203'])
  assert.deepEqual(
    selectedScoreCoordinates({ anchor: cell(2, 2), focus: cell(0, 0) }, students, columns).map(item => item.key),
    ['100:200', '100:201', '100:202', '101:200', '101:201', '101:202', '102:200', '102:201', '102:202'],
  )

  const filtered = [students[8], students[2], students[5]]
  const filteredSelection = {
    anchor: scoreCoordinate(filtered[0], columns[1], 0, 1),
    focus: scoreCoordinate(filtered[1], columns[2], 1, 2),
  }
  assert.deepEqual(selectedScoreCoordinates(filteredSelection, filtered, columns).map(item => item.key), [
    '108:201', '108:202', '102:201', '102:202',
  ])
})

test('copy creates Excel-compatible TSV with row order, explicit zero, blank fields, and injection protection', () => {
  const tsv = selectionToTsv({
    selection: { anchor: cell(0, 0), focus: cell(1, 1) },
    roster: students,
    columns,
    valueFor,
  })
  assert.equal(tsv, '10\t0\n\t15')

  const protectedText = selectionToTsv({
    selection: { anchor: cell(0, 0), focus: cell(0, 0) },
    roster: students,
    columns,
    valueFor: () => '=SUM(A1:A2)',
  })
  assert.equal(protectedText, "'=SUM(A1:A2)")
})

test('valid multi-cell paste applies as one local operation while blanks leave scores unchanged', () => {
  const drafts = {}
  const plan = planScorePaste({
    text: '0\t18\n\t20',
    start: cell(0, 0),
    roster: students,
    columns,
    drafts,
    valueFor,
  })

  assert.deepEqual(plan.errors, [])
  assert.equal(plan.skippedBlanks, 1)
  assert.deepEqual(plan.changes.map(change => [change.key, change.after.value]), [
    ['100:200', '0'],
    ['100:201', '18'],
    ['101:201', '20'],
  ])
  const operation = createDraftOperation('paste', plan.changes, 'Paste range')
  assert.equal(operation.changes.length, 3)
  assert.deepEqual(applyDraftOperation(drafts, operation, 'redo'), {
    '100:200': '0',
    '100:201': '18',
    '101:201': '20',
  })
})

test('paste validation is atomic for formulas, invalid values, score limits, and protected boundaries', () => {
  for (const [text, start, expected] of [
    ['=2+2\t10', cell(0, 0), /formulas/],
    ['word\t10', cell(0, 0), /numeric score/],
    ['21\t10', cell(0, 0), /0 to 20/],
    ['1\t2\t3', cell(0, columns.length - 1), /extends beyond/],
  ]) {
    const plan = planScorePaste({ text, start, roster: students, columns, drafts: {}, valueFor })
    assert.equal(plan.changes.length, 0)
    assert.match(plan.errors.join(' '), expected)
  }

  const locked = planScorePaste({ text: '10', start: cell(0, 0), roster: students, columns, drafts: {}, valueFor, editable: false })
  assert.equal(locked.changes.length, 0)
  assert.match(locked.errors[0], /locked/)
})

test('undo and redo restore single-cell and multi-cell draft snapshots with a bounded history', () => {
  const key = '100:200'
  const edit = createDraftOperation('edit', [{
    key,
    before: draftSnapshot({}, key),
    after: { exists: true, value: '12' },
  }])
  const pasted = createDraftOperation('paste', [
    { key: '100:201', before: draftSnapshot({}, '100:201'), after: { exists: true, value: '7' } },
    { key: '101:201', before: draftSnapshot({}, '101:201'), after: { exists: true, value: '8' } },
  ])
  const afterEdit = applyDraftOperation({}, edit, 'redo')
  const afterPaste = applyDraftOperation(afterEdit, pasted, 'redo')
  assert.deepEqual(applyDraftOperation(afterPaste, pasted, 'undo'), afterEdit)
  assert.deepEqual(applyDraftOperation(afterEdit, edit, 'undo'), {})
  assert.deepEqual(applyDraftOperation({}, edit, 'redo'), afterEdit)

  let history = []
  for (let index = 0; index < SCORE_HISTORY_LIMIT + 10; index++) history = boundedHistory(history, { kind: 'edit', changes: [], index })
  assert.equal(history.length, SCORE_HISTORY_LIMIT)
  assert.equal(history[0].index, 10)
})

test('100-cell paste planning and 300-student selection stay within a practical local-operation budget', () => {
  const largeRoster = Array.from({ length: 300 }, (_, index) => ({ enrollment_subject_id: 1000 + index }))
  const largeColumns = Array.from({ length: 20 }, (_, index) => ({ assessment: { id: 2000 + index, label: `Assessment ${index + 1}`, max_score: 100 } }))
  const text = Array.from({ length: 10 }, () => Array.from({ length: 10 }, (_, index) => String(index)).join('\t')).join('\n')
  const start = scoreCoordinate(largeRoster[0], largeColumns[0], 0, 0)
  const started = performance.now()
  for (let run = 0; run < 100; run++) {
    const plan = planScorePaste({ text, start, roster: largeRoster, columns: largeColumns, drafts: {}, valueFor: () => '' })
    assert.equal(plan.changes.length, 100)
    selectedScoreCoordinates({ anchor: start, focus: scoreCoordinate(largeRoster[299], largeColumns[19], 299, 19) }, largeRoster, largeColumns)
  }
  assert.ok(performance.now() - started < 1500)
})

test('Website Grade Entry owns clipboard shortcuts, history boundaries, and selection styling without changing Edit in Excel', async () => {
  const [view, styles] = await Promise.all([
    read('src/modules/grading/GradingView.vue'),
    read('src/modules/grading/grading.css'),
  ])

  assert.match(view, /<table ref="scoreGrid"[\s\S]*@copy="handleScoreCopy"[\s\S]*@paste="handleScorePaste"[\s\S]*@keydown="handleScoreKeydown"/)
  assert.match(view, /event\.shiftKey\?redoScoreEdit\(\):undoScoreEdit\(\)/)
  assert.match(view, /moveScoreSelection\(\.\.\.deltas\[event\.key\],event\.shiftKey\)/)
  assert.match(view, /function resetScoreInteraction/)
  assert.match(view, /function clearScoreHistory/)
  assert.match(view, /Undo history was reset at the saved-data boundary/)
  assert.match(view, /<span>Edit in Excel<\/span>/)
  assert.match(view, /Step 5 of 5/)
  assert.match(view, /<template v-else><section class="grading-window"/)
  assert.match(styles, /\.grade-score-cell\.is-selected/)
  assert.match(styles, /\.grade-score-cell\.is-active/)
  assert.doesNotMatch(styles, /\.registrar-inspired-grid \.grade-score-cell:focus-within/)
})
