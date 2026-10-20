import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const source = async (path) => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('student, professor, and Admin dashboard routes use their live dashboard views', async () => {
  const router = await source('src/router/index.js')

  assert.match(router, /const StudentDashboardView = .*modules\/dashboard\/StudentDashboardView\.vue/)
  assert.match(router, /const ProfessorDashboardView = .*modules\/dashboard\/ProfessorDashboardView\.vue/)
  assert.match(router, /const AdminDashboardView = .*modules\/dashboard\/AdminDashboardView\.vue/)
  assert.match(router, /path: 'student-dashboard',[\s\S]*?component: StudentDashboardView/)
  assert.match(router, /path: 'professor-dashboard',[\s\S]*?component: ProfessorDashboardView/)
  assert.match(router, /path: 'admin-dashboard',[\s\S]*?component: AdminDashboardView/)
})

test('Admin dashboard presents real workflow summaries and operational links', async () => {
  const view = await source('src/modules/dashboard/AdminDashboardView.vue')
  const service = await source('src/modules/dashboard/dashboardService.js')
  const access = await source('src/config/accessControl.js')
  const css = await source('src/modules/dashboard/adminDashboard.css')

  for (const field of ['active_students', 'active_professors', 'registrar_staff_accounts', 'guest_accounts', 'pending_admission_applications', 'current_term_enrollments', 'pending_administrative_actions', 'published_or_ongoing_events']) {
    assert.match(view, new RegExp(field))
  }
  for (const route of ['registrar-management', 'registrar-admissions', 'enrollment-applications', 'monitoring', 'student-management', 'event-attendance']) {
    assert.match(view, new RegExp(`name: '${route}'`))
  }
  assert.match(view, /timeZone: 'Asia\/Manila'/)
  assert.match(view, /role="img" aria-label="Admission application counts by status"/)
  assert.match(view, /No current Enrollment period is configured/)
  assert.match(view, /dashboard\.enrollment\.activity\.academic_enrollments/)
  assert.match(view, /role="alert"/)
  assert.match(service, /getDashboard\('\/admin\/dashboard'\)/)
  assert.match(access, /\[ROLES\.ADMIN\]: '\/admin-dashboard'/)
  assert.match(css, /@media \(max-width: 900px\)/)
  assert.match(css, /@media \(max-width: 560px\)/)
})

test('student dashboard presents live identity, summary, empty state, and real portal links', async () => {
  const view = await source('src/modules/dashboard/StudentDashboardView.vue')
  const service = await source('src/modules/dashboard/dashboardService.js')

  for (const field of ['student_number', 'program_code', 'year_level', 'section', 'enrollment_status', 'academic_term', 'enrolled_subjects', 'unread_monitoring_notices', 'upcoming_events']) {
    assert.match(view, new RegExp(field))
  }
  for (const route of ['enrollment-status', 'enrollment-subjects', 'enrollment-schedule', 'grading', 'monitoring', 'student-document-requests', 'event-attendance']) {
    assert.match(view, new RegExp(`name: '${route}'`))
  }
  assert.match(view, /No finalized enrollment is available yet/)
  assert.match(view, /Use the Mobile app for Event participation and check-in/)
  assert.match(view, /clientPlatform === 'mobile'/)
  assert.match(service, /getDashboard\('\/student\/dashboard'\)/)
})

test('student dashboard promotes only safe published Event data after the academic summary', async () => {
  const view = await source('src/modules/dashboard/StudentDashboardView.vue')
  const summaryIndex = view.indexOf('student-summary-title')
  const eventsIndex = view.indexOf('student-events-title')
  const guideIndex = view.indexOf('student-guide-title')

  assert.ok(summaryIndex < eventsIndex && eventsIndex < guideIndex)
  assert.match(view, /eventService\.promotions\(\{ per_page: 5 \}\)/)
  assert.match(view, /Upcoming at Colegio de Montalban/)
  assert.match(view, /event\.image_url/)
  for (const field of ['title', 'description', 'starts_at', 'venue', 'public_audience', 'status']) {
    assert.match(view, new RegExp(`(?:event|activeEvent)\\.${field}`))
  }
  assert.match(view, /No upcoming Events are currently posted/)
  assert.match(view, /View all Events/)
  assert.match(view, /View Event/)
  assert.match(view, /name: 'event-attendance'/)
  assert.match(view, /Events shown here are public promotions/)
  assert.match(view, /clientPlatform !== 'web'/)
  assert.match(view, /activeEvent/)
  assert.match(view, /aria-label="Previous event"/)
  assert.match(view, /aria-label="Next event"/)
  assert.match(view, /events\.length > 1/)
  assert.match(view, /`Go to event \$\{index \+ 1\}`/)
  assert.match(view, /@pointerdown="beginPointer"/)
  assert.match(view, /@pointerup="finishPointer"/)
  assert.match(view, /setPointerCapture/)
  assert.match(view, /carouselCardState/)
  assert.match(view, /v-for="\(event, index\) in events"/)
  assert.match(view, /eventState\(index\)\.startsWith\('hidden'\)/)
  assert.match(view, /@click="activateSideEvent\(index\)"/)
  assert.match(view, /\['previous', 'next'\]\.includes\(eventState\(index\)\)/)
  assert.match(view, /class="event-feature-copy"/)
  assert.match(view, /cdmLogo/)
  assert.doesNotMatch(view, /attendanceToken|participantQr|operatorScan|manualAttendance|personnel|EventAttendancePanel|QR/)
})

