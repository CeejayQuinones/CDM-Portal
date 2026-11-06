import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import {
  desktopModuleContainsRoute,
  desktopModuleForRoute,
  desktopModulesFromMenu,
} from '../src/config/desktopNavigation.js'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

const registrarMenu = [
  { name: 'registrar-dashboard', label: 'Registrar Dashboard', path: '/registrar-dashboard' },
  {
    name: 'enrollment',
    label: 'Enrollment',
    children: [
      { name: 'enrollment-applications', label: 'Applications', path: '/enrollment/applications' },
      { name: 'enrollment-records', label: 'Enrollment Records', path: '/enrollment/records' },
    ],
  },
  {
    name: 'student-management-menu',
    label: 'Student Management',
    children: [
      { name: 'student-management', label: 'Students', path: '/student-management', activeRoutes: ['student-details'] },
      { name: 'physical-records', label: 'Physical Records', path: '/student-management/physical-records' },
    ],
  },
]

test('desktop modules derive only from authorized navigation and retain stable routes', () => {
  const modules = desktopModulesFromMenu(registrarMenu)
  assert.deepEqual(modules.map(module => module.label), ['Overview', 'Enrollment', 'Students'])
  assert.equal(modules[1].destinations[1].path, '/enrollment/records')
  assert.equal(desktopModuleContainsRoute(modules[2], { name: 'student-details', path: '/student-management/41' }), true)
  assert.equal(desktopModuleForRoute(modules, { name: 'enrollment-records', path: '/enrollment/records' }).name, 'enrollment')
})

test('Electron uses an icon-only rail and contextual module navigation instead of the legacy Sidebar and Navbar', async () => {
  const [layout, shell, rail, panel, header] = await Promise.all([
    read('src/layouts/DashboardLayout.vue'),
    read('src/layouts/DesktopAppShell.vue'),
    read('src/components/desktop/DesktopPrimaryRail.vue'),
    read('src/components/desktop/DesktopContextPanel.vue'),
    read('src/components/desktop/DesktopContextHeader.vue'),
  ])

  assert.match(layout, /<DesktopAppShell v-else-if="isDesktopPresentation"/)
  assert.match(shell, /DesktopPrimaryRail/)
  assert.match(shell, /DesktopContextPanel/)
  assert.match(shell, /DesktopContextHeader/)
  assert.match(shell, /activeModule.*desktopModuleForRoute/)
  assert.match(shell, /hasContextPanel.*destinations\.length/)
  assert.match(shell, /event\.key === 'Escape'/)
  assert.match(shell, /class="desktop-context-overlay"/)
  assert.match(shell, /Transition name="desktop-context-float"/)
  assert.match(shell, /activeModule\.value\?\.name === module\.name && modulePanelOpen\.value/)
  assert.match(shell, /module\.destinations\.length === 1/)
  assert.match(shell, /closeModuleNavigation/)
  assert.match(shell, /openModuleNavigation/)
  assert.match(shell, /focusModuleButton/)
  assert.match(shell, /focusPanel/)
  assert.match(shell, /hasContextPanel && !modulePanelOpen/)
  assert.doesNotMatch(shell, /has-context-panel/)
  assert.doesNotMatch(shell, /watch\(\(\) => route\.fullPath/)
  assert.doesNotMatch(shell, /<Sidebar/)
  assert.doesNotMatch(shell, /<Navbar/)
  assert.match(rail, /aria-label="Primary workspace navigation"/)
  assert.match(rail, /desktop-rail-tooltip/)
  assert.doesNotMatch(rail, /desktop-rail-label/)
  assert.doesNotMatch(rail, /desktop-rail-destinations/)
  assert.match(rail, /aria-controls="module\.destinations\.length > 1 \? 'desktop-module-navigation'/)
  assert.match(rail, /aria-expanded="module\.destinations\.length > 1 && isActive\(module\) \? modulePanelOpen/)
  assert.match(panel, /id="desktop-module-navigation"/)
  assert.match(panel, /aria-current="active\(destination, route\) \? 'page'/)
  assert.match(panel, /aria-label="Close module navigation"/)
  assert.match(panel, /@click="\$emit\('close'\)"/)
  assert.match(panel, /defineExpose\(\{ focusPanel:/)
  assert.match(header, /Authenticated|desktop-header-account|auth\.currentRole/)
  assert.match(header, /aria-label="Open module navigation"/)
  assert.match(header, /defineExpose\(\{ focusModuleButton:/)
})

test('Registrar dashboard is an operational queue and no longer renders Quick Actions cards', async () => {
  const dashboard = await read('src/modules/registrar-dashboard/RegistrarDashboardView.vue')
  const template = dashboard.slice(dashboard.indexOf('<template>'), dashboard.indexOf('</template>'))

  assert.match(template, /operations-metric-strip/)
  assert.match(template, /Priority work queue/)
  assert.match(template, /Today's appointments/)
  assert.match(template, /Recent activity/)
  assert.match(dashboard, /pending_document_requests/)
  assert.match(dashboard, /students_with_missing_documents/)
  assert.match(dashboard, /students_without_record_location/)
  assert.doesNotMatch(template, /Quick Actions/)
  assert.doesNotMatch(template, /quick-action/)
})

test('Student and Enrollment Records retain their real workflows in denser desktop workspaces', async () => {
  const [students, enrollment, style] = await Promise.all([
    read('src/modules/student-management/StudentRecordsView.vue'),
    read('src/modules/enrollment/EnrollmentAcademicStaff.vue'),
    read('src/assets/styles/desktop-ui-3.css'),
  ])

  assert.match(students, /class="student-records-workspace"/)
  assert.match(students, /role="search"/)
  assert.match(students, /advanced-student-filters/)
  assert.match(students, /class="bulk-toolbar"/)
  assert.match(students, /viewStudent\(student\.id\)/)
  assert.match(enrollment, /filters\s*=\s*ref\(\{\s*search:/)
  assert.match(enrollment, /View COR/)
  assert.match(enrollment, /Page \{\{ page \}\} of \{\{ data\.last_page \}\}/)
  assert.match(style, /student-records-workspace \.table-wrap/)
  assert.match(style, /enrollment-workspace > \.en-table/)
})

test('UI 3 tokens, resize behavior, themes, print, and platform isolation are explicit', async () => {
  const [style, shell, website] = await Promise.all([
    read('src/assets/styles/desktop-ui-3.css'),
    read('src/layouts/DesktopAppShell.vue'),
    read('src/layouts/WebsiteAppShell.vue'),
  ])

  for (const token of ['canvas', 'surface', 'line', 'ink', 'green-950', 'gold', 'rail-width', 'panel-width']) {
    assert.match(style, new RegExp(`--desk-${token}:`))
  }
  assert.match(style, /html\.cdm-desktop-client\[data-student-theme='dark'\]/)
  assert.match(style, /html\.cdm-desktop-client\[data-student-theme='system'\]/)
  assert.match(style, /@media \(max-width: 1040px\)/)
  assert.match(style, /@media \(prefers-reduced-motion: reduce\)/)
  assert.match(style, /@media print/)
  assert.match(style, /desktop-context-float-enter-active/)
  assert.match(style, /transform: translateX\(-100%\)/)
  assert.match(style, /transition: opacity 220ms ease-out/)
  assert.doesNotMatch(style, /desktop-workspace-shell\.has-context-panel/)
  assert.match(shell, /desktop-ui-3\.css/)
  assert.doesNotMatch(website, /desktop-ui-3/)
})
