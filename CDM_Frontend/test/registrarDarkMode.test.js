import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = path => readFile(new URL(path, import.meta.url), 'utf8')
const luminance = (hex) => {
  const channels = hex.match(/[a-f\d]{2}/gi).map(value => parseInt(value, 16) / 255)
    .map(value => value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4)
  return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2]
}
const contrast = (foreground, background) => {
  const values = [luminance(foreground), luminance(background)].sort((a, b) => b - a)
  return (values[0] + 0.05) / (values[1] + 0.05)
}

test('Registrar dark mode defines one root semantic palette and restores light tokens', async () => {
  const [main, theme, layout] = await Promise.all([
    read('../src/assets/styles/main.css'),
    read('../src/assets/styles/student-theme.css'),
    read('../src/layouts/DashboardLayout.vue'),
  ])
  const tokens = ['bg-page', 'bg-surface', 'bg-surface-alt', 'bg-sidebar', 'bg-input', 'bg-hover', 'text-primary', 'text-secondary', 'text-muted', 'border-color', 'accent', 'accent-hover', 'text-on-accent', 'danger', 'success']
  for (const token of tokens) {
    assert.match(main, new RegExp(`--${token}:`), `light palette must define --${token}`)
    assert.match(theme, new RegExp(`html\\[data-student-theme='dark'\\][\\s\\S]*--${token}:`), `dark palette must define --${token}`)
  }
  assert.match(layout, /'registrar-theme-shell': isStaffTheme/)
  assert.match(theme, /html\[data-student-theme='system'\]/)
})

test('dark palette text and controls meet normal-text contrast targets', () => {
  for (const [foreground, background] of [
    ['#f0f5ef', '#101914'],
    ['#f0f5ef', '#19261e'],
    ['#cad7cd', '#213128'],
    ['#9dafaa', '#19261e'],
    ['#9bd4ad', '#213128'],
    ['#102018', '#9bd4ad'],
    ['#ffb4aa', '#442822'],
  ]) {
    assert.ok(contrast(foreground, background) >= 4.5, `${foreground} on ${background} must meet WCAG AA`)
  }
})

test('shared shell, header, controls, tables, and sidebar consume theme tokens', async () => {
  const [theme, navbar, main] = await Promise.all([
    read('../src/assets/styles/student-theme.css'),
    read('../src/components/Navbar.vue'),
    read('../src/assets/styles/main.css'),
  ])
  assert.match(navbar, /background: var\(--bg-header\)/)
  assert.match(theme, /\.registrar-theme-shell \.shell-content/)
  assert.match(theme, /\.registrar-theme-shell :is\(input, select, textarea\)/)
  assert.match(theme, /\.registrar-theme-shell :is\(table, th, td\)/)
  assert.match(theme, /\.registrar-theme-shell \.sidebar/)
  assert.match(main, /select option[\s\S]*background: var\(--bg-input\)/)
  assert.match(main, /input:-webkit-autofill[\s\S]*-webkit-text-fill-color: var\(--text-primary\)/)
})

test('Admission, Enrollment, and Student Records no longer force blocking light surfaces', async () => {
  const [admission, enrollment, records] = await Promise.all([
    read('../src/modules/admission/admission.css'),
    read('../src/modules/enrollment/enrollment.css'),
    read('../src/modules/student-management/StudentRecordsView.vue'),
  ])
  assert.match(admission, /background: var\(--bg-input\)/)
  assert.match(admission, /tbody tr:hover \{ background: var\(--bg-hover\)/)
  assert.match(enrollment, /background:var\(--bg-input/)
  assert.match(enrollment, /tbody tr:hover \{ background:var\(--bg-hover\)/)
  assert.match(records, /background: var\(--bg-surface\)/)
  assert.doesNotMatch(records, /\.confirmation-modal \{[\s\S]{0,80}background: white/)
})
