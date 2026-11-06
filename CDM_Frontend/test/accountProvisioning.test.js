import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { compileScript, parse } from '@vue/compiler-sfc'

const read = path => readFileSync(new URL(path, import.meta.url), 'utf8')
const view = read('../src/modules/account-management/AccountProvisioningView.vue')
const access = read('../src/config/accessControl.js')
const router = read('../src/router/index.js')
const styles = read('../src/modules/account-management/account-management.css')
const dialog = read('../src/design-system/CdmDialog.vue')

test('Admin and Registrar receive only their owned account-management route', () => {
  assert.match(access, /'registrar-management': \[ROLES\.ADMIN\]/)
  assert.match(access, /'professor-management': \[ROLES\.REGISTRAR_STAFF\]/)
  assert.match(router, /path: 'admin\/registrars'.*roles: ROUTE_ROLES\['registrar-management'\]/)
  assert.match(router, /path: 'registrar\/professors'.*roles: ROUTE_ROLES\['professor-management'\]/)
})

test('forms use fixed server-owned role endpoints without an arbitrary role selector', () => {
  assert.match(view, /'\/admin\/registrars'/)
  assert.match(view, /'\/registrar\/professors'/)
  assert.match(view, /apiClient\.post\(endpoint\.value, payload\)/)
  assert.match(view, /apiClient\.put\(`\$\{endpoint\.value\}\/\$\{form\.value\.id\}`/)
  assert.match(view, /The role is assigned by the server/)
  assert.doesNotMatch(view, /v-model="form\.role(_id)?"/)
})

test('account management supports filtering, status changes, responsive layout, and accessible dialogs', () => {
  for (const label of ['Search', 'Status', 'Department', 'Deactivate', 'Activate']) assert.match(view, new RegExp(label))
  assert.match(view, /CdmDialog/)
  assert.match(dialog, /aria-modal="true"/)
  assert.match(dialog, /@keydown="keydown"/)
  assert.match(view, /role="alert"/)
  assert.match(styles, /@media \(max-width: 760px\)/)
  assert.match(styles, /@media \(max-width: 560px\)/)
})

test('account pages compile as one shared reference-inspired desktop directory', () => {
  const { descriptor, errors } = parse(view, { filename: 'AccountProvisioningView.vue' })
  assert.deepEqual(errors, [])
  compileScript(descriptor, { id: 'account-provisioning', inlineTemplate: true })
  for (const marker of ['account-overview', 'account-filter', 'directory-heading', 'account-identity', 'form-section']) assert.match(view, new RegExp(marker))
  assert.match(view, /accountKind/)
  assert.match(view, /resetFilters/)
  assert.match(view, /aria-label="Account overview"/)
  assert.match(view, /aria-label="Previous page"/)
  assert.match(view, /aria-label="Next page"/)
})

test('Registrar can maintain non-authorizing Professor subject associations', () => {
  assert.match(view, /Manage subject associations for/)
  assert.match(view, /\/registrar\/professors\/\$\{account\.id\}\/subjects/)
  assert.match(view, /subject_ids: subjectAssignments\.value\.selected/)
  assert.match(view, /Scheduling reference only/)
  assert.match(view, /receives teaching and Grade Entry access only after being assigned to a subject offering/)
  assert.match(view, /Search code, subject, program, or department/)
  assert.match(styles, /\.subject-assignment-list/)
  assert.match(styles, /max-height: min\(45vh, 430px\)/)
})
