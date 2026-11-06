import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('Student Enrollment preserves the verified reference hierarchy with Portal-authoritative data', async () => {
  const [view, definitions] = await Promise.all([
    read('src/modules/enrollment/EnrollmentStudent.vue'),
    read('src/modules/enrollment/enrollment.js'),
  ])

  assert.match(view, /eyebrow="Qualification phase"/)
  assert.match(view, /Step \{\{ step\+1 \}\} of \{\{ enrollmentSteps\.length \}\}/)
  assert.match(view, /Personal details/)
  assert.match(view, /Choose your classification/)
  assert.match(view, /Academic path and review/)
  assert.match(view, /Final review/)
  assert.match(view, /class="en-grid enrollment-reference-classifications"/)
  assert.match(view, /v-for="\(c,index\) in classifications"/)
  assert.match(definitions, /value:\s*'regular'/)
  assert.match(definitions, /value:\s*'irregular'/)
  assert.match(definitions, /value:\s*'transferee'/)
  assert.match(definitions, /value:\s*'returnee'/)
  assert.match(view, /Document requirements/)
  assert.match(view, /PDF, JPEG or PNG · up to 10 MB/)
})

test('reference adaptation keeps draft details separate and never fabricates course, fee, or Firebase behavior', async () => {
  const view = await read('src/modules/enrollment/EnrollmentStudent.vue')
  assert.match(view, /does not overwrite your account profile or Admission record/)
  assert.match(view, /personal_details:\{\.\.\.personalDetails\}/)
  assert.match(view, /CDM Portal preserves the program accepted through Admission or assigned in Student Records/)
  assert.doesNotMatch(view, /Firestore|firebase|setDoc|firstChoice|secondChoice|assessedFees|paymentDetails/)
  assert.doesNotMatch(view, /1st Semester 2026|slots remaining/)
  assert.match(view, /apiClient\.get\('\/enrollment\/status'\)/)
  assert.match(view, /apiClient\.post\('\/enrollment\/applications'/)
})

test('document and submission controls retain server-authoritative state protection', async () => {
  const view = await read('src/modules/enrollment/EnrollmentStudent.vue')
  assert.match(view, /:disabled="busy \|\| classification!==application\.classification"[^>]*@change="upload\(d\.id,\$event\)"/)
  assert.match(view, /requirements\.some\(d=>d\.required&&!d\.attached&&!d\.reusable\)/)
  assert.match(view, /Save your classification, then return to Student info to review the refreshed document requirements before submitting/)
  assert.match(view, /version:application\.value\.version/)
  assert.match(view, /confirmed:true/)
  assert.match(view, /accept="\.pdf,\.jpg,\.jpeg,\.png"/)
  assert.match(view, /const hasUnsavedClassification=computed/)
  assert.match(view, /const hasUnsavedDetails=computed/)
  assert.match(view, /onBeforeRouteLeave\(\(\)=>confirmDraftLeave\(\)\)/)
  assert.match(view, /beforeunload/)
  assert.match(view, /Leave without saving your enrollment draft changes/)
})

test('reference styling is Website-scoped and responsive without altering shared platform presentation', async () => {
  const css = await read('src/modules/enrollment/enrollment.css')
  assert.match(css, /html\.website-ui-2-client \.enrollment-workspace \.enrollment-reference-header/)
  assert.match(css, /\.enrollment-reference-classifications \{[\s\S]*grid-template-columns: repeat\(4, minmax\(0, 1fr\)\)/)
  assert.match(css, /@media \(max-width: 1024px\)[\s\S]*\.enrollment-reference-classifications/)
  assert.match(css, /@media \(max-width: 768px\)[\s\S]*\.enrollment-reference-status/)
  assert.match(css, /@media \(max-width: 520px\)[\s\S]*\.enrollment-reference-classifications/)
  const referenceRules = css.slice(css.indexOf('/* Enrollment UI 2.0: collaborator-reference hierarchy'))
  const selectorLines = referenceRules.split('\n').filter(line => line.includes('.enrollment-reference') && line.includes('{'))
  assert.ok(selectorLines.length > 20)
  assert.ok(selectorLines.every(line => line.trim().startsWith('html.website-ui-2-client')))
})

test('Professor Grading remains at the frozen Phase 5.2 baseline', async () => {
  const finalization = await read('../docs/UI_2_PROFESSOR_GRADING_FINALIZATION.md')
  assert.match(finalization, /Feature Complete — Pending Final Manual UAT/)
  assert.match(finalization, /Feature-freeze declaration/)
})
