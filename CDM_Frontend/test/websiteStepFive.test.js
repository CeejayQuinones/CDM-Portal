import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

import { guestDashboardActionAvailability } from '../src/modules/dashboard/dashboardPresentation.js'

const source = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('Guest dashboard offers examination only when the current workflow permits it', () => {
  assert.deepEqual(guestDashboardActionAvailability(), { exam: false, result: false })
  assert.deepEqual(guestDashboardActionAvailability({ exam: { eligible: true } }), { exam: true, result: false })
  assert.deepEqual(guestDashboardActionAvailability({ exam: { session: { exam_completed: false } } }), { exam: true, result: false })
  assert.deepEqual(guestDashboardActionAvailability({ exam: { eligible: true, session: { exam_completed: true } } }), { exam: false, result: false })
  assert.deepEqual(guestDashboardActionAvailability({ exam: { eligible: true, latest_completed: true } }), { exam: false, result: false })
  assert.deepEqual(guestDashboardActionAvailability({ exam: { eligible: true } }, true), { exam: false, result: false })
})

test('Guest dashboard offers result only after official publication', () => {
  assert.deepEqual(guestDashboardActionAvailability({ result: { published: false } }), { exam: false, result: false })
  assert.deepEqual(guestDashboardActionAvailability({ result: { published: true } }), { exam: false, result: true })
})

test('bulk assessment review fields identify their row and purpose to assistive technology', async () => {
  const grading = await source('src/modules/grading/GradingView.vue')
  for (const field of ['label', 'category', 'maximum score', 'display order']) {
    assert.match(grading, new RegExp(`Assessment \\$\\{index \\+ 1\\} ${field}`))
  }
})

test('Step 5 presentation stays behind the website client branch', async () => {
  const [layout, guest, professor] = await Promise.all([
    source('src/layouts/DashboardLayout.vue'),
    source('src/views/GuestDashboardView.vue'),
    source('src/modules/dashboard/ProfessorDashboardView.vue'),
  ])
  assert.match(layout, /<WebsiteAppShell v-if="isWebsitePresentation" \/>/)
  assert.match(guest, /<GuestWebsiteDashboard v-if="isWebsitePresentation"/)
  assert.match(professor, /<ProfessorWebsiteDashboard v-if="isWebsitePresentation"/)
})
