import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = (path) => readFile(new URL(path, import.meta.url), 'utf8')

test('Event operational workspace separates Desktop administration from Mobile participation', async () => {
  const source = await read('../src/modules/event-attendance/EventAttendanceView.vue')
  assert.match(source, /clientPlatform === 'desktop'/)
  assert.match(source, /Event Administration/)
  assert.match(source, /Mobile Event participation/)
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
  assert.match(source, /events\/\$\{id\}\/personnel/)
  assert.match(source, /assignPersonnel/)
  assert.match(source, /updatePersonnel/)
  assert.match(source, /revokePersonnel/)
})

test('Event details separate portal role from scoped responsibility and gate controls by server capabilities', async () => {
  const source = await read('../src/modules/event-attendance/EventAttendanceView.vue')
  assert.match(source, /Portal Role/)
  assert.match(source, /Event Responsibility/)
  assert.match(source, /assignment_inherited/)
  assert.match(source, /can_manage_personnel/)
  assert.match(source, /can_view_personnel/)
  assert.match(source, /can_edit_event/)
  assert.match(source, /can_manage_sub_events/)
  assert.match(source, /can_create_event/)
  assert.match(source, /can_operate_attendance/)
  assert.match(source, /can_self_scan/)
  assert.match(source, /EventPersonnelPanel/)
})

test('Event navigation and actions apply the Event-specific client matrix', async () => {
  const router = await read('../src/router/index.js')
  const navigation = await read('../src/stores/navigation.js')
  const attendance = await read('../src/modules/event-attendance/EventAttendancePanel.vue')
  const personnel = await read('../src/modules/event-attendance/EventPersonnelPanel.vue')
  for (const source of [router, navigation]) {
    assert.match(source, /clientPlatform === 'desktop'/)
    assert.match(source, /clientPlatform === 'web'/)
    assert.match(source, /clientPlatform === 'mobile'/)
    assert.match(source, /Admin.*Professor/s)
    assert.match(source, /Professor.*Student/s)
  }
  assert.match(router, /eventModule/)
  assert.match(attendance, /can_manage_attendance_session/)
  assert.match(attendance, /can_verify_attendance/)
  assert.match(personnel, /canManage/)
  assert.match(personnel, /View only/)
})

test('Web Events render only the promotional surface', async () => {
  const promotion = await read('../src/modules/event-attendance/EventPromotionView.vue')
  const router = await read('../src/router/index.js')
  const service = await read('../src/modules/event-attendance/eventService.js')
  assert.match(router, /clientPlatform === 'web' \? EventPromotionView : EventAttendanceView/)
  assert.match(promotion, /Upcoming Events/)
  assert.match(promotion, /View Details/)
  assert.doesNotMatch(promotion, /EventAttendancePanel|EventPersonnelPanel|EventQrScanner|EventOperatorScanner|Event Reports|Audit/)
  assert.match(service, /event-promotions/)
})

test('Mobile Event UI exposes participant and assignment-specific QR workflows', async () => {
  const workspace = await read('../src/modules/event-attendance/EventAttendanceView.vue')
  const participant = await read('../src/modules/event-attendance/EventParticipantQr.vue')
  const operator = await read('../src/modules/event-attendance/EventOperatorScanner.vue')
  assert.match(workspace, /can_scan_static_participant_qr/)
  assert.match(workspace, /can_scan_dynamic_participant_qr/)
  assert.match(participant, /Show Static QR/)
  assert.match(participant, /Show Dynamic QR/)
  assert.match(operator, /Registered Moderator static QR workflow/)
  assert.match(operator, /Requested Moderator dynamic QR workflow/)
  assert.match(operator, /operatorScan/)
})

test('Authorized Personnel supports safe search, assignment, role changes, revoke, and temporal windows', async () => {
  const source = await read('../src/modules/event-attendance/EventPersonnelPanel.vue')
  for (const text of [
    'Authorized Personnel',
    'Portal Role',
    'Event Responsibility',
    'Assigned By',
    'Assigned At',
    'Assign Personnel',
    'Revoke',
    'Starts \\(optional\\)',
    'Ends \\(optional\\)',
  ]) assert.match(source, new RegExp(text, 'i'))
  assert.match(source, /eventService\.personnel/)
  assert.match(source, /eventService\.assignPersonnel/)
  assert.match(source, /eventService\.updatePersonnel/)
  assert.match(source, /eventService\.revokePersonnel/)
  assert.match(source, /var\(--bg-surface\)/)
  assert.match(source, /var\(--border-color\)/)
  assert.match(source, /@media \(max-width: 700px\)/)
  assert.doesNotMatch(source, /localStorage/)
})

test('Event navigation enables attendance and staff reports', async () => {
  const access = await read('../src/config/accessControl.js')
  const sidebar = await read('../src/components/Sidebar.vue')
  assert.match(access, /label: 'Events'.*event-attendance/s)
  assert.doesNotMatch(access, /event-attendance-tracking/)
  assert.match(access, /label: 'Reports'.*event-attendance\/reports/s)
  assert.doesNotMatch(access, /event-attendance-reports'.*comingSoon: true/)
  assert.match(sidebar, /aria-disabled="true"/)
})

test('Event reports expose filters, summaries, exports, responsive states, and sub-event aggregation', async () => {
  const source = await read('../src/modules/event-attendance/EventReportsView.vue')
  const service = await read('../src/modules/event-attendance/eventService.js')
  const css = await read('../src/modules/event-attendance/event.css')
  assert.match(source, /Event Reports/)
  assert.match(source, /Search Event/)
  assert.match(source, /Attendance Rate/)
  assert.match(source, /Export CSV/)
  assert.match(source, /report\.capabilities\?\.can_export/)
  assert.match(source, /No Event reports found/)
  assert.match(source, /No attendance records found/)
  assert.match(source, /Loading attendance report/)
  assert.match(source, /Sub-event Summary/)
  assert.match(source, /overall_unique_attendees/)
  assert.match(service, /event-reports/)
  assert.match(service, /responseType: 'blob'/)
  assert.match(css, /var\(--bg-surface\)/)
  assert.match(css, /report-row-filters/)
  assert.match(css, /prefers-reduced-motion/)
})

test('Student Event detail shows only the authenticated Student attendance state', async () => {
  const source = await read('../src/modules/event-attendance/EventAttendanceView.vue')
  assert.match(source, /My Attendance/)
  assert.match(source, /selected\.my_attendance/)
  assert.match(source, /Add Sub-event/)
  assert.match(source, /inherit parent Event audience/i)
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
  assert.match(source, /can_manage_attendance_session/)
  assert.match(source, /can_verify_attendance/)
  assert.match(source, /can_correct_attendance/)
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
