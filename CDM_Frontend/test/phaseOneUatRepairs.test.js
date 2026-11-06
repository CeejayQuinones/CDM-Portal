import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

import { formatDashboardStatus } from '../src/modules/dashboard/dashboardPresentation.js'

const source = (path) => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('Section cards preserve complete status labels in the approved directory', async () => {
  const [view, css] = await Promise.all([
    source('src/modules/enrollment/EnrollmentAcademicStaff.vue'),
    source('src/modules/enrollment/registrarEnrollmentComplete.css'),
  ])

  assert.match(view, /class="en-badge" :data-status="section\.status"/)
  assert.match(view, /\{\{ label\(section\.status\) \}\}/)
  assert.match(view, /class="en-section-directory"/)
  assert.match(view, /class="en-section-card-grid"/)
  assert.match(css, /\.en-badge \{[^}]*white-space: nowrap;/)
  assert.match(css, /\.en-section-card-grid \{ display: grid;/)
})

test('Add Professor has independent accessible password controls and modal validation', async () => {
  const [view, css] = await Promise.all([
    source('src/modules/account-management/AccountProvisioningView.vue'),
    source('src/modules/account-management/account-management.css'),
  ])

  assert.match(view, /showInitialPassword = ref\(false\)/)
  assert.match(view, /showConfirmationPassword = ref\(false\)/)
  assert.match(view, /Show'} initial password/)
  assert.match(view, /Show'} password confirmation/)
  assert.match(view, /type="button" :aria-label=.*initial password/)
  assert.match(view, /form\.value\.password !== form\.value\.password_confirmation/)
  assert.match(view, /The password confirmation does not match\./)
  assert.match(view, /formErrors\.value = err\.response\?\.data\?\.errors/)
  assert.match(view, /account-dialog-error[^>]*role="alert"/)
  assert.match(css, /\.account-password-control\s*\{[^}]*position:\s*relative/)
})

test('Subject catalog protects compact records with responsive directory rows', async () => {
  const [view, css] = await Promise.all([
    source('src/modules/enrollment/EnrollmentSubjectManagement.vue'),
    source('src/modules/enrollment/registrarEnrollmentComplete.css'),
  ])

  assert.match(view, /class="en-catalog-directory"/)
  assert.match(view, /class="en-subject-row"/)
  assert.match(view, /class="en-row-menu"/)
  assert.match(css, /\.en-subject-row \{ grid-template-columns:/)
  assert.match(css, /@media \(max-width: 900px\)/)
  assert.match(css, /\.en-subject-name,[\s\S]*grid-column: 1 \/ -1;/)
})

test('Student enrollment statuses are title-cased only for presentation', () => {
  assert.equal(formatDashboardStatus('enrolled'), 'Enrolled')
  assert.equal(formatDashboardStatus('under_review'), 'Under Review')
  assert.equal(formatDashboardStatus('NOT_FINALIZED'), 'Not Finalized')
  assert.equal(formatDashboardStatus(null), 'Not finalized')
})

test('Drawer Settings is teleported above its drawer with focus isolation and restoration', async () => {
  const view = await source('src/modules/student-management/PhysicalRecordsView.vue')

  assert.match(view, /<Teleport to="body">[\s\S]*?v-if="showSlotEdit"[\s\S]*?slot-edit-backdrop[\s\S]*?<\/Teleport>/)
  assert.match(view, /\.slot-edit-backdrop \{[^}]*z-index: 1300;/)
  assert.match(view, /:inert="showSlotEdit"/)
  assert.match(view, /:aria-hidden="showSlotEdit \? 'true' : undefined"/)
  assert.match(view, /@keydown="trapSlotEditFocus"/)
  assert.match(view, /slotEditCloseButton\.value\?\.focus\(\)/)
  assert.match(view, /slotEditTrigger\?\.focus\(\)/)
  assert.match(view, /if \(showSlotEdit\.value\) \{[\s\S]*?closeSlotEdit\(\)/)
})
