import assert from 'node:assert/strict'
import { access, readFile } from 'node:fs/promises'
import test from 'node:test'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('CDM Buddy is limited to Student and Guest dashboards with accessible states', async () => {
  const [buddy, layout, service] = await Promise.all([
    read('src/components/CDMBuddy.vue'),
    read('src/layouts/DashboardLayout.vue'),
    read('src/services/portalNoticeService.js'),
  ])

  assert.match(layout, /import CDMBuddy/)
  assert.match(layout, /<CDMBuddy \/>/)
  assert.match(buddy, /\['student-dashboard', 'guest-dashboard'\]/)
  assert.match(buddy, /aria-expanded="expanded"/)
  assert.match(buddy, /role="dialog"/)
  assert.match(buddy, /event\.key === 'Escape'/)
  assert.match(buddy, /Dismiss for this session/)
  assert.match(buddy, /sessionStorage/)
  assert.match(buddy, /Verified update/)
  assert.match(buddy, /Informational guidance/)
  assert.match(buddy, /@media \(max-width: 760px\)[\s\S]*position: static/)
  assert.match(buddy, /prefers-reduced-motion/)
  assert.match(service, /get\('\/portal-notices'\)/)
})

test('Event application surfaces and imports are removed', async () => {
  const [router, accessControl, navigation, student, admin, login] = await Promise.all([
    read('src/router/index.js'),
    read('src/config/accessControl.js'),
    read('src/stores/navigation.js'),
    read('src/modules/dashboard/StudentDashboardView.vue'),
    read('src/modules/dashboard/AdminDashboardView.vue'),
    read('src/views/LoginView.vue'),
  ])

  for (const source of [router, accessControl, navigation, student, admin, login]) {
    assert.doesNotMatch(source, /event-attendance|event-promotions|EventAttendance|Event Reports|Upcoming Events/)
  }
  await assert.rejects(access(new URL('../src/modules/event-attendance/EventAttendanceView.vue', import.meta.url)))
})
