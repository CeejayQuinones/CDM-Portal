import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { compileScript, compileStyle, parse } from '@vue/compiler-sfc'

const source = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('role dashboards compile with the same authorized data and a bento command area', async () => {
  for (const role of ['Student', 'Guest', 'Professor']) {
    const text = await source(`src/modules/dashboard/${role}WebsiteDashboard.vue`)
    const { descriptor, errors } = parse(text)
    assert.deepEqual(errors, [])
    assert.doesNotThrow(() => compileScript(descriptor, { id: `${role}-step7`, inlineTemplate: true }))
    assert.match(text, /class="[^"]*dashboard-command-grid[^"]*"/)
    assert.match(text, /WebsiteStatCard/)
    assert.match(text, /WebsiteQuickAction/)
    assert.match(text, /WebsiteActivityPanel/)
  }
  const student = await source('src/modules/dashboard/StudentWebsiteDashboard.vue')
  const guest = await source('src/modules/dashboard/GuestWebsiteDashboard.vue')
  assert.match(student, /dashboard\.summary\.enrollment_status/)
  assert.match(student, /notice\.kind === 'official' && notice\.verified/)
  assert.match(guest, /guestDashboardActionAvailability\(props\.workflow, props\.admitted\)/)
})

test('website-only interiors retain source-driven next actions and reduced-motion safety', async () => {
  const [enrollment, admission, interiors, dashboard, shell] = await Promise.all([
    source('src/modules/enrollment/EnrollmentStudent.vue'),
    source('src/modules/admission/AdmissionView.vue'),
    source('src/assets/styles/website-academic-modules.css'),
    source('src/modules/dashboard/websiteDashboard.css'),
    source('src/layouts/WebsiteAppShell.vue'),
  ])
  assert.match(enrollment, /class="en-actions enrollment-next-action"/)
  assert.match(enrollment, /current\.status === 'draft'/)
  assert.match(enrollment, /data\.eligible && data\.applications_enabled/)
  assert.match(admission, /application-content-grid': isWebsitePresentation/)
  assert.match(admission, /nextStep\.text/)
  assert.match(interiors, /\.application-content-grid \{ display: grid; grid-template-columns:/)
  assert.match(interiors, /html\.website-ui-2-client \.website-ui-2-shell \.grading-table-wrap th \{ position: sticky/)
  assert.match(interiors, /\.grading-table-wrap tbody tr:focus-within td/)
  assert.match(interiors, /prefers-reduced-motion: reduce/)
  assert.match(dashboard, /\.dashboard-command-grid/)
  assert.match(shell, /website-route-enter-active,\.website-route-leave-active \{ transition: opacity 150ms ease/)
  assert.deepEqual(compileStyle({ source: interiors, filename: 'website-academic-modules.css', id: 'step7' }).errors, [])
})
