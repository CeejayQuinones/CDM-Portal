import { performance } from 'node:perf_hooks'
import { buildGradeTableModel } from '../src/modules/grading/gradeTableModel.js'
import { analyzeGradeRecovery, createGradeRecoveryRecord, gradeRecoveryScope } from '../src/modules/grading/gradeDraftRecovery.js'
import { createGradeStudentSearchIndex, findIndexedGradeStudents, resolveGradeJumpTarget } from '../src/modules/grading/gradeFindNavigation.js'
import {
  applyDraftOperation,
  createDraftOperation,
  draftSnapshot,
  planScorePaste,
  resolveScoreCoordinate,
  scoreCoordinate,
  selectedScoreCoordinates,
} from '../src/modules/grading/gradeSpreadsheetInteraction.js'

const runs = 40
const makeAssessments = count => Array.from({ length: count }, (_, index) => ({
  id: 2000 + index,
  label: `Assessment ${index + 1}`,
  category: index < 10 ? 'Quiz' : 'Activity',
  max_score: 100,
  status: 'active',
  period: 'midterm',
  version: 1,
}))
const assessments = makeAssessments(20)
const columns = buildGradeTableModel(assessments).assessmentColumns
const makeRoster = count => Array.from({ length: count }, (_, index) => ({
  enrollment_subject_id: 1000 + index,
  student_number: `ST-${String(index + 1).padStart(4, '0')}`,
  first_name: 'Generated',
  last_name: `Student ${index + 1}`,
}))
const pasteText = Array.from({ length: 10 }, (_, row) => Array.from({ length: 10 }, (_, column) => String((row + column) % 100)).join('\t')).join('\n')

function median(values) {
  const sorted = [...values].sort((left, right) => left - right)
  return sorted[Math.floor(sorted.length / 2)]
}

function measure(label, action) {
  const samples = []
  for (let run = 0; run < runs; run++) {
    const started = performance.now()
    action()
    samples.push(performance.now() - started)
  }
  return { label, median_ms: Number(median(samples).toFixed(3)), max_ms: Number(Math.max(...samples).toFixed(3)), runs }
}

const results = []
for (const size of [30, 100, 300]) {
  for (const assessmentCount of [10, 20, 40]) {
    const roster = makeRoster(size)
    const fixtureColumns = buildGradeTableModel(makeAssessments(assessmentCount)).assessmentColumns
    const start = scoreCoordinate(roster[0], fixtureColumns[0], 0, 0)
    results.push(measure(`${size} × ${assessmentCount}: cell projection`, () => {
      roster.map((student, row) => fixtureColumns.map((column, columnIndex) => scoreCoordinate(student, column, row, columnIndex)))
    }))
    results.push(measure(`${size} × ${assessmentCount}: range map`, () => {
      selectedScoreCoordinates({ anchor: start, focus: scoreCoordinate(roster.at(-1), fixtureColumns.at(-1), size - 1, fixtureColumns.length - 1) }, roster, fixtureColumns)
    }))
  }
}

const roster = makeRoster(300)
const studentSearchIndex = createGradeStudentSearchIndex(roster)
const start = scoreCoordinate(roster[0], columns[0], 0, 0)
const editOperation = createDraftOperation('edit', [{ key: start.key, before: draftSnapshot({}, start.key), after: { exists: true, value: '88' } }])
results.push(measure('single-cell local edit', () => {
  applyDraftOperation({}, editOperation, 'redo')
}))
results.push(measure('keyboard navigation coordinate lookup', () => {
  resolveScoreCoordinate({ enrollmentSubjectId: roster[150].enrollment_subject_id, assessmentId: columns[10].assessment.id }, roster, columns)
}))
results.push(measure('single operation undo + redo', () => {
  const key = start.key
  const operation = createDraftOperation('edit', [{ key, before: draftSnapshot({}, key), after: { exists: true, value: '88' } }])
  const edited = applyDraftOperation({}, operation, 'redo')
  const undone = applyDraftOperation(edited, operation, 'undo')
  applyDraftOperation(undone, operation, 'redo')
}))
results.push(measure('100-cell paste validation + apply', () => {
  const plan = planScorePaste({ text: pasteText, start, roster, columns, drafts: {}, valueFor: () => '' })
  const operation = createDraftOperation('paste', plan.changes)
  applyDraftOperation({}, operation, 'redo')
}))
results.push(measure('Student search + assessment jump', () => {
  const student = findIndexedGradeStudents(studentSearchIndex, 'student 251', 8)[0]
  resolveGradeJumpTarget({ enrollmentSubjectId: student.enrollment_subject_id, assessmentId: assessments[15].id, roster, columns })
}))
const recoveryScope = gradeRecoveryScope({ accountId: 7, classContext: { id: 44, academic_year: '2026-2027', semester: 'First' }, sheet: { id: 90, version: 2 }, period: 'midterm' })
const recoveryDrafts = Object.fromEntries(roster.slice(0, 100).map(student => [`${student.enrollment_subject_id}:${assessments[0].id}`, '75']))
const recoveryKeys = new Set(Object.keys(recoveryDrafts))
results.push(measure('100-edit recovery create + validate', () => {
  const record = createGradeRecoveryRecord({ scope: recoveryScope, drafts: recoveryDrafts, dirtyKeys: recoveryKeys, roster, assessments, persistedScore: () => null, now: 1_000 })
  JSON.stringify(record)
  analyzeGradeRecovery({ record, scope: recoveryScope, roster, assessments, persistedScore: () => null, editable: true })
}))
results.push(measure('20-column grading model build', () => buildGradeTableModel(assessments)))

console.table(results)
