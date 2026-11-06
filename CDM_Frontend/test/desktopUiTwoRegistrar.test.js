import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('Desktop UI 2 remains archived while Electron selects the UI 3 workspace', async () => {
  const [main, layout, style] = await Promise.all([
    read('src/main.js'),
    read('src/layouts/DashboardLayout.vue'),
    read('src/assets/styles/desktop-ui-2.css'),
  ])

  assert.match(main, /classList\.toggle\('cdm-desktop-client', clientPlatform === 'desktop'\)/)
  assert.match(layout, /const isDesktopPresentation = clientPlatform === 'desktop'/)
  assert.match(layout, /<DesktopAppShell v-else-if="isDesktopPresentation"/)
  assert.doesNotMatch(layout, /desktop-ui-2\.css/)
  assert.match(style, /html\.cdm-desktop-client/)
  assert.doesNotMatch(style, /html\.website-ui-2-client/)
  assert.doesNotMatch(style, /\.mobile-ui/)
})

test('desktop shell supports an account-scoped collapsible rail and narrow-window drawer', async () => {
  const [layout, sidebar, navbar] = await Promise.all([
    read('src/layouts/DashboardLayout.vue'),
    read('src/components/Sidebar.vue'),
    read('src/components/Navbar.vue'),
  ])

  assert.match(layout, /`cdm-desktop-sidebar:\$\{accountKey\.value\}`/)
  assert.match(layout, /isDesktopShellWide/)
  assert.match(layout, /isDesktopShellDrawer/)
  assert.match(layout, /:mobile-drawer="\(isMobileWebsite \|\| isDesktopShellDrawer\) && isSidebarOpen"/)
  assert.match(sidebar, /'is-desktop-sidebar': desktopPresentation/)
  assert.match(navbar, /class="desktop-page-context"/)
  assert.match(navbar, /class="desktop-account"/)
})

test('desktop semantic tokens cover light, explicit dark, system dark, tables, and teleported dialogs', async () => {
  const style = await read('src/assets/styles/desktop-ui-2.css')

  for (const token of ['canvas', 'surface', 'border', 'text', 'green', 'gold', 'danger', 'warning']) {
    assert.match(style, new RegExp(`--desktop-${token}:`))
  }
  assert.match(style, /html\.cdm-desktop-client\[data-student-theme='dark'\]/)
  assert.match(style, /html\.cdm-desktop-client\[data-student-theme='system'\]/)
  assert.match(style, /\.desktop-ui-two-shell thead th/)
  assert.match(style, /position: sticky/)
  assert.match(style, /html\.cdm-desktop-client :is\(\.modal-backdrop/)
  assert.match(style, /html\.cdm-desktop-client :is\(\.modal, \[role='dialog'\]/)
})

test('desktop route motion is opacity-only and honors reduced motion', async () => {
  const style = await read('src/assets/styles/desktop-ui-2.css')
  const routeMotion = style.slice(style.indexOf('.desktop-ui-two-shell .portal-route-enter-active'), style.indexOf('/* Shared desktop'))

  assert.match(routeMotion, /transition: opacity 160ms ease/)
  assert.match(routeMotion, /transform: none/)
  assert.match(style, /@media \(prefers-reduced-motion: reduce\)/)
})

test('desktop navigation exposes only real role-gated registrar and admin destinations', async () => {
  const [access, routes] = await Promise.all([
    read('src/config/accessControl.js'),
    read('src/router/index.js'),
  ])

  for (const destination of [
    'registrar-dashboard',
    'student-management',
    'physical-records',
    'registrar-document-requests',
    'enrollment-applications',
    'enrollment-records',
    'grading-student-history',
    'registrar-settings',
  ]) assert.match(access, new RegExp(`['\"]?${destination}['\"]?`))

  assert.match(routes, /to\.meta\.platforms && !to\.meta\.platforms\.includes\(clientPlatform\)/)
  assert.doesNotMatch(access, /Event Management/)
})
