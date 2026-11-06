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
const luminance = hex => {
  const channels = hex.match(/[a-f\d]{2}/gi).map(value => parseInt(value, 16) / 255)
    .map(value => value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4)
  return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2]
}
const contrast = (foreground, background) => {
  const values = [luminance(foreground), luminance(background)].sort((left, right) => right - left)
  return (values[0] + 0.05) / (values[1] + 0.05)
}
const storage = entries => {
  const values = new Map(entries)
  return {
    getItem: key => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
    values,
  }
}

test('Professor Settings is a legitimate self-scoped website route', async () => {
  const [view, routes, access, navigation, topbar] = await Promise.all([
    read('src/views/SettingsView.vue'),
    read('src/router/index.js'),
    read('src/config/accessControl.js'),
    read('src/stores/navigation.js'),
    read('src/components/website/WebsiteTopbar.vue'),
  ])

  assert.match(routes, /path: 'professor\/settings'[\s\S]*name: 'professor-settings'[\s\S]*platforms: \['web'\]/)
  assert.match(routes, /path: 'settings'[\s\S]*roles: \[ROLES\.STUDENT\]/)
  assert.match(access, /name: 'professor-settings'[\s\S]*roles: ROUTE_ROLES\['professor-settings'\][\s\S]*platforms: \['web'\]/)
  assert.match(access, /'professor-settings': \[ROLES\.PROFESSOR\]/)
  assert.doesNotMatch(access, /'professor-settings': \[[^\]]*(?:GUEST|ADMIN|REGISTRAR_STAFF)/)
  assert.match(navigation, /!item\.platforms \|\| item\.platforms\.includes\(clientPlatform\)/)
  assert.match(topbar, /role === 'Professor'[\s\S]*professor-settings/)
  assert.match(view, /getProfessorDashboard\(\)/)
  assert.match(view, /authStore\.fetchCurrentUser\(\)/)
  assert.match(view, /no self-service faculty profile endpoint is available/)
  assert.match(view, /faculty_id: dashboard\?\.identity\?\.faculty_id/)
  assert.match(view, /department: dashboard\?\.identity\?\.department/)
  assert.match(view, /apiClient\.post\('\/change-password'/)
  assert.doesNotMatch(view, /\/professor\/settings\/profile/)
})

test('website dark surfaces and status colors retain normal-text contrast', () => {
  for (const [foreground, background] of [
    ['#e5ece8', '#0b1613'],
    ['#e5ece8', '#13241d'],
    ['#a7b8ad', '#13241d'],
    ['#07130c', '#34d399'],
    ['#7ee2ad', '#153c2b'],
    ['#f4d35e', '#3b341d'],
    ['#ffaaa2', '#492925'],
    ['#93c5fd', '#17304a'],
  ]) assert.ok(contrast(foreground, background) >= 4.5, `${foreground} on ${background} must meet WCAG AA`)
})

test('appearance persistence is account scoped and bootstraps before mount', async () => {
  const target = { dataset: {} }
  const mock = storage()
  const studentKey = portalAppearanceStorageKey('Student', 41)
  const otherKey = portalAppearanceStorageKey('Student', 42)

  persistPortalAppearance('dark', 'Student', 41, mock, target)
  persistPortalAppearance('light', 'Student', 42, mock, target)
  assert.equal(mock.values.get(studentKey), 'dark')
  assert.equal(mock.values.get(otherKey), 'light')
  assert.equal(storedPortalAppearance('Student', 41, mock), 'dark')
  assert.equal(storedPortalAppearance('Student', 42, mock), 'light')

  mock.setItem('cdm_portal_auth', JSON.stringify({ currentRole: 'Student', currentUser: { id: 41 } }))
  assert.equal(bootstrapPortalAppearance(mock, target), 'dark')
  assert.equal(target.dataset.studentTheme, 'dark')

  const main = await read('src/main.js')
  assert.ok(main.indexOf('bootstrapPortalAppearance()') < main.indexOf('createApp(App)'), 'appearance must restore before Vue mounts')
})

test('website light, dark, and system palettes cover shell, modules, dialogs, and Buddy', async () => {
  const [tokens, academic, buddy, dashboard] = await Promise.all([
    read('src/assets/styles/website-ui-2.css'),
    read('src/assets/styles/emerald-academic-web.css'),
    read('src/components/CDMBuddy.vue'),
    read('src/modules/dashboard/websiteDashboard.css'),
  ])

  for (const token of ['canvas', 'surface', 'surface-elevated', 'input', 'ink', 'muted', 'border', 'success-bg', 'warning-bg', 'danger-bg', 'overlay']) {
    assert.match(tokens, new RegExp(`--website-${token}:`))
  }
  assert.match(tokens, /html\[data-student-theme='dark'\]\.website-ui-2-client/)
  assert.match(tokens, /html\[data-student-theme='system'\]\.website-ui-2-client/)
  assert.match(tokens, /\.dr-dialog[\s\S]*\.grading-modal/)
  assert.match(tokens, /\.dr-dialog-overlay[\s\S]*\.grading-modal-backdrop/)
  assert.match(academic, /--academic-canvas: #0b1613/)
  assert.match(academic, /--academic-paper: #13241d/)
  assert.match(academic, /grading-table-wrap td input:not\(:disabled\)[\s\S]*var\(--website-input/)
  assert.match(academic, /@media print[\s\S]*color-scheme: light/)
  assert.match(buddy, /data-student-theme='dark'/)
  assert.doesNotMatch(buddy, /data-theme="dark"/)
  assert.match(dashboard, /var\(--website-danger-bg\)/)
  assert.match(dashboard, /var\(--website-warning-bg\)/)
})

test('Step 3 remains isolated from Android and Electron presentation', async () => {
  const [shell, layout, routes, appearance] = await Promise.all([
    read('src/layouts/WebsiteAppShell.vue'),
    read('src/layouts/DashboardLayout.vue'),
    read('src/router/index.js'),
    read('src/composables/useStudentTheme.js'),
  ])

  assert.match(layout, /<WebsiteAppShell v-if="isWebsitePresentation"/)
  assert.match(layout, /<div v-else class="app-shell"/)
  assert.match(shell, /website-ui-2\.css/)
  assert.match(shell, /emerald-academic-web\.css/)
  assert.match(routes, /to\.meta\.platforms && !to\.meta\.platforms\.includes\(clientPlatform\)/)
  assert.match(appearance, /persistPortalAppearance\(appearance, role, account, storage, target\)/)
})
