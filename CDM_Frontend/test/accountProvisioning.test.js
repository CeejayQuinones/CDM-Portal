import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const read = path => readFileSync(new URL(path, import.meta.url), 'utf8')
const view = read('../src/modules/account-management/AccountProvisioningView.vue')
const access = read('../src/config/accessControl.js')
const router = read('../src/router/index.js')
const styles = read('../src/modules/account-management/account-management.css')

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
  assert.match(view, /aria-modal="true"/)
  assert.match(view, /role="alert"/)
  assert.match(styles, /@media\(max-width:860px\)/)
  assert.match(styles, /@media\(max-width:600px\)/)
})
