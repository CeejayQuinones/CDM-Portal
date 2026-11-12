import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import {
  bootstrapPortalAppearance,
  persistPortalAppearance,
  portalAppearanceStorageKey,
  storedPortalAppearance,
} from '../src/composables/portalAppearance.js'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')
const memoryStorage = () => {
  const values = new Map()
  return {
    getItem: key => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
  }
}

test('Registrar appearance switches immediately and restores for the same account only', () => {
  const storage = memoryStorage()
  const target = { dataset: {} }
  const key = portalAppearanceStorageKey('Registrar Staff', 41)
  assert.equal(persistPortalAppearance('dark', 'Registrar Staff', 41, storage, target), 'dark')
  assert.equal(target.dataset.studentTheme, 'dark')
  assert.equal(storage.getItem(key), 'dark')
  assert.equal(storedPortalAppearance('Registrar Staff', 42, storage), 'system')
  assert.equal(storedPortalAppearance('Admin', 41, storage), 'system')
  storage.setItem('cdm_portal_auth', JSON.stringify({ currentRole: 'Registrar Staff', currentUser: { id: 41 } }))
  const refreshed = { dataset: {} }
  assert.equal(bootstrapPortalAppearance(storage, refreshed), 'dark')
  assert.equal(refreshed.dataset.studentTheme, 'dark')
  assert.equal(persistPortalAppearance('light', 'Registrar Staff', 41, storage, refreshed), 'light')
  assert.equal(refreshed.dataset.studentTheme, 'light')
  assert.equal(bootstrapPortalAppearance(storage, { dataset: {} }), 'light')
})

test('Registrar desktop theme is scoped, and Settings uses the existing account operations', async () => {
  const [style, shell, settings, main] = await Promise.all([
    read('src/assets/styles/desktop-ui-3.css'),
    read('src/layouts/DesktopAppShell.vue'),
    read('src/views/SettingsView.vue'),
    read('src/main.js'),
  ])
  assert.match(shell, /auth\.currentRole === 'Registrar Staff'/)
  assert.match(shell, /'registrar-workspace': registrarRail/)
  assert.match(style, /html\.cdm-desktop-client\[data-student-theme='dark'\] \.registrar-workspace/)
  assert.match(style, /html\.cdm-desktop-client\[data-student-theme='system'\] \.registrar-workspace/)
  assert.match(style, /\.desktop-registrar-sidebar \{ background: var\(--desk-sidebar\)/)
  assert.match(settings, /authStore\.currentRole === ROLES\.REGISTRAR_STAFF/)
  assert.match(settings, /registrar-settings-workspace/)
  assert.match(settings, /const selectAppearance = \(\) =>/)
  assert.match(settings, /snapshot\.appearance = form\.value\.appearance/)
  assert.match(settings, /@change="selectAppearance"/)
  assert.match(settings, /staffMode \? '\/registrar\/settings\/profile'/)
  assert.match(settings, /staffMode \? '\/registrar\/settings\/contact'/)
  assert.match(settings, /authStore\.changePassword/)
  assert.ok(main.indexOf('bootstrapPortalAppearance()') < main.indexOf('app.mount('))
})

test('Registrar drawer is reachable at the Electron minimum width without changing other desktop modules', async () => {
  const [electron, shell, style, settings] = await Promise.all([
    read('electron/main.cjs'),
    read('src/layouts/DesktopAppShell.vue'),
    read('src/assets/styles/desktop-ui-3.css'),
    read('src/views/SettingsView.vue'),
  ])
  const minimumWidth = Number(electron.match(/minWidth:\s*(\d+)/)?.[1])
  const drawerWidth = Number(style.match(/@media \(max-width: (\d+)px\) \{\s*\.desktop-workspace-shell\.registrar-workspace \{ grid-template-columns: minmax\(0, 1fr\)/)?.[1])
  const shellWidth = Number(shell.match(/narrowWindow\.value = window\.innerWidth <= (\d+)/)?.[1])
  assert.equal(minimumWidth, 960)
  assert.equal(drawerWidth, shellWidth)
  assert.ok(drawerWidth >= minimumWidth)
  assert.match(style, /\.desktop-workspace-shell\.registrar-workspace \{ grid-template-columns: 272px minmax\(0, 1fr\)/)
  assert.match(style, /\.registrar-workspace \.desktop-workspace-stage \{ grid-column: 1; \}/)
  assert.match(style, /\.desktop-workspace-content \{[^}]*min-width: 0;[^}]*overflow: auto;/)
  assert.match(settings, /\.registrar-settings-workspace \.layout \{ grid-template-columns: 220px minmax\(0, 1fr\)/)
  assert.match(settings, /\.settings\.registrar-settings-workspace \.layout \{ display: grid; grid-template-columns: minmax\(0,1fr\)/)
})

test('teleported Registrar dialogs inherit the account theme without changing other roles', async () => {
  const [style, records, physical, documents, requests] = await Promise.all([
    read('src/assets/styles/desktop-ui-3.css'),
    read('src/modules/student-management/StudentRecordsView.vue'),
    read('src/modules/student-management/PhysicalRecordsView.vue'),
    read('src/modules/student-management/StudentDocumentsView.vue'),
    read('src/modules/document-request/DocumentRequestDialog.vue'),
  ])
  const registrarRoot = "html.cdm-desktop-client:has(.desktop-workspace-shell.registrar-workspace)"
  assert.ok(style.includes(registrarRoot))
  assert.ok(style.includes(`html.cdm-desktop-client[data-student-theme='dark']:has(.desktop-workspace-shell.registrar-workspace)`))
  assert.ok(style.includes(`html.cdm-desktop-client[data-student-theme='system']:has(.desktop-workspace-shell.registrar-workspace)`))
  assert.match(style, /html\.cdm-desktop-client :is\(\.modal, \[role='dialog'\], \.en-dialog, \.request-detail-dialog\)/)
  for (const component of [records, physical, documents, requests]) assert.match(component, /<Teleport to="body">/)
  assert.match(documents, /class="image-viewer-backdrop"/)
  assert.match(requests, /class="dr-dialog-overlay"/)
  assert.doesNotMatch(style, /html\.cdm-desktop-client\.registrar-workspace/)
})
