import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { curriculumGroups } from '../src/modules/enrollment/enrollmentCatalogPresentation.js'

const source = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('Courses Offered uses the verified Student catalog and a working curriculum route', async () => {
  const [router, access, service, courses, curriculum] = await Promise.all([
    source('src/router/index.js'),
    source('src/config/accessControl.js'),
    source('src/modules/enrollment/enrollmentCatalog.js'),
    source('src/modules/enrollment/EnrollmentCourses.vue'),
    source('src/modules/enrollment/EnrollmentCurriculum.vue'),
  ])

  assert.match(router, /path: 'enrollment\/courses'.+name: 'enrollment-courses'.+platforms: \['web'\]/)
  assert.match(router, /path: 'enrollment\/courses\/:courseId\/curriculum'.+name: 'enrollment-curriculum'.+platforms: \['web'\]/)
  assert.match(access, /name: 'enrollment-courses'.+platforms: \['web'\]/)
  assert.match(service, /apiClient\.get\('\/enrollment\/academic\/catalog'\)/)
  assert.match(courses, /v-for="program in catalog\.programs"/)
  assert.match(courses, /name: 'enrollment-curriculum'/)
  assert.match(courses, /No active programs available/)
  assert.match(curriculum, /Curriculum not configured/)
  assert.match(curriculum, /No active subjects configured/)
  assert.doesNotMatch(courses, /45 Slots|BS in Information Technology|October 31, 2026/)
})

test('curriculum grouping preserves authoritative year, semester, subjects, and units', () => {
  const groups = curriculumGroups({ subjects: [
    { id: 1, year_level: 1, semester: 'First Semester', semester_order: 1, code: 'IT101', units: 3 },
    { id: 2, year_level: 1, semester: 'First Semester', semester_order: 1, code: 'IT102', units: 2 },
    { id: 3, year_level: 2, semester: 'Second Semester', semester_order: 2, code: 'IT203', units: 3 },
  ] })

  assert.equal(groups.length, 2)
  assert.deepEqual(groups[0].subjects.map(subject => subject.code), ['IT101', 'IT102'])
  assert.equal(groups[1].yearLevel, 2)
  assert.equal(groups[1].semester, 'Second Semester')
})

test('Student dashboard maps the real Enrollment period and status without inventing approval', async () => {
  const [container, dashboard, service] = await Promise.all([
    source('src/modules/dashboard/StudentDashboardView.vue'),
    source('src/modules/dashboard/StudentWebsiteDashboard.vue'),
    source('src/modules/dashboard/dashboardService.js'),
  ])

  assert.match(service, /getStudentEnrollmentStatus = \(\) => getDashboard\('\/enrollment\/status'\)/)
  assert.match(container, /isWebsitePresentation \? getStudentEnrollmentStatus\(\) : Promise\.resolve\(null\)/)
  assert.match(dashboard, /period\.value\?\.closes_at/)
  assert.match(dashboard, /\['submitted','under_review','approved','enrolled'\]/)
  assert.match(dashboard, /detail: 'Registrar review'/)
  assert.match(dashboard, /name: 'enrollment-courses'/)
  assert.match(dashboard, /name: 'enrollment-steps'/)
  assert.doesNotMatch(dashboard, /October 31, 2026|1,284|842/)
})

test('Enrollment Journey actions are connected and do not invent payment or interview services', async () => {
  const steps = await source('src/modules/enrollment/EnrollmentSteps.vue')

  for (const route of ['enrollment-status', 'enrollment-courses', 'enrollment-subjects', 'enrollment-cor']) {
    assert.match(steps, new RegExp(`name: '${route}'`))
  }
  assert.doesNotMatch(steps, /to: \{ name: 'admission' \}/)
  assert.match(steps, /Already completed for continuing Students/)
  assert.match(steps, /No general interview is scheduled by this page/)
  assert.match(steps, /CDM Portal has no tuition payment workflow/)
  assert.match(steps, /separate from the three-step Online Enrollment form/)
  assert.doesNotMatch(steps, /payment endpoint|schedule interview|process payment/i)
})

test('Phase 1.2 wizard and responsive Website-only isolation remain explicit', async () => {
  const [wizard, styles, router] = await Promise.all([
    source('src/modules/enrollment/EnrollmentStudent.vue'),
    source('src/modules/enrollment/enrollment.css'),
    source('src/router/index.js'),
  ])

  assert.match(wizard, /\['Student info','Enrollment','Course'\]/)
  assert.match(wizard, /applications\/\$\{application\.value\.id\}\/\$\{action\}/)
  assert.match(styles, /enrollment-program-grid[\s\S]*repeat\(3,minmax\(0,1fr\)\)/)
  assert.match(styles, /max-width:1024px[\s\S]*enrollment-program-grid[\s\S]*repeat\(2,minmax\(0,1fr\)\)/)
  assert.match(styles, /max-width:640px[\s\S]*enrollment-program-grid[\s\S]*minmax\(0,1fr\)/)
  assert.match(router, /enrollment-courses'[\s\S]*platforms: \['web'\]/)
})
