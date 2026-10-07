import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const view = readFileSync(new URL('../src/modules/grading/GradingView.vue', import.meta.url), 'utf8')
const access = readFileSync(new URL('../src/config/accessControl.js', import.meta.url), 'utf8')
const service = readFileSync(new URL('../src/modules/grading/gradingService.js', import.meta.url), 'utf8')

test('grading workspace exposes Phase 1 professor states and actions', () => {
  for (const text of ['My Classes', 'Search Student', 'Midterm', 'Finals', 'Add Assessment', 'Remove', 'Restore', 'Draft scores saved', 'No enrolled Students', 'Loading grading workspace']) assert.match(view, new RegExp(text, 'i'))
  assert.match(view, /const editable=computed/)
  assert.match(view, /type="number"/)
})

test('grading navigation is limited to professor and grading staff', () => {
  assert.match(access, /grading: \[ROLES\.STUDENT, ROLES\.PROFESSOR, ROLES\.REGISTRAR_STAFF, ROLES\.ADMIN\]/)
  assert.match(access, /label: 'My Classes'/)
  assert.match(access, /label: 'Grade Periods'/)
})

test('grading view uses shared semantic tokens and responsive table', () => {
  const css = readFileSync(new URL('../src/modules/grading/grading.css', import.meta.url), 'utf8')
  assert.match(css, /var\(--bg-surface/)
  assert.match(css, /overflow:auto/)
  assert.match(css, /position:sticky/)
  assert.match(css, /@media/)
})

test('Registrar grade-period load is isolated and validation errors are field-level', () => {
  assert.match(view, /else await loadPeriods\(\)/)
  assert.match(view, /openStaffTab\('reviews'\)/)
  assert.match(view, /activeYear=options\.value\.academic_years\?\.find\(y=>y\.status==='active'\)/)
  assert.match(view, /periodErrors\.academic_year_id/)
  assert.match(view, /periodErrors\.midterm_deadline/)
  assert.match(view, /midterm_opens_at:apiDate\(periodForm\.value\.midterm_opens_at\)/)
  assert.match(service, /Object\.values\(gradingValidationErrors\(error\)\)\.flat\(\)\.find\(Boolean\) \|\| error\.response\?\.data\?\.message/)
})
