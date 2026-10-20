import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = (path) => readFile(new URL(path, import.meta.url), 'utf8')

test('Event operational workspace separates Desktop administration from Mobile participation', async () => {
  const source = await read('../src/modules/event-attendance/EventAttendanceView.vue')
  assert.match(source, /clientPlatform === 'desktop'/)
  assert.match(source, /Event Administration/)
  assert.match(source, /Event participation/)
  assert.match(source, /Create Event/)
  assert.match(source, /eventDate/)
  assert.match(source, /audience\.value/)
  assert.match(source, /calendarCursor/)
  assert.match(source, /Previous month/)
  assert.match(source, /aria-modal="true"/)
  assert.match(source, /Today:/)
  assert.match(source, /'Happening now':/)
  assert.match(source, /Upcoming:/)
  assert.match(source, /Past:/)
  assert.match(source, /eventService\.transition/)
  assert.match(source, /saving\.value/) // repeat submission guard
  assert.match(source, /No Events found/)
  assert.match(source, /Loading Events/)
  assert.match(source, /role="alert"/)
  assert.match(source, /toEventDateTimeInput\(event\.starts_at\)/)
  assert.match(source, /starts_at: toEventApiDateTime\(form\.value\.starts_at\)/)
  assert.match(source, /eventDateKey\(event\.starts_at\)/)
  assert.doesNotMatch(source, /getTimezoneOffset\(\)/)
  assert.match(source, /Refresh Events/)
  assert.match(source, /App\.addListener\('appStateChange'/)
  assert.match(source, /if \(isActive\) refreshWorkspace\(\)/)
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
  assert.match(source, /can_scan_event_qr/)
  assert.match(source, /can_generate_participant_qr/)
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
  const css = await read('../src/modules/event-attendance/event.css')
  assert.match(workspace, /can_scan_static_participant_qr/)
  assert.match(workspace, /can_scan_dynamic_participant_qr/)
  assert.match(participant, /Show Static Participant QR/)
  assert.match(participant, /Show Dynamic Participant QR/)
  assert.match(participant, /Approved Moderators scan Static Participant QR/)
  assert.match(participant, /Event Staff scan Dynamic Participant QR/)
  assert.match(operator, /Scan the Participant QR shown on an attendee’s device/)
  assert.match(operator, /operatorScan/)
  assert.doesNotMatch(operator, /mode = ref/)
  assert.doesNotMatch(operator, /workflow: mode/)
  assert.match(operator, /Html5Qrcode\.getCameras\(\)/)
  assert.match(operator, /readyState === 'live'/)
  assert.match(operator, /typeof token === 'string' \? token\.trim\(\) : ''/)
  assert.match(operator, /scanner\?\.clear\(\)/)
  assert.match(operator, /Attendance recorded\./)
  assert.match(operator, /finally \{ await stop\(\); busy\.value = false \}/)
  assert.match(css, /\.operator-access-card \{[^}]*display: flex/)
  assert.match(css, /\.operator-access-card \{ align-items: stretch; flex-direction: column \}/)
  assert.match(css, /\.event-modal-body \{[^}]*overflow-y: auto/)
})

test('participant QR camera and image imports share token-based workflow detection and preserve server errors', async () => {
  const operator = await read('../src/modules/event-attendance/EventOperatorScanner.vue')
  const participant = await read('../src/modules/event-attendance/EventParticipantQr.vue')
  const service = await read('../src/modules/event-attendance/eventService.js')
  assert.match(participant, /QRCode\.toDataURL\(result\.token/)
  assert.match(operator, /await submit\(await scanner\.scanFile\(file, false\)\)/)
  assert.match(operator, /scanner\.start\([\s\S]*submit/)
  assert.match(service, /operatorScan\(id, token, action = 'auto'\)/)
  assert.match(service, /operator-scan`, \{ token, action \}/)
  assert.match(operator, /error\.value = eventErrorMessage\(cause\)/)
})

test('Student and operator screens render only their server-authorized QR tools and label incompatible QR types', async () => {
  const workspace = await read('../src/modules/event-attendance/EventAttendanceView.vue')
  const studentScanner = await read('../src/modules/event-attendance/EventQrScanner.vue')
  const operatorScanner = await read('../src/modules/event-attendance/EventOperatorScanner.vue')
  assert.match(workspace, /eventSelfScanEnabled = import\.meta\.env\.VITE_EVENT_SELF_SCAN_ENABLED === 'true'/)
  assert.match(workspace, /EventQrScanner v-if="eventSelfScanEnabled && selected\.capabilities\?\.can_scan_event_qr/)
  assert.match(workspace, /EventQrScanner v-if="eventSelfScanEnabled && attendanceTab === 'scan' && selected\.capabilities\?\.can_scan_event_qr/)
  assert.match(workspace, /EventParticipantQr v-if="selected\.capabilities\?\.can_generate_participant_qr/)
  assert.match(workspace, /EventOperatorScanner v-if="attendanceTab === 'scan' && \(selected\.capabilities\?\.can_scan_static_participant_qr \|\| selected\.capabilities\?\.can_scan_dynamic_participant_qr\)/)
  assert.match(workspace, /v-if="canScanParticipantQr"/)
  assert.match(workspace, /@click="openParticipantScanner">Scan Participant QR/)
  assert.doesNotMatch(workspace, /EventOperatorScanner[^>]+can_self_scan/)
  assert.match(studentScanner, /Scan Event Attendance QR/)
  assert.match(studentScanner, /Do not scan your own Participant QR here/)
  assert.match(studentScanner, /WRONG_QR_TYPE/)
  assert.match(operatorScanner, /Event QRs displayed by organizers are not accepted here/)
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
  const workspace = await read('../src/modules/event-attendance/EventAttendanceView.vue')
  const eventNavigation = access.match(/\{\s*name: 'event-attendance',[\s\S]*?activeRoutes: \['event-attendance', 'event-attendance-reports'\],[\s\S]*?\n\s*\}/)?.[0] || ''
  assert.match(access, /label: 'Events'.*event-attendance/s)
  assert.doesNotMatch(access, /event-attendance-tracking/)
  assert.match(eventNavigation, /activeRoutes: \['event-attendance', 'event-attendance-reports'\]/)
  assert.doesNotMatch(eventNavigation, /children:|label: '(Dashboard|QR Scanner|Event Management|Participant Management|Reports)'/)
  assert.match(sidebar, /isItemActive/)
  assert.match(workspace, /key: 'reports'.*visible: canViewReports\.value/)
  assert.match(workspace, /<EventReportsView v-if="area === 'reports'" embedded/)
})

test('Event reports expose filters, summaries, exports, responsive states, and sub-event aggregation', async () => {
  const source = await read('../src/modules/event-attendance/EventReportsView.vue')
  const service = await read('../src/modules/event-attendance/eventService.js')
  const css = await read('../src/modules/event-attendance/event.css')
  assert.match(source, /Event Reports/)
  assert.match(source, /Search Event/)
  assert.match(source, /Attendance Rate/)
  assert.match(source, /Export CSV/)
  assert.match(source, /Export Excel/)
  assert.match(source, /Export PDF/)
  assert.match(source, /completion_rate/)
  assert.match(source, /report\.summary/)
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

test('Phase 2B certificate configuration and participant downloads stay inside Event details', async () => {
  const workspace = await read('../src/modules/event-attendance/EventAttendanceView.vue')
  const service = await read('../src/modules/event-attendance/eventService.js')
  assert.match(workspace, /Participation certificates/)
  assert.match(workspace, /required_event_ids/)
  assert.match(workspace, /selected\.my_certificate/)
  assert.match(workspace, /Download Certificate/)
  assert.match(workspace, /eventService\.configureCertificates/)
  assert.match(workspace, /eventService\.downloadCertificate/)
  assert.match(service, /certificate-settings/)
  assert.match(service, /certificate\/download/)
  assert.doesNotMatch(workspace, /key: 'certificates'/)
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
  assert.match(source, /availability\?\.message/)
  assert.match(source, /Start Attendance/)
  assert.match(source, /:disabled="busy \|\| !canStart"/)
  assert.match(source, /Event:.*Published/)
  assert.match(source, /Philippine time/)
})

test('Student scanner handles camera, image fallback, success, duplicate, and scan errors', async () => {
  const source = await read('../src/modules/event-attendance/EventQrScanner.vue')
  assert.match(source, /import\('html5-qrcode'\)/)
  assert.match(source, /Html5Qrcode\.getCameras\(\)/)
  assert.match(source, /facingMode: 'environment'/)
  assert.match(source, /getVideoTracks/)
  assert.match(source, /readyState === 'live'/)
  assert.match(source, /typeof token === 'string' \? token\.trim\(\) : ''/)
  assert.match(source, /Camera permission was denied/)
  assert.match(source, /Camera is unavailable/)
  assert.match(source, /Choose Event QR Image/)
  assert.match(source, /ATTENDANCE_RECORDED/)
  assert.match(source, /ALREADY_RECORDED/)
  assert.match(source, /EXPIRED_TOKEN/)
  assert.match(source, /scanner\?\.clear\(\)/)
  assert.match(source, /event\.target\.value = ''/)
  assert.match(source, /Starting camera…/)
})

test('Event presentation uses theme tokens, responsive layout, and reduced motion', async () => {
  const css = await read('../src/modules/event-attendance/event.css')
  assert.match(css, /var\(--bg-surface\)/)
  assert.match(css, /@media \(max-width: 768px\)/)
  assert.match(css, /\.event-toolbar label \{ flex: 0 0 auto;/)
  assert.match(css, /prefers-reduced-motion/)
})

test('Event transition feedback uses correct past-tense labels', async () => {
  const source = await read('../src/modules/event-attendance/EventAttendanceView.vue')
  assert.match(source, /archive:\s*'archived'/)
  assert.doesNotMatch(source, /`\$\{action\}ed`/)
})

test('Event workspace provides a real month calendar and role-aware workflow navigation', async () => {
  const workspace = await read('../src/modules/event-attendance/EventAttendanceView.vue')
  const access = await read('../src/config/accessControl.js')
  assert.match(workspace, /calendarDays/)
  assert.match(workspace, /length: 42/)
  assert.match(workspace, /calendar-weekdays/)
  assert.match(workspace, />Today</)
  assert.match(workspace, /current-day|is-today/)
  for (const label of ['Overview', 'Calendar', 'Manage', 'Attendance', 'People', 'Notifications', 'Reports']) {
    assert.ok(workspace.includes(label), `missing workspace entry ${label}`)
  }
  assert.match(workspace, /visible: canManageWorkspace\.value/)
  assert.match(workspace, /visible: canAttendWorkspace\.value/)
  assert.match(workspace, /visible: canViewPeopleWorkspace\.value/)
  assert.match(workspace, /\['scan', 'Scan QR'\]/)
  assert.match(workspace, /\['manual', 'Manual'\]/)
  assert.match(workspace, /\['records', 'Records'\]/)
  assert.match(workspace, /\['corrections', 'Corrections'\]/)
  assert.match(workspace, /\[\['participants','Participants'\],\['moderators','Moderators'\],\['staff','Staff'\],\['coordinators','Coordinators'\]\]/)
  assert.match(workspace, /My Event status and QR/)
  assert.doesNotMatch(access, /event-dashboard|event-calendar|event-scanner|event-management|event-participants|event-notifications|event-profile/)
})

test('Moderator requests, participant profile, notifications, and QR download use authenticated Event APIs', async () => {
  const requests = await read('../src/modules/event-attendance/EventModeratorRequestsPanel.vue')
  const profile = await read('../src/modules/event-attendance/EventProfilePanel.vue')
  const notifications = await read('../src/modules/event-attendance/EventNotificationsPanel.vue')
  const participantQr = await read('../src/modules/event-attendance/EventParticipantQr.vue')
  const service = await read('../src/modules/event-attendance/eventService.js')
  assert.match(requests, /Request Moderator Access/)
  assert.match(requests, /Approve/)
  assert.match(requests, /Reject/)
  assert.match(requests, /Revoke/)
  assert.match(profile, /Attended Events/)
  assert.match(profile, /My Event Status/)
  assert.match(profile, /event_responsibility_label/)
  assert.match(profile, /eligibleRequestEvents/)
  assert.match(profile, /Request Moderator Access/)
  assert.match(profile, /Reason <small>\(optional\)<\/small>/)
  assert.match(profile, /<Teleport to="body">/)
  assert.match(requests, />View</)
  assert.match(notifications, /unread/)
  assert.match(participantQr, /Save Static QR/)
  assert.match(participantQr, /Capacitor\.isNativePlatform\(\)/)
  assert.match(participantQr, /Filesystem\.writeFile/)
  assert.match(participantQr, /Share\.share/)
  assert.match(participantQr, /Dynamic QR codes are display-only/)
  assert.match(service, /moderator-requests/)
  assert.match(service, /events\/profile/)
  assert.match(service, /events\/notifications/)
})

test('Attendance management uses server-side participant pagination', async () => {
  const attendance = await read('../src/modules/event-attendance/EventAttendancePanel.vue')
  assert.match(attendance, /per_page: 25/)
  assert.match(attendance, /state\.students\.last_page/)
  assert.match(attendance, /changePage/)
  assert.match(attendance, /Verification/)
})

test('Phase 2A exposes sub-event policy and safe archival inside the existing workspace', async () => {
  const workspace = await read('../src/modules/event-attendance/EventAttendanceView.vue')
  const service = await read('../src/modules/event-attendance/eventService.js')
  assert.match(workspace, /Add Sub-event/)
  assert.match(workspace, /Display order/)
  assert.match(workspace, /Sub-events inherit parent Event audience/)
  assert.match(workspace, /Attendance configuration/)
  assert.match(workspace, /check_in_and_out/)
  assert.match(workspace, /minimum_participation_minutes/)
  assert.match(workspace, /static_event/)
  assert.match(workspace, /archiveSubEvent/)
  assert.match(service, /delete\(`\/events\/\$\{id\}`/)
})

test('Phase 2A attendance UI distinguishes Event and participant QR workflows', async () => {
  const panel = await read('../src/modules/event-attendance/EventAttendancePanel.vue')
  const scanner = await read('../src/modules/event-attendance/EventQrScanner.vue')
  const operator = await read('../src/modules/event-attendance/EventOperatorScanner.vue')
  const service = await read('../src/modules/event-attendance/eventService.js')
  assert.match(panel, /Dynamic QR/)
  assert.match(panel, /Static QR/)
  assert.match(panel, /Pending confirmations/)
  assert.match(panel, /reviewAttendanceVerification/)
  assert.match(panel, /checkoutAttendance/)
  assert.match(scanner, /VERIFICATION_PENDING/)
  assert.match(operator, /Check-out/)
  assert.match(service, /attendance\/static-token/)
  assert.match(service, /attendance\/verifications/)
  assert.match(service, /attendance\/\$\{attendanceId\}\/checkout/)
})

test('Event modals escape the application stacking context and scroll internally', async () => {
  const [workspace, reports, promotion, lock, css] = await Promise.all([
    read('../src/modules/event-attendance/EventAttendanceView.vue'),
    read('../src/modules/event-attendance/EventReportsView.vue'),
    read('../src/modules/event-attendance/EventPromotionView.vue'),
    read('../src/modules/event-attendance/useEventModalScrollLock.js'),
    read('../src/modules/event-attendance/event.css'),
  ])
  for (const source of [workspace, reports, promotion]) {
    assert.match(source, /<Teleport to="body">/)
    assert.match(source, /event-modal-body/)
    assert.match(source, /useEventModalScrollLock/)
  }
  assert.match(lock, /document\.documentElement\.classList/)
  assert.match(lock, /document\.body\.classList/)
  assert.match(css, /\.event-modal-backdrop \{[^}]*inset: 0;[^}]*position: fixed;[^}]*z-index: 1200/)
  assert.match(css, /\.event-modal \{[^}]*display: flex;[^}]*flex-direction: column;[^}]*overflow: hidden/)
  assert.match(css, /\.event-modal-body \{[^}]*min-height: 0;[^}]*overflow-x: hidden;[^}]*overflow-y: auto/)
  assert.match(css, /body\.event-modal-open \{ overflow: hidden \}/)
})

test('Android Event integration declares camera permission and closes dialogs with hardware Back', async () => {
  const [manifest, workspace] = await Promise.all([
    read('../android/app/src/main/AndroidManifest.xml'),
    read('../src/modules/event-attendance/EventAttendanceView.vue'),
  ])
  assert.match(manifest, /android\.permission\.CAMERA/)
  assert.match(workspace, /App\.addListener\('backButton'/)
  assert.match(workspace, /modal\.value = ''/)
  assert.match(workspace, /backButtonListener\?\.remove\(\)/)
})
