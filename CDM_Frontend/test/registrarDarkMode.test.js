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
    ['#f1f4f2', '#070a08'],
    ['#f1f4f2', '#0d120f'],
    ['#bcc7c0', '#121915'],
    ['#87958c', '#0d120f'],
    ['#65bd80', '#0d120f'],
    ['#f1f4f2', '#106a2e'],
    ['#ef9a91', '#492925'],
    ['#f4d35e', '#121915'],
    ['#07130c', '#65bd80'],
  ]) {
    assert.ok(contrast(foreground, background) >= 4.5, `${foreground} on ${background} must meet WCAG AA`)
  }
})

test('dark palette uses distinct CDM green depth and restrained gold tokens', async () => {
  const theme = await read('../src/assets/styles/student-theme.css')

  for (const declaration of [
    '--bg-page: #070a08',
    '--bg-surface: #0d120f',
    '--bg-surface-alt: #121915',
    '--bg-surface-raised: #17201a',
    '--bg-input: #111814',
    '--bg-hover: #19231c',
    '--border-color: #28382e',
    '--text-primary: #f1f4f2',
    '--text-secondary: #bcc7c0',
    '--text-muted: #87958c',
    '--brand-primary: #106a2e',
    '--brand-active: #168443',
    '--color-naples-yellow: #f4d35e',
  ]) assert.match(theme, new RegExp(declaration))

  assert.doesNotMatch(theme, /#1d2118|#101914|#19261e|#213128|#293b30/)
})

test('shared shell, header, controls, tables, and sidebar consume theme tokens', async () => {
  const [theme, navbar, sidebar, main, appearance] = await Promise.all([
    read('../src/assets/styles/student-theme.css'),
    read('../src/components/Navbar.vue'),
    read('../src/components/Sidebar.vue'),
    read('../src/assets/styles/main.css'),
    read('../src/composables/useStudentTheme.js'),
  ])
  assert.match(navbar, /background: var\(--bg-header\)/)
  assert.match(theme, /\.registrar-theme-shell \.shell-content/)
  assert.match(theme, /\.registrar-theme-shell :is\(input, select, textarea\)/)
  assert.match(theme, /\.registrar-theme-shell :is\(table, th, td\)/)
  assert.match(theme, /\.registrar-theme-shell \.sidebar/)
  assert.match(main, /select option[\s\S]*background: var\(--bg-input\)/)
  assert.match(main, /input:-webkit-autofill[\s\S]*-webkit-text-fill-color: var\(--text-primary\)/)
  assert.match(navbar, /color: var\(--tab-inactive, var\(--text-secondary\)\)/)
  assert.match(sidebar, /linear-gradient\(170deg, #063c26 0%, #0a6332 52%, #6eae16 100%\)/)
  assert.match(sidebar, /linear-gradient\(90deg, #c9df45, #a8cf38\)/)
  assert.match(appearance, /Guest: 'cdm-guest-appearance'/)
  assert.match(appearance, /Professor: 'cdm-professor-appearance'/)
})

test('meaningful shared text, tabs and reduced motion retain semantic treatment', async () => {
  const [theme, main, backdrop, monitoring] = await Promise.all([
    read('../src/assets/styles/student-theme.css'),
    read('../src/assets/styles/main.css'),
    read('../src/components/PortalAnimatedBackdrop.vue'),
    read('../src/modules/monitoring/MonitoringView.vue'),
  ])
  for (const token of ['tab-inactive', 'tab-active', 'tab-hover']) {
    assert.match(main, new RegExp(`--${token}:`))
    assert.match(theme, new RegExp(`--${token}:`))
  }
  assert.match(theme, /\.app-shell :is\(\.page-kicker, \.eyebrow, \.grading-eyebrow\)/)
  assert.match(theme, /\.app-shell :is\(\.page-description,[\s\S]*var\(--text-secondary\)/)
  assert.match(monitoring, /color: var\(--tab-inactive, var\(--text-secondary\)\)/)
  assert.match(backdrop, /@media \(prefers-reduced-motion: reduce\)/)
  assert.match(main, /@media \(prefers-reduced-motion: reduce\)/)
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
  assert.match(enrollment, /\.en-card \{ background:var\(--surface-1/)
  assert.match(enrollment, /\.en-card\.en-no-print \{ background:var\(--surface-2/)
  assert.match(enrollment, /th \{ color:var\(--text-secondary\); background:var\(--surface-2/)
  assert.match(records, /background: var\(--bg-surface\)/)
  assert.doesNotMatch(records, /\.confirmation-modal \{[\s\S]{0,80}background: white/)
})
