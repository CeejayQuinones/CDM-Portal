import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = file => readFile(new URL(`../src/${file}`, import.meta.url), 'utf8')

test('Registrar Applications and Periods are structurally separate workspaces using existing contracts', async () => {
  const [staff, router] = await Promise.all([
    read('modules/enrollment/EnrollmentStaff.vue'),
    read('router/index.js'),
  ])
  assert.match(staff, /ui4-records-panel/)
  assert.match(staff, /ui4-status-tabs/)
  assert.match(staff, /ui4-records-toolbar/)
  assert.match(staff, /ui4-extra-filters/)
  assert.match(staff, /ui4-detail-grid/)
  assert.match(staff, /ui4-year-panel/)
  assert.match(staff, /ui4-window-panel/)
  assert.match(staff, /ui4-form-dialog/)
  assert.doesNotMatch(staff, /aria-label="Enrollment workspace"/)
  assert.match(staff, /\/enrollment\/applications/)
  assert.match(staff, /\/enrollment\/periods/)
  assert.match(staff, /\/enrollment\/academic-years/)
  assert.match(router, /name: 'enrollment-applications'/)
  assert.match(router, /name: 'enrollment-periods'/)
})

test('Registrar controls use scoped desktop light/dark/system tokens and guarded decisions', async () => {
  const [staff, styles, desktop] = await Promise.all([
    read('modules/enrollment/EnrollmentStaff.vue'),
    read('modules/enrollment/registrarEnrollmentUi4.css'),
    read('assets/styles/desktop-ui-3.css'),
  ])
  assert.match(staff, /requestPeriodSave/)
  assert.match(staff, /periodConfirmation/)
  assert.match(staff, /yearConfirmation/)
  assert.match(staff, /confirmation='reject'/)
  assert.match(staff, /\['submitted','under_review'\]/)
  assert.match(staff, /role="region" aria-label="Application results" tabindex="0"/)
  assert.match(styles, /html\.cdm-desktop-client \.ui4-staff/)
  assert.match(styles, /--desk-surface/)
  assert.match(styles, /--desk-line/)
  assert.match(styles, /:focus-visible/)
  assert.match(styles, /prefers-reduced-motion: reduce/)
  assert.match(desktop, /data-student-theme='dark'/)
  assert.match(desktop, /data-student-theme='system'/)
})

test('Academic Enrollment review is schedule-optional and keeps finalization feedback inside its dialog', async () => {
  const load = await read('modules/enrollment/EnrollmentLoad.vue')
  assert.match(load, /isDesktopStaff/)
  assert.match(load, /Schedule not required/)
  assert.match(load, /To be announced/)
  assert.match(load, /Schedule pending/)
  assert.match(load, /Finalize Academic Enrollment/)
  assert.match(load, /Confirm Final Enrollment/)
  assert.match(load, /modalError/)
  assert.match(load, /errorSummary\.value\?\.focus/)
  assert.match(load, /if \(action === 'finalize'\) payload\.confirmed = true/)
  assert.doesNotMatch(load, /Every selected subject needs a schedule/)
  assert.doesNotMatch(load, /payload\.day|payload\.start_time|payload\.end_time|payload\.professor_id|payload\.room/)
})
