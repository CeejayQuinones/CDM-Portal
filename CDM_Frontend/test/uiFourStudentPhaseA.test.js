import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = file => readFile(new URL(`../src/${file}`, import.meta.url), 'utf8')

test('UI 4 Phase A keeps the academic journey Website Student-only', async () => {
  const [journey, routes, records, grades] = await Promise.all([
    read('components/website/WebsiteAcademicJourneyNav.vue'),
    read('config/accessControl.js'),
    read('modules/enrollment/EnrollmentAcademicStudent.vue'),
    read('modules/grading/StudentGradesView.vue'),
  ])
  assert.match(journey, /clientPlatform === 'web' && auth\.isAuthenticated && auth\.currentRole === ROLES\.STUDENT/)
  for (const name of ['enrollment-status', 'enrollment-courses', 'enrollment-steps', 'enrollment-subjects', 'enrollment-schedule', 'enrollment-cor', 'grading']) {
    assert.ok(journey.includes(`name: '${name}'`), `${name} is missing from the journey`)
  }
  assert.match(journey, /:aria-current=/)
  assert.match(routes, /ROLES\.STUDENT/)
  assert.match(records, /WebsiteAcademicRecordCard/)
  assert.match(records, /\/enrollment\/academic\/records/)
  assert.match(grades, /Read-only record/)
  assert.match(grades, /gradingService\.studentGrades/)
})

test('UI 4 Phase A record and schedule styling is semantic, responsive, and isolated', async () => {
  const [recordCard, schedule, cor, tokens] = await Promise.all([
    read('components/website/WebsiteAcademicRecordCard.vue'),
    read('modules/enrollment/EnrollmentSchedule.vue'),
    read('modules/enrollment/EnrollmentCor.vue'),
    read('assets/styles/website-ui-2.css'),
  ])
  assert.match(recordCard, /<style scoped>/)
  assert.match(recordCard, /--website-card/)
  assert.match(recordCard, /@media \(max-width: 640px\)/)
  assert.match(schedule, /v-if="isWebsitePresentation"/)
  assert.match(schedule, /<template v-else>/)
  assert.match(schedule, /Time pending/)
  assert.match(schedule, /Not assigned/)
  assert.match(cor, /Print COR/)
  assert.match(tokens, /--website-success-border/)
  assert.match(tokens, /prefers-color-scheme: dark/)
})
