import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const view = () => readFile(new URL('../src/views/SettingsView.vue', import.meta.url), 'utf8')

test('student settings keeps a left internal sidebar and mobile drawer', async () => {
  const source = await view()
  assert.match(source, /<aside :class="\{ open \}">/)
  assert.match(source, /\.settings-nav|aside\.open|transform:translateX/)
  assert.doesNotMatch(source, /role="tablist"|<nav class="tabs"/)
})

test('main navigation exposes Settings only to students', async () => {
  const access = await readFile(new URL('../src/config/accessControl.js', import.meta.url), 'utf8')
  assert.match(access, /settings: \[ROLES\.STUDENT\]/)
  assert.match(access, /name: 'settings',[\s\S]*?path: '\/settings',[\s\S]*?roles: ROUTE_ROLES\.settings/)
})

test('student settings contains every editable settings surface and loading/error states', async () => {
  const source = await view()
  for (const label of ['Profile', 'Account', 'Student Status', 'Document Status', 'Contact Information', 'Notifications', 'Academic Preferences', 'Appearance', 'Security']) assert.match(source, new RegExp(label))
  assert.match(source, /Loading settings\.\.\./)
  assert.match(source, /Unable to load settings/)
  assert.match(source, /student\/settings\/avatar/)
  assert.match(source, /change-password/)
})
