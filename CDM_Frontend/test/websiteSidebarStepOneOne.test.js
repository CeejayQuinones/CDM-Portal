import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

import {
  readWebsiteNavigation,
  WEBSITE_DESKTOP_QUERY,
  WEBSITE_NAVIGATION_LAYOUT_EVENT,
  websiteNavigationStorageKey,
  writeWebsiteNavigation,
} from '../src/composables/useWebsiteNavigation.js'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')
const memoryStorage = () => { const values = new Map(); return { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value) } }

test('website navigation state is account-scoped and restores the collapsed preference', () => {
  const storage = memoryStorage()
  assert.equal(WEBSITE_DESKTOP_QUERY, '(min-width: 901px)')
  writeWebsiteNavigation(storage, 12, { moduleName: 'enrollment', collapsed: true })
  writeWebsiteNavigation(storage, 19, { moduleName: 'grading', collapsed: false })
  assert.equal(websiteNavigationStorageKey(12), 'cdm-website-navigation:12')
  assert.notEqual(websiteNavigationStorageKey(12), websiteNavigationStorageKey(19))
  assert.deepEqual(readWebsiteNavigation(storage, 12), { panelOpen: false, moduleName: 'enrollment', collapsed: true })
  assert.deepEqual(readWebsiteNavigation(storage, 19), { panelOpen: false, moduleName: 'grading', collapsed: false })
})

test('web client switches to a dedicated architecture while non-web keeps the legacy shell', async () => {
  const [layout, shell] = await Promise.all([read('src/layouts/DashboardLayout.vue'), read('src/layouts/WebsiteAppShell.vue')])
  assert.match(layout, /const isWebsitePresentation = clientPlatform === 'web'/)
  assert.match(layout, /<WebsiteAppShell v-if="isWebsitePresentation"/)
  assert.match(layout, /<div v-else class="app-shell"/)
  for (const component of ['WebsiteNavigationRail', 'WebsiteTopbar', 'WebsiteMobileNavigation']) assert.match(shell, new RegExp(component))
  assert.doesNotMatch(shell, /WebsiteNavigationPanel/)
  assert.match(shell, /<CDMBuddy \/>/)
})

test('single collapsible sidebar provides active, accessible role-aware navigation', async () => {
  const [rail, config, store] = await Promise.all([
    read('src/components/website/WebsiteNavigationRail.vue'),
    read('src/config/websiteNavigation.js'),
    read('src/stores/navigation.js'),
  ])
  assert.match(rail, /width: 248px/)
  assert.match(rail, /\.website-navigation-rail\.collapsed \{ width: 72px/)
  assert.match(rail, /aria-current/)
  assert.match(rail, /role="tooltip"/)
  assert.match(rail, /Collapse portal navigation/)
  assert.match(rail, /module\.destinations/)
  assert.match(config, /websiteModulesFromMenu/)
  assert.match(store, /menuItemsForRole/)
})

test('mobile website navigation uses a focus-managed modal drawer', async () => {
  const [shell, mobile] = await Promise.all([read('src/layouts/WebsiteAppShell.vue'), read('src/components/website/WebsiteMobileNavigation.vue')])
  assert.match(shell, /event\.key !== 'Escape'/)
  assert.match(shell, /:inert="mobileOpen"/)
  assert.match(shell, /website-navigation-open/)
  assert.match(mobile, /role="dialog" aria-modal="true"/)
  assert.match(mobile, /event\.key !== 'Tab'/)
  assert.match(mobile, /document\.activeElement === items\.at\(-1\)/)
  assert.match(mobile, /env\(safe-area-inset-bottom\)/)
})

test('Buddy recalculates around the collapsible navigation, topbar, and dashboard obstacles', async () => {
  const [shell, position, dashboard] = await Promise.all([
    read('src/layouts/WebsiteAppShell.vue'),
    read('src/composables/useBuddyPosition.js'),
    read('src/modules/dashboard/StudentWebsiteDashboard.vue'),
  ])
  assert.equal(WEBSITE_NAVIGATION_LAYOUT_EVENT, 'cdm:website-navigation-layout')
  assert.match(shell, /new CustomEvent\(WEBSITE_NAVIGATION_LAYOUT_EVENT\)/)
  assert.match(position, /addEventListener\(WEBSITE_NAVIGATION_LAYOUT_EVENT, onSidebarLayoutChange\)/)
  assert.match(position, /\[data-website-navigation-boundary="true"\]/)
  assert.match(position, /\[data-website-topbar="true"\]/)
  assert.match(dashboard, /data-buddy-obstacle="true"/)
})

test('role dashboards use purpose-built web components and retain existing data services', async () => {
  const [student, professor, guest, service] = await Promise.all([
    read('src/modules/dashboard/StudentWebsiteDashboard.vue'),
    read('src/modules/dashboard/ProfessorWebsiteDashboard.vue'),
    read('src/modules/dashboard/GuestWebsiteDashboard.vue'),
    read('src/modules/dashboard/dashboardService.js'),
  ])
  for (const source of [student, professor, guest]) {
    assert.match(source, /WebsiteWorkspace/)
    assert.match(source, /WebsiteQuickAction/)
  }
  assert.match(student, /student-enrollment-hero/)
  assert.match(professor, /WebsitePageHeading/)
  assert.match(guest, /CdmPageHeader/)
  assert.match(service, /\/student\/dashboard/)
  assert.match(service, /\/professor\/dashboard/)
  assert.doesNotMatch(`${student}\n${professor}\n${guest}`, /Event Management|Upcoming Events/)
})