test('student sidebar follows the academic journey and retains Admission history access below services', async () => {
  const navigation = await source('src/stores/navigation.js')
  const order = [
    "'student-dashboard'",
    "'enrollment'",
    "'grading'",
    "'monitoring'",
    "'event-attendance'",
    "'document-requests-menu'",
    "'admission-menu'",
    "'settings'",
  ]
  let previous = -1
  for (const item of order) {
    const current = navigation.indexOf(item, previous + 1)
    assert.ok(current > previous, `${item} should follow the previous Student journey item`)
    previous = current
  }
  assert.match(navigation, /if \(role !== 'Student'\) return items/)
})

test('student top bar shares the green brand family with a distinct depth', async () => {
  const theme = await source('src/assets/styles/student-portal.css')

  assert.match(theme, /\.student-portal-shell \.navbar\s*\{[\s\S]*?linear-gradient\([\s\S]*?--student-cdm-green[\s\S]*?--student-cdm-spring/)
  assert.match(theme, /border-bottom-color:[^;]*--student-cdm-yellow/)
  assert.match(theme, /\.student-portal-shell \.navbar-label\s*\{[\s\S]*?--student-cdm-yellow/)
  assert.match(theme, /\.student-portal-shell \.navbar-title h1\s*\{[\s\S]*?--student-cdm-white/)
  assert.doesNotMatch(theme, /\.student-portal-shell \.navbar\s*\{[^}]*background:\s*#(?:000|fff)/i)
})

test('professor dashboard guides the existing teaching workflow and shows scoped summaries', async () => {
  const view = await source('src/modules/dashboard/ProfessorDashboardView.vue')
  const service = await source('src/modules/dashboard/dashboardService.js')

  for (const field of ['faculty_id', 'department', 'assigned_classes', 'students', 'grade_sheets_needing_work', 'submitted_grade_sheets', 'monitoring_alerts']) {
    assert.match(view, new RegExp(field))
  }
  for (const route of ['enrollment-teaching', 'grading', 'monitoring']) {
    assert.match(view, new RegExp(`name: '${route}'`))
  }
  assert.match(view, /No classes have been assigned yet/)
  assert.match(view, /available in the Mobile app/)
  assert.doesNotMatch(view, /name: 'event-attendance'/)
  assert.match(service, /getDashboard\('\/professor\/dashboard'\)/)
})

test('guide dashboards use semantic tokens and responsive zero-minimum grids', async () => {
  const css = await source('src/modules/dashboard/guideDashboard.css')

  for (const token of ['--color-dartmouth-green', '--color-dark-spring-green', '--color-naples-yellow', '--bg-surface', '--bg-surface-alt', '--text-primary', '--text-secondary', '--border-color']) {
    assert.match(css, new RegExp(`var\\(${token}\\)`))
  }
  assert.match(css, /repeat\(4, minmax\(0, 1fr\)\)/)
  assert.match(css, /repeat\(2, minmax\(0, 1fr\)\)/)
  assert.match(css, /\.event-carousel-stage\s*\{[^}]*overflow: hidden;[^}]*touch-action: pan-y;/s)
  assert.match(css, /\.dashboard-events\s*\{[^}]*background: transparent;[^}]*border: 0;/s)
  assert.match(css, /\.event-carousel-card\s*\{[^}]*position: absolute;[^}]*width: min\(58%, 46rem\)/s)
  assert.match(css, /\.event-carousel-card\.is-active[^}]*translate\(-50%, -50%\) scale\(1\)/)
  assert.match(css, /\.event-carousel-card\.is-previous[^}]*translate\(-118%, -50%\) scale\(0\.82\)/)
  assert.match(css, /\.event-carousel-card\.is-next[^}]*translate\(18%, -50%\) scale\(0\.82\)/)
  assert.match(css, /\.event-carousel-card\.is-hidden-left,[\s\S]*?pointer-events: none/)
  assert.match(css, /440ms cubic-bezier/)
  assert.match(css, /\.event-feature-copy/)
  assert.match(css, /grid-template-columns: minmax\(0, 1fr\)/)
  assert.match(css, /min-width: 0/)
  assert.match(css, /prefers-reduced-motion: reduce/)
  assert.doesNotMatch(css, /#(?:000|fff)(?:\W|$)/i)
})

test('both dashboards expose loading and recoverable error states', async () => {
  for (const file of ['StudentDashboardView.vue', 'ProfessorDashboardView.vue']) {
    const view = await source(`src/modules/dashboard/${file}`)
    assert.match(view, /loading\.value = true/)
    assert.match(view, /role="alert"/)
    assert.match(view, /Try again/)
    assert.match(view, /@click="loadDashboard"/)
  }
})
