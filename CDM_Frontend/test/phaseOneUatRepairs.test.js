import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

import { formatDashboardStatus } from '../src/modules/dashboard/dashboardPresentation.js'

const source = (path) => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('Section status badges preserve Open and Closed as intact labels', async () => {
  const [view, css] = await Promise.all([
    source('src/modules/enrollment/EnrollmentAcademicStaff.vue'),
    source('src/modules/enrollment/enrollment.css'),
  ])

  assert.match(view, /'open','closed'/)
  assert.match(view, /class="en-badge" :data-status="r\.status"/)
  assert.match(view, /'en-sections-table': mode === 'sections'/)
  assert.match(css, /\.en-badge \{[^}]*word-break:keep-all;[^}]*white-space:nowrap;/)
  assert.match(css, /\.en-sections-table th:nth-child\(4\)[^}]*min-width:7rem;[^}]*white-space:nowrap;/)
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
  assert.match(css, /\.account-password-control\{[^}]*position:relative/)
})

test('Subjects table protects compact columns and scrolls instead of crushing content', async () => {
  const [view, css] = await Promise.all([
    source('src/modules/enrollment/EnrollmentSubjectManagement.vue'),
    source('src/modules/enrollment/enrollment.css'),
  ])

  assert.match(view, /table class="subject-management-table"/)
  assert.match(css, /\.en-table \{ overflow-x:auto; \}/)
  assert.match(css, /\.subject-management-table \{ min-width:1080px; \}/)
  assert.match(css, /nth-child\(1\)[^}]*white-space:nowrap/)
  assert.match(css, /nth-child\(3\)[^}]*white-space:nowrap/)
  assert.match(css, /nth-child\(6\)[^}]*white-space:nowrap/)
  assert.match(css, /subject-management-table \.en-actions button[^}]*white-space:nowrap/)
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
