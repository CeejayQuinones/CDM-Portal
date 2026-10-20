import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const read = path => readFileSync(new URL(path, import.meta.url), 'utf8')
const view = read('../src/modules/enrollment/EnrollmentSubjectManagement.vue')
const staff = read('../src/modules/enrollment/EnrollmentAcademicStaff.vue')
const load = read('../src/modules/enrollment/EnrollmentLoad.vue')
const access = read('../src/config/accessControl.js')
const router = read('../src/router/index.js')
const styles = read('../src/modules/enrollment/enrollment.css')

test('Subject Management has protected staff navigation without replacing Student Subjects', () => {
  assert.match(access, /'enrollment-subject-management': \[ROLES\.REGISTRAR_STAFF, ROLES\.ADMIN\]/)
  assert.match(access, /path: '\/enrollment\/subject-management'/)
  assert.match(access, /'enrollment-subjects': \[ROLES\.STUDENT\]/)
  assert.match(router, /name: 'enrollment-subject-management'/)
  assert.match(router, /roles: ROUTE_ROLES\['enrollment-subject-management'\]/)
})

test('Subject Management supports create, edit, assignment, archive and reactivation APIs', () => {
  assert.match(view, />Add Subject</)
  assert.match(view, /apiClient\.post\('\/enrollment\/academic\/subjects',payload\)/)
  assert.match(view, /apiClient\.put\(`\/enrollment\/academic\/subjects\/\$\{payload\.id\}`/)
  assert.match(view, />Assign to Curriculum</)
  assert.match(view, /subjects\/\$\{statusChange\.value\.id\}\/\$\{action\}/)
  assert.match(view, /Archive|Reactivate/)
})

test('Subject Management exposes filters, curriculum view, and useful empty/error/loading states', () => {
  for (const label of ['Search code or name', 'Program', 'Curriculum', 'Status']) assert.match(view, new RegExp(label))
  assert.match(view, /Program → Curriculum → Subjects/)
  assert.match(view, /No subjects have been configured for this curriculum\./)
  assert.match(view, /role="alert"/)
  assert.match(view, /Loading subjects…/)
  assert.match(view, />Retry</)
})

test('Enrollment empty states direct staff to the authoritative subject catalog', () => {
  assert.match(staff, /No active subjects are configured for/)
  assert.match(staff, /configuration_issue/)
  assert.match(staff, /Edit Section/)
  assert.match(staff, /Open Subject Management/)
  assert.match(load, /No subjects are configured for this curriculum and semester\./)
})

test('Subject Management inherits dark theme tokens and responsive Electron-safe layout', () => {
  assert.match(styles, /var\(--surface-2/)
  assert.match(styles, /\.curriculum-groups/)
  assert.match(styles, /@media\(max-width:768px\)/)
  assert.match(styles, /overflow-x:auto/)
  assert.doesNotMatch(view, /#[0-9a-f]{3,8}/i)
})
