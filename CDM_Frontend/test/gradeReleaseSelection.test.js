import assert from 'node:assert/strict'
import test from 'node:test'
import { approvedReleaseSheets, programsForInstitute } from '../src/modules/grading/gradeReleaseSelection.js'

const sheet = (id, courseId, yearId = 1, semesterId = 1, status = 'approved') => ({
  id, status, section_subject: { section: { course_id: courseId, academic_year_id: yearId, semester_id: semesterId } },
})

test('release selection includes only approved sheets in the chosen term and programs', () => {
  const sheets = [sheet(1, 10), sheet(2, 20), sheet(3, 10, 2), sheet(4, 10, 1, 2), sheet(5, 10, 1, 1, 'submitted')]
  assert.deepEqual(approvedReleaseSheets(sheets, { yearId: '1', semesterId: '1', programIds: ['10'] }).map(item => item.id), [1])
  assert.deepEqual(approvedReleaseSheets(sheets, { yearId: 1, semesterId: 1, programIds: [10, 20] }).map(item => item.id), [1, 2])
  assert.deepEqual(approvedReleaseSheets(sheets, { yearId: '', semesterId: 1, programIds: [10] }), [])
})

test('program choices come from each course’s recorded institute', () => {
  const courses = [
    { id: 1, course_code: 'BSIT', department: { department_code: 'ICS' } },
    { id: 2, course_code: 'BSBA', department: { department_code: 'IBE' } },
    { id: 3, course_code: 'NEW', department: { department_code: 'ICS' } },
  ]
  assert.deepEqual(programsForInstitute(courses, 'ICS').map(course => course.id), [1, 3])
  assert.deepEqual(programsForInstitute(courses, 'ITE'), [])
})
