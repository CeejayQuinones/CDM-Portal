import assert from 'node:assert/strict'
import test from 'node:test'
import { instituteCourseIds, mergeInstituteReviews } from '../src/modules/grading/instituteGradeSheets.js'

const courses = [
  { id: 11, course_code: 'BSIT' },
  { id: 12, course_code: 'BSCS' },
  { id: 21, course_code: 'BSED' },
  { id: 31, course_code: 'BSBA' },
  { id: 99, course_code: 'UNVERIFIED' },
]

test('institute workspaces resolve only verified stable course identifiers', () => {
  assert.deepEqual(instituteCourseIds('ICS', courses), ['11', '12'])
  assert.deepEqual(instituteCourseIds('ITE', courses), ['21'])
  assert.deepEqual(instituteCourseIds('IBE', courses), ['31'])
  assert.deepEqual(instituteCourseIds('ICS', courses, 12), ['12'])
  assert.deepEqual(instituteCourseIds('ICS', courses, 31), [])
  assert.deepEqual(instituteCourseIds('UNKNOWN', courses), [])
})

test('server-filtered institute responses merge summaries and retain newest sheets', () => {
  const merged = mergeInstituteReviews([
    { summary: { pending_review: 1, approved: 2, published: 0 }, sheets: { total: 1, data: [{ id: 1, submitted_at: '2026-01-01' }] } },
    { summary: { pending_review: 3, approved: 0, published: 1 }, sheets: { total: 1, data: [{ id: 2, submitted_at: '2026-02-01' }] } },
  ])
  assert.deepEqual(merged.summary, { pending_review: 4, approved: 2, published: 1 })
  assert.deepEqual(merged.sheets.data.map(sheet => sheet.id), [2, 1])
  assert.equal(merged.sheets.total, 2)
})
