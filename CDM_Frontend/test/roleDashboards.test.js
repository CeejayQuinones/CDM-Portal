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

test('Admin dashboard presents real academic workflow summaries and operational links', async () => {
  const view = await source('src/modules/dashboard/AdminDashboardView.vue')
  const service = await source('src/modules/dashboard/dashboardService.js')
  const access = await source('src/config/accessControl.js')
  const css = await source('src/modules/dashboard/adminDashboard.css')

  for (const field of ['active_students', 'active_professors', 'registrar_staff_accounts', 'guest_accounts', 'pending_admission_applications', 'current_term_enrollments', 'pending_administrative_actions']) {
    assert.match(view, new RegExp(field))
  }
  for (const route of ['registrar-management', 'registrar-admissions', 'enrollment-applications', 'student-management']) {
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

  for (const field of ['student_number', 'program_code', 'year_level', 'section', 'enrollment_status', 'academic_term', 'enrolled_subjects']) {
    assert.match(view, new RegExp(field))
  }
  for (const route of ['enrollment-status', 'enrollment-subjects', 'enrollment-schedule', 'grading', 'student-document-requests']) {
    assert.match(view, new RegExp(`name: '${route}'`))
  }
  assert.match(view, /No finalized enrollment is available yet/)
  assert.match(service, /getDashboard\('\/student\/dashboard'\)/)
})

test('Guest website dashboard uses authenticated Admission eligibility without changing non-web presentation', async () => {
  const [view, dashboard] = await Promise.all([
    source('src/views/GuestDashboardView.vue'),
    source('src/modules/dashboard/GuestWebsiteDashboard.vue'),
  ])

  assert.match(view, /const isWebsitePresentation = clientPlatform === 'web'/)
  assert.match(view, /authStore\.currentUser\?\.admission/)
  assert.match(view, /admitted_guest/)
  assert.match(view, /fetchAdmissionIdentity/)
  assert.match(view, /admissionApi\('get', 'exam'\)/)
  assert.match(view, /getPortalNotices/)
  assert.match(dashboard, /name: 'admission'/)
  assert.match(dashboard, /name: 'enrollment-status'/)
  assert.match(dashboard, /kind === 'official' && notice\.verified/)
  assert.match(view, /<EmptyPage v-else \/>/)
  assert.doesNotMatch(`${view}\n${dashboard}`, /fake|sample statistic/i)
})

test('student sidebar follows the academic journey and retains Admission history access below services', async () => {
  const navigation = await source('src/stores/navigation.js')
  const order = [
    "'student-dashboard'",
    "'enrollment'",
    "'grading'",
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

  for (const field of ['faculty_id', 'department', 'assigned_classes', 'students', 'grade_sheets_needing_work', 'submitted_grade_sheets']) {
    assert.match(view, new RegExp(field))
  }
  for (const route of ['enrollment-teaching', 'grading']) {
    assert.match(view, new RegExp(`name: '${route}'`))
  }
  assert.match(view, /No classes have been assigned yet/)
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
