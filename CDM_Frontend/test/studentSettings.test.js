import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { compileStyle, parse } from '@vue/compiler-sfc'

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

test('compiled student themes cover the shell and dialogs with live system color-scheme support', async () => {
  const { descriptor } = parse(await view())
  const theme = await readFile(new URL('../src/assets/styles/student-theme.css', import.meta.url), 'utf8')
  const themeRules = []

  for (const style of [...descriptor.styles, { content: theme, scoped: false }]) {
    const compiled = compileStyle({ source: style.content, filename: 'SettingsView.vue', id: 'data-v-settings-test', scoped: style.scoped })
    assert.deepEqual(compiled.errors, [])
    compiled.rawResult.root.walkRules(rule => {
      if (!/data-student-theme=['"]/.test(rule.selector)) return
      themeRules.push(rule)
      // The descendant must survive Vue compilation: never theme html alone.
      assert.match(rule.selector, /^html\[data-student-theme=['"](?:dark|system)['"]\]\s+:is\(\.student-portal-shell \.shell-content, \.student-workflow-backdrop, \.step-up-backdrop\)$/)
      assert.ok(rule.nodes.every(node => node.type === 'comment' || (node.type === 'decl' && node.prop.startsWith('--student-'))), 'theme selectors must only set Student palette variables')
    })
  }

  assert.ok(themeRules.some(rule => /['"]dark['"]/.test(rule.selector)), 'an explicit dark palette must survive CSS compilation')
  const systemRules = themeRules.filter(rule => /['"]system['"]/.test(rule.selector))
  assert.ok(systemRules.length > 0, 'System must include a dark palette')
  for (const rule of systemRules) {
    assert.equal(rule.parent.type, 'atrule')
    assert.equal(rule.parent.name, 'media')
    assert.match(rule.parent.params, /prefers-color-scheme:\s*dark/)
  }
  assert.deepEqual(themeRules[0].nodes.map(node => node.toString()), systemRules[0].nodes.map(node => node.toString()), 'Dark and System must share the same palette')
  assert.match(await view(), /--settings-surface: var\(--student-surface/)
})
