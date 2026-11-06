import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

import { destinationContainsRouteName, moduleContainsRoute, moduleContainsRouteName, websiteModulesFromMenu } from '../src/config/websiteNavigation.js'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

const menuItems = [
  { name: 'student-dashboard', label: 'Dashboard', path: '/dashboard' },
  {
    name: 'enrollment',
    label: 'Enrollment',
    children: [
      { name: 'enrollment-status', label: 'Status', path: '/enrollment/status' },
      { name: 'enrollment-subjects', label: 'Subjects', path: '/enrollment/subjects' },
      { name: 'enrollment-schedule', label: 'Schedule', path: '/enrollment/schedule' },
      { name: 'enrollment-cor', label: 'COR', path: '/enrollment/cor' },
    ],
  },
  { name: 'student-document-requests', label: 'Document Requests', path: '/document-requests' },
]

test('the required navigation sequence resolves deterministically without collapsing an Enrollment module', () => {
  const modules = websiteModulesFromMenu(menuItems)
  const moduleFor = route => modules.find(module => moduleContainsRoute(module, route))?.name
  const sequence = [
    { name: 'student-dashboard', path: '/dashboard' },
    { name: 'enrollment-status', path: '/enrollment/status' },
    { name: 'enrollment-subjects', path: '/enrollment/subjects' },
    { name: 'enrollment-schedule', path: '/enrollment/schedule' },
    { name: 'student-document-requests', path: '/document-requests' },
    { name: 'student-dashboard', path: '/dashboard' },
  ]

  assert.deepEqual(sequence.map(moduleFor), [
    'student-dashboard',
    'enrollment',
    'enrollment',
    'enrollment',
    'student-document-requests',
    'student-dashboard',
  ])
})

test('desktop and mobile route-name matching preserve nested active state', () => {
  const module = {
    name: 'admission-menu',
    destinations: [
      { name: 'admission', activeRoutes: ['admission-upload'] },
      { name: 'student-admission-exam', activeRoutes: ['admission-exam-instructions'] },
    ],
  }

  assert.equal(moduleContainsRouteName(module, 'admission-upload'), true)
  assert.equal(destinationContainsRouteName(module.destinations[0], 'admission-upload'), true)
  assert.equal(destinationContainsRouteName(module.destinations[1], 'admission-upload'), false)
  assert.equal(moduleContainsRouteName(module, 'unrelated'), false)
})

test('route transitions keep a persistent final-size stage and use an opacity-only fade', async () => {
  const shell = await read('src/layouts/WebsiteAppShell.vue')
  const style = shell.match(/<style scoped>([\s\S]*?)<\/style>/)?.[1] || ''

  assert.match(shell, /class="website-route-stage"/)
  assert.match(shell, /:key="currentRoute\.path"/)
  assert.doesNotMatch(shell, /mode="out-in"/)
  assert.match(style, /\.website-route-stage \{[^}]*display: grid;[^}]*grid-template-columns: minmax\(0,1fr\);[^}]*overflow-x: clip;/)
  assert.match(style, /\.website-route-view \{[^}]*grid-area: 1 \/ 1;[^}]*width: 100%;[^}]*min-width: 0;[^}]*min-height:/)
  assert.match(style, /website-route-enter-active[^}]*transition: opacity 150ms ease;/)
  assert.doesNotMatch(style, /website-route[^\n{}]*\{[^}]*transform:/)
  assert.match(style, /prefers-reduced-motion: reduce[^}]*\{[^}]*website-route-enter-active[^}]*transition: none;/)
})

test('route changes preserve the single-sidebar preference and notify Buddy without timers', async () => {
  const [shell, rail, buddy, buddyPosition] = await Promise.all([
    read('src/layouts/WebsiteAppShell.vue'),
    read('src/components/website/WebsiteNavigationRail.vue'),
    read('src/components/CDMBuddy.vue'),
    read('src/composables/useBuddyPosition.js'),
  ])
  const routeWatch = shell.slice(shell.indexOf('watch(() => route.path'), shell.indexOf('watch(accountKey'))

  assert.match(shell, /collapsed: navigationCollapsed\.value/)
  assert.match(shell, /@toggle-collapse="toggleNavigation"/)
  assert.doesNotMatch(routeWatch, /setTimeout/)
  assert.match(rail, /class="rail-destinations"/)
  assert.match(buddy, /\.cdm-buddy \{[^}]*position: fixed;/)
  assert.match(buddyPosition, /WEBSITE_NAVIGATION_LAYOUT_EVENT/)
})

test('the web workspace reserves scrollbar width and contains Enrollment overflow locally', async () => {
  const [ui, workspace, enrollment] = await Promise.all([
    read('src/assets/styles/website-ui-2.css'),
    read('src/components/website/WebsiteWorkspace.vue'),
    read('src/modules/enrollment/enrollment.css'),
  ])

  assert.match(ui, /scrollbar-gutter: stable;/)
  assert.match(ui, /html\.website-ui-2-client body \{[^}]*overflow-x: clip;/)
  assert.match(ui, /website-route-view > \.enrollment-workspace \{[^}]*width: 100%;[^}]*max-width: 1320px;/)
  assert.match(ui, /\.enrollment-workspace :is\(\.en-card, \.en-grid, \.en-table, \.en-timetable\) \{[^}]*max-width: 100%;[^}]*min-width: 0;/)
  assert.doesNotMatch(workspace, /padding:/)
  assert.match(enrollment, /\.enrollment-workspace \.en-table \{ overflow-x:auto; \}/)
  assert.match(enrollment, /\.enrollment-workspace \.en-timetable \{[^}]*overflow-x:auto;/)
})

test('web UI 2 prevents the global page-header transform from stacking with route motion', async () => {
  const [ui, global] = await Promise.all([
    read('src/assets/styles/website-ui-2.css'),
    read('src/assets/styles/main.css'),
  ])

  assert.match(global, /\.page-header \{[^}]*animation: page-enter 260ms ease both;/)
  assert.match(ui, /\.website-ui-2-shell \.page-header \{\s*animation: none;/)
})
