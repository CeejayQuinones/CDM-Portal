import assert from 'node:assert/strict'
import test from 'node:test'
import { calculateLiveGradeResult, displayLiveGrade } from '../src/modules/grading/gradeLivePreview.js'

const student = { enrollment_subject_id: 1 }
const sheet = {
  midterm_weight: 40,
  finals_weight: 60,
  weights: [
    { period: 'midterm', category: 'Quiz', weight_percentage: 100 },
    { period: 'finals', category: 'Quiz', weight_percentage: 100 },
  ],
  assessments: [
    { id: 11, period: 'midterm', category: 'Quiz', max_score: 100, status: 'active', scores: [] },
    { id: 12, period: 'finals', category: 'Quiz', max_score: 100, status: 'active', scores: [{ enrollment_subject_id: 1, score: 90 }] },
  ],
}
const scales = [
  { min_percentage: 75, max_percentage: 100, grade_point: 1.75, remark: 'PASSED', is_active: true },
  { min_percentage: 0, max_percentage: 74.99, grade_point: 5, remark: 'FAILED', is_active: true },
]
const preview = drafts => calculateLiveGradeResult({ student, sheet, drafts, scales })

test('draft scores calculate the period, final grade, and registrar grade point immediately', () => {
  assert.equal(displayLiveGrade(preview({})?.midterm), '—')
  assert.equal(displayLiveGrade(preview({ '1:11': '80' }).midterm), '80.00')
  assert.equal(displayLiveGrade(preview({ '1:11': '80' }).final), '86.00')
  assert.equal(preview({ '1:11': '80' }).gradePoint, '1.75')
  assert.equal(preview({ '1:11': '80' }).remarks, 'PASSED')
})

test('zero marks INC, not a failing numeric result or grade point', () => {
  const result = preview({ '1:11': '0' })
  assert.equal(displayLiveGrade(result.midterm), 'INC')
  assert.equal(displayLiveGrade(result.final), 'INC')
  assert.equal(result.remarks, 'INCOMPLETE')
  assert.equal(result.gradePoint, null)
})

test('missing and invalid scores never claim an official final result', () => {
  const partial = preview({ '1:11': '' })
  assert.equal(displayLiveGrade(partial.final), '—')
  const invalid = preview({ '1:11': '101' })
  assert.equal(displayLiveGrade(invalid.midterm), 'Fix score')
  assert.equal(displayLiveGrade(invalid.final), '—')
})

test('subpassing percentage receives only the configured failed remark', () => {
  const result = preview({ '1:11': '30' })
  assert.equal(displayLiveGrade(result.final), '66.00')
  assert.equal(result.gradePoint, '5.00')
  assert.equal(result.remarks, 'FAILED')
})

test('invalid grading criteria never produce a misleading numeric preview', () => {
  const changedSheet = { ...sheet, midterm_weight: 50 }
  const result = calculateLiveGradeResult({ student, sheet: changedSheet, drafts: { '1:11': '80' }, scales })
  assert.equal(displayLiveGrade(result.final), 'Set criteria')
  assert.equal(result.gradePoint, null)
})

test('an active custom category with zero weight cannot show a misleading grade', () => {
  const changedSheet = {
    ...sheet,
    weights: [...sheet.weights, { period: 'midterm', category: 'Notes', weight_percentage: 0 }],
    assessments: [...sheet.assessments, { id: 13, period: 'midterm', category: 'Notes', max_score: 100, status: 'active', scores: [{ enrollment_subject_id: 1, score: 75 }] }],
  }
  const result = calculateLiveGradeResult({ student, sheet: changedSheet, drafts: { '1:11': '80' }, scales })
  assert.equal(displayLiveGrade(result.midterm), 'Set weight')
  assert.equal(displayLiveGrade(result.final), 'Set weight')
  assert.equal(result.gradePoint, null)
})
