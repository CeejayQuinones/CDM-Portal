import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = path => readFile(new URL(path, import.meta.url), 'utf8')

test('registrar settings reuses the settings view with staff-specific routing', async () => {
  const [view, router, access] = await Promise.all([
    read('../src/views/SettingsView.vue'),
    read('../src/router/index.js'),
    read('../src/config/accessControl.js'),
  ])

  assert.match(router, /path: 'registrar\/settings',[\s\S]*?name: 'registrar-settings',[\s\S]*?props: \{ staffMode: true \}/)
  assert.match(access, /'registrar-settings': \[ROLES\.REGISTRAR_STAFF, ROLES\.ADMIN\]/)
  assert.match(access, /name: 'registrar-settings',[\s\S]*?path: '\/registrar\/settings'/)
  assert.match(view, /staffMode \? '\/registrar\/settings' : '\/student\/settings'/)
  assert.match(view, /Registrar Settings/)
})

test('registrar settings exposes safe profile fields and existing password flow', async () => {
  const view = await read('../src/views/SettingsView.vue')

  for (const field of ['first_name', 'middle_name', 'last_name', 'suffix', 'email', 'contact_number', 'address']) {
    assert.match(view, new RegExp(`form\\.${field}`))
  }
  assert.match(view, /staffMode \? '\/registrar\/settings\/profile'/)
  assert.match(view, /staffMode \? '\/registrar\/settings\/contact'/)
  assert.match(view, /staffMode \? '\/registrar\/settings\/avatar'/)
  assert.match(view, /apiClient\.post\('\/change-password'/)
  assert.match(view, /finally \{ saving\.value = false \}/)
  assert.match(view, /messageFor\(err, 'Unable to save settings\.'/)
})

test('student-only preferences stay out of the staff section list', async () => {
  const view = await read('../src/views/SettingsView.vue')

  assert.match(view, /props\.staffMode\s*\? \['Profile', 'Account', 'Contact Information', 'Appearance', 'Security'\]/)
  assert.match(view, /!props\.staffMode \? \['Notifications', 'Academic Preferences'\]/)
  assert.match(view, /storedPortalAppearance\(authStore\.currentRole\)/)
  assert.match(view, /applyPortalAppearance\(form\.value\.appearance, authStore\.currentRole\)/)
  assert.match(view, /success\.value = 'Appearance saved\.'/)
})
