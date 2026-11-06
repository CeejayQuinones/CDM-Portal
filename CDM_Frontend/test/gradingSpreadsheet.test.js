import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import {
  clearSavedScoreBatch,
  collectScoreBatches,
  isScoreDraftDirty,
  scoreCellKey,
  validateScoreDraft,
} from '../src/modules/grading/gradeSpreadsheet.js'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('spreadsheet score validation enforces numeric assessment limits', () => {
  assert.equal(validateScoreDraft('', 20), 'Enter a score.')
  assert.equal(validateScoreDraft('not-a-number', 20), 'Enter a valid number.')
  assert.equal(validateScoreDraft(-1, 20), 'Score cannot be below zero.')
  assert.equal(validateScoreDraft(21, 20), 'Maximum score is 20.')
  assert.equal(validateScoreDraft('20', 20), '')
})

test('a successful score batch clears only cells confirmed saved', () => {
  const drafts = { '11:7': '18', '12:7': '19', '11:8': '20' }
  const remaining = clearSavedScoreBatch(drafts, {
    assessment_id: 7,
    scores: [{ enrollment_subject_id: 11 }, { enrollment_subject_id: 12 }],
  })

  assert.deepEqual(remaining, { '11:8': '20' })
  assert.deepEqual(drafts, { '11:7': '18', '12:7': '19', '11:8': '20' })
})

test('spreadsheet dirty state ignores unchanged numeric values', () => {
  const cellKey = scoreCellKey(11, 7)
  assert.equal(isScoreDraftDirty({}, cellKey, 18), false)
  assert.equal(isScoreDraftDirty({ [cellKey]: '18.00' }, cellKey, 18), false)
  assert.equal(isScoreDraftDirty({ [cellKey]: '19' }, cellKey, 18), true)
  assert.equal(isScoreDraftDirty({ [cellKey]: '' }, cellKey, 18), true)
})

test('one save action groups pending cells into the existing assessment API contract', () => {
  const roster = [{ enrollment_subject_id: 11 }, { enrollment_subject_id: 12 }]
  const assessments = [
    { id: 7, status: 'active' },
    { id: 8, status: 'active' },
    { id: 9, status: 'archived' },
  ]
  const existing = new Map([
    ['11:7', { score: 10, version: 2 }],
    ['12:7', { score: 12, version: 1 }],
  ])
  const drafts = { '11:7': '11', '12:7': '12.00', '12:8': '7', '11:9': '99' }
  const batches = collectScoreBatches({
    drafts,
    roster,
    assessments,
    persistedScore: (student, assessment) => existing.get(scoreCellKey(student.enrollment_subject_id, assessment.id)),
  })

  assert.deepEqual(batches, [
    { assessment_id: 7, scores: [{ enrollment_subject_id: 11, score: 11, version: 2 }] },
    { assessment_id: 8, scores: [{ enrollment_subject_id: 12, score: 7 }] },
  ])
})

test('Professor spreadsheet redesign is isolated to the website presentation', async () => {
  const [view, styles] = await Promise.all([
    read('src/modules/grading/GradingView.vue'),
    read('src/modules/grading/grading.css'),
  ])

  assert.match(view, /website-professor-grading/)
  assert.match(view, /v-if="isWebsitePresentation"/)
  assert.match(view, /v-else><section class="grading-window"/)
  assert.match(view, /@click="saveAllScores"/)
  assert.match(view, /Save changes/)
  assert.match(view, /function handleScoreKeydown/)
  assert.match(view, /if\(event\.key==='Enter'\)/)
  assert.match(view, /aria-label="Student assessment scores and calculated grade results"/)
  assert.match(styles, /\.website-professor-grading/)
  assert.match(styles, /\.professor-grade-sheet thead th[\s\S]*position: sticky/)
  assert.match(styles, /prefers-reduced-motion: reduce/)
})
