import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = (path) => readFile(new URL(path, import.meta.url), 'utf8')

test('Event workspace exposes staff management and audience read-only states', async () => {
  const source = await read('../src/modules/event-attendance/EventAttendanceView.vue')
  assert.match(source, /\['Admin', 'Registrar Staff'\]/)
  assert.match(source, /Create Event/)
  assert.match(source, /eventDate/)
  assert.match(source, /audience\.value/)
  assert.match(source, /calendarCursor/)
  assert.match(source, /Previous month/)
  assert.match(source, /aria-modal="true"/)
  assert.match(source, /Today:/)
  assert.match(source, /Upcoming:/)
  assert.match(source, /Past:/)
  assert.match(source, /eventService\.transition/)
  assert.match(source, /saving\.value/) // repeat submission guard
  assert.match(source, /No events found/)
  assert.match(source, /Loading events/)
  assert.match(source, /role="alert"/)
})

test('Event service uses authenticated shared API endpoints', async () => {
  const source = await read('../src/modules/event-attendance/eventService.js')
  for (const endpoint of ["get('/events'", "get('/events/options'", "post('/events'", "put(`/events/${id}`", "post(`/events/${id}/${action}`"]) {
    assert.ok(source.includes(endpoint), `missing ${endpoint}`)
  }
  assert.doesNotMatch(source, /fetch\(/)
})

test('Event navigation enables Phase 2 attendance and keeps Phase 3 reports disabled', async () => {
  const access = await read('../src/config/accessControl.js')
  const sidebar = await read('../src/components/Sidebar.vue')
  assert.match(access, /label: 'Events'.*event-attendance/s)
  assert.match(access, /event-attendance-tracking'.*label: 'Attendance'/s)
  assert.doesNotMatch(access, /event-attendance-tracking'.*comingSoon: true/)
  assert.match(access, /label: 'Reports'.*comingSoon: true.*Phase 3/s)
  assert.match(sidebar, /aria-disabled="true"/)
})

test('Registrar attendance panel renders rotating QR, live state, close, table, and corrections', async () => {
  const source = await read('../src/modules/event-attendance/EventAttendancePanel.vue')
  assert.match(source, /QRCode\.toDataURL/)
  assert.match(source, /refresh_after_seconds/)
  assert.match(source, /setInterval\(\(\) =>.*7000/s)
  assert.match(source, /Start Attendance/)
  assert.match(source, /Close Attendance/)
  assert.match(source, /Not checked in/)
  assert.match(source, /Correct Attendance/)
  assert.match(source, /runWithStepUp/)
})

test('Student scanner handles camera, image fallback, success, duplicate, and scan errors', async () => {
  const source = await read('../src/modules/event-attendance/EventQrScanner.vue')
  assert.match(source, /import\('html5-qrcode'\)/)
  assert.match(source, /facingMode: 'environment'/)
  assert.match(source, /Camera permission was denied/)
  assert.match(source, /Camera is unavailable/)
  assert.match(source, /Choose QR Image/)
  assert.match(source, /ATTENDANCE_RECORDED/)
  assert.match(source, /ALREADY_RECORDED/)
  assert.match(source, /EXPIRED_TOKEN/)
})

test('Event presentation uses theme tokens, responsive layout, and reduced motion', async () => {
  const css = await read('../src/modules/event-attendance/event.css')
  assert.match(css, /var\(--bg-surface\)/)
  assert.match(css, /@media \(max-width: 700px\)/)
  assert.match(css, /prefers-reduced-motion/)
})
