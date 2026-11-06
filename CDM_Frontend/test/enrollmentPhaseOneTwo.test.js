import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('Phase 1.2 uses the collaborators three-step order without a duplicate application store', async () => {
  const view = await read('src/modules/enrollment/EnrollmentStudent.vue')
  assert.match(view, /const enrollmentSteps=isWebsitePresentation\?\['Student info','Enrollment','Course'\]/)
  assert.match(view, /style="--step-count:3"/)
  assert.match(view, /v-if="step===0"[\s\S]*Personal details/)
  assert.match(view, /v-if="step===1"[\s\S]*Choose your classification/)
  assert.match(view, /v-if="isWebsitePresentation && step===2"[\s\S]*Academic path and review/)
  assert.doesNotMatch(view, /v-if="isWebsitePresentation && step===3[^\n]*Review your application/)
  assert.match(view, /current_application/)
  assert.match(view, /An application already exists|View current application|current/)
  assert.match(view, /const finalDraftStep=isWebsitePresentation\?2:3/)
  assert.match(view, /!isWebsitePresentation && step===3/)
})

test('personal details are editable within the Enrollment draft without inventing address fields', async () => {
  const view = await read('src/modules/enrollment/EnrollmentStudent.vue')
  for (const field of ['First name', 'Middle name', 'Last name', 'Birth date', 'Age', 'Gender', 'Contact number', 'Email', 'Full address']) {
    assert.match(view, new RegExp(field))
  }
  assert.match(view, /personal_details:\{\.\.\.personalDetails\}/)
  assert.match(view, /does not overwrite your account profile or Admission record/)
  assert.match(view, /does not support separate province, city, or barangay fields/)
  assert.doesNotMatch(view, /v-model[^>]*(province|municipality|barangay)/i)
  assert.doesNotMatch(view, /PHILIPPINES_ADDRESS_DATA|localStorage\.setItem\([^)]*document/i)
})

test('documents and four classifications remain bound to authoritative Portal contracts', async () => {
  const [view, definitions] = await Promise.all([
    read('src/modules/enrollment/EnrollmentStudent.vue'),
    read('src/modules/enrollment/enrollment.js'),
  ])
  for (const value of ['regular', 'irregular', 'transferee', 'returnee']) assert.match(definitions, new RegExp(`value: '${value}'`))
  assert.match(view, /\/enrollment\/applications\/\$\{application\.value\.id\}\/documents/)
  assert.match(view, /accept="\.pdf,\.jpg,\.jpeg,\.png"/)
  assert.match(view, /View full \$\{d\.name\} image/)
  assert.match(view, /View \$\{d\.name\} document/)
  assert.match(view, /responseType:'blob'/)
  assert.match(view, /revokeObjectURL/)
  assert.match(view, /requirements\.some\(d=>d\.required&&!d\.attached&&!d\.reusable\)/)
  assert.match(view, /step\.value=0/)
  assert.doesNotMatch(view, /base64|FileReader|Firestore|setDoc/)
})

test('course review exposes only verified Portal academic data and no fake selection', async () => {
  const view = await read('src/modules/enrollment/EnrollmentStudent.vue')
  assert.match(view, /Primary program/)
  assert.match(view, /Verified selection/)
  assert.match(view, /Secondary program/)
  assert.match(view, /no approved secondary-choice enrollment contract/)
  assert.match(view, /applicantCourse/)
  assert.match(view, /applicantCurriculum/)
  assert.doesNotMatch(view, /slots remaining|selectedCourse|secondChoice|course_id:\s*selected/)
})

test('Phase 1.2 styles are Website-scoped and collapse personal and program grids', async () => {
  const css = await read('src/modules/enrollment/enrollment.css')
  assert.match(css, /html\.website-ui-2-client \.enrollment-workspace \.enrollment-reference-personal-grid/)
  assert.match(css, /@media \(max-width: 600px\)[\s\S]*\.enrollment-reference-personal-grid,[\s\S]*\.enrollment-reference-program-choice/)
  const rules = css.split('\n').filter(line => line.includes('.enrollment-reference-personal') || line.includes('.enrollment-reference-program'))
  assert.ok(rules.filter(line => line.includes('{')).every(line => line.trim().startsWith('html.website-ui-2-client')))
})

test('COR null schedule and frozen grading regressions remain present', async () => {
  const [cor, enrollmentTest, gradingDoc] = await Promise.all([
    read('src/modules/enrollment/EnrollmentCor.vue'),
    read('test/enrollmentAcademic.test.js'),
    read('../docs/UI_2_PROFESSOR_GRADING_FINALIZATION.md'),
  ])
  assert.match(cor, /hasSavedSchedule\(schedule\)/)
  assert.match(cor, /To be announced/)
  assert.match(await read('src/modules/enrollment/EnrollmentAcademicStudent.vue'), /scheduleRows/)
  assert.match(enrollmentTest, /start_time:null,end_time:null/)
  assert.match(gradingDoc, /Feature-freeze declaration/)
})
