import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { moduleContainsRoute, websiteModulesFromMenu } from '../src/config/websiteNavigation.js'

const source = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('authorized route and browser-history changes resolve the correct navigation module', () => {
  const modules = websiteModulesFromMenu([
    { name: 'student-dashboard', path: '/dashboard' },
    { name: 'enrollment', children: [{ name: 'enrollment-status', path: '/enrollment/status' }, { name: 'enrollment-subjects', path: '/enrollment/subjects' }] },
    { name: 'grading', children: [{ name: 'grading-student', path: '/grading' }] },
    { name: 'document-requests-menu', children: [{ name: 'student-document-requests', path: '/document-requests' }] },
  ])
  const activeFor = route => modules.find(module => moduleContainsRoute(module, route))?.name || null
  assert.deepEqual([
    { name: 'student-dashboard', path: '/dashboard' }, { name: 'enrollment-status', path: '/enrollment/status' },
    { name: 'grading-student', path: '/grading' },
    { name: 'student-document-requests', path: '/document-requests' }, { name: 'enrollment-subjects', path: '/enrollment/subjects' },
  ].map(activeFor), ['student-dashboard','enrollment','grading','document-requests-menu','enrollment'])
  assert.equal(activeFor({ name: 'unauthorized-route', path: '/admin-dashboard' }), null)
})

test('website uses one persistent expanded or collapsed sidebar with nested destinations', async () => {
  const [rail, shell] = await Promise.all([source('src/components/website/WebsiteNavigationRail.vue'), source('src/layouts/WebsiteAppShell.vue')])
  assert.match(rail, /width: 248px/)
  assert.match(rail, /\.website-navigation-rail\.collapsed \{ width: 72px/)
  assert.match(rail, /class="rail-destinations"/)
  assert.match(rail, /role="tooltip"/)
  assert.match(rail, /Collapse portal navigation/)
  assert.match(shell, /navigationCollapsed/)
  assert.match(shell, /@toggle-collapse="toggleNavigation"/)
  assert.doesNotMatch(shell, /WebsiteNavigationPanel/)
})

test('sidebar motion is restrained, reduced-motion safe, and replaced by the mobile drawer', async () => {
  const [rail, shell, layout] = await Promise.all([
    source('src/components/website/WebsiteNavigationRail.vue'), source('src/layouts/WebsiteAppShell.vue'), source('src/layouts/DashboardLayout.vue'),
  ])
  assert.doesNotMatch(rail, /selectedElement\.animate|rail-selection|translate3d/)
  assert.match(rail, /prefers-reduced-motion: reduce/)
  assert.match(rail, /max-width: 900px[^}]*website-navigation-rail \{ display: none;/)
  assert.match(shell, /website-route-enter-active,\.website-route-leave-active \{ transition: opacity 150ms ease;/)
  assert.match(layout, /<WebsiteAppShell v-if="isWebsitePresentation" \/>/)
})
