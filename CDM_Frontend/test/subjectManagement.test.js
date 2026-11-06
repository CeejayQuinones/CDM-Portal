import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { compileScript, parse } from '@vue/compiler-sfc'

const read = path => readFileSync(new URL(path, import.meta.url), 'utf8')
const view = read('../src/modules/enrollment/EnrollmentSubjectManagement.vue')
const staff = read('../src/modules/enrollment/EnrollmentAcademicStaff.vue')
const load = read('../src/modules/enrollment/EnrollmentLoad.vue')
const access = read('../src/config/accessControl.js')
const router = read('../src/router/index.js')
const styles = read('../src/modules/enrollment/registrarEnrollmentComplete.css')

test('Subject Management has protected staff navigation without replacing Student Subjects', () => {
  assert.match(access, /'enrollment-subject-management': \[ROLES\.REGISTRAR_STAFF, ROLES\.ADMIN\]/)
  assert.match(access, /path: '\/enrollment\/subject-management'/)
  assert.match(access, /'enrollment-subjects': \[ROLES\.STUDENT\]/)
  assert.match(router, /name: 'enrollment-subject-management'/)
  assert.match(router, /roles: ROUTE_ROLES\['enrollment-subject-management'\]/)
})

test('Subject Management supports create, edit, assignment, archive and reactivation APIs', () => {
  assert.match(view, />Add Subject</)
  assert.match(view, /apiClient\.post\('\/enrollment\/academic\/subjects',\s*payload\)/)
  assert.match(view, /apiClient\.put\(`\/enrollment\/academic\/subjects\/\$\{payload\.id\}`/)
  assert.match(view, />Assign to Curriculum</)
  assert.match(view, /subjects\/\$\{statusChange\.value\.id\}\/\$\{action\}/)
  assert.match(view, /Archive|Reactivate/)
})

test('Subject Management edits the shared Professor association from the subject side', () => {
  assert.match(view, />Manage Professors</)
  assert.match(view, /subjects\/\$\{professorAssignments\.value\.subject\.id\}\/professors/)
  assert.match(view, /assigned_professor_ids/)
  assert.match(view, /Find a Professor/)
  assert.match(view, /Save associations/)
  assert.match(view, /canManageProfessors/)
  assert.match(view, /professorCanToggle/)
  assert.match(view, /type="checkbox"/)
})

test('Scheduling supports independent times for multiple selected weekdays', () => {
  assert.match(staff, /Meeting days/)
  assert.match(staff, /form\.meetings/)
  assert.match(staff, /toggleScheduleDay/)
  assert.match(staff, /meeting\.start_time/)
  assert.match(staff, /meeting\.end_time/)
  assert.match(staff, /Associated/)
})

test('Subject Management exposes filters, curriculum catalog, and useful empty/error/loading states', () => {
  for (const label of ['Find a subject', 'Program', 'Curriculum', 'Status']) assert.match(view, new RegExp(label))
  assert.match(view, /Curriculum catalog/)
  assert.match(view, /No matching subjects/)
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
  assert.match(styles, /var\(--desk-surface/)
  assert.match(styles, /\.en-catalog-group/)
  assert.match(styles, /@media \(max-width: 900px\)/)
  assert.match(styles, /html\.cdm-desktop-client/)
  assert.doesNotMatch(view, /#[0-9a-f]{3,8}/i)
})

test('Subject Manage menu is single-state, dismissible, and closes before dialogs', () => {
  const { descriptor, errors } = parse(view, { filename: 'EnrollmentSubjectManagement.vue' })
  assert.deepEqual(errors, [])
  compileScript(descriptor, { id: 'subject-management-menu', inlineTemplate: true })
  assert.match(view, /const manageMenuId = ref\(null\)/)
  assert.match(view, /manageMenuId\.value = manageMenuId\.value === id \? null : id/)
  assert.match(view, /chooseManageAction\(subject, action\)/)
  assert.match(view, /document\.addEventListener\('pointerdown', handleOutsideMenu, true\)/)
  assert.match(view, /document\.addEventListener\('keydown', handleMenuEscape, true\)/)
  assert.match(view, /window\.addEventListener\('resize', handleViewportChange\)/)
  assert.match(view, /window\.addEventListener\('scroll', handleViewportChange, true\)/)
  assert.match(view, /onBeforeRouteLeave\(\(\) => closeManageMenu\(\)\)/)
  assert.match(view, /trigger\?\.focus\(\{ preventScroll: true \}\)/)
  assert.match(view, /role="menu"/)
  assert.match(view, /role="menuitem"/)
  assert.doesNotMatch(view, /<details class="en-row-menu"/)
  assert.match(styles, /\.en-row-menu-popover/)
})
