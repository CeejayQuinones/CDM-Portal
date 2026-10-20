import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('Student and Professor web use the fine-pointer hover sidebar without reserving width', async () => {
  const [layout, platform] = await Promise.all([
    read('src/layouts/DashboardLayout.vue'),
    read('src/config/clientPlatform.js'),
  ])

  assert.match(platform, /export const clientPlatform/)
  assert.match(layout, /clientPlatform === 'web'/)
  assert.match(layout, /\['Student', 'Professor'\]\.includes\(authStore\.currentRole\)/)
  assert.match(layout, /\(hover: hover\) and \(pointer: fine\)/)
  assert.match(layout, /\.hover-sidebar-shell \.shell-content \{\s*margin-left: 0/)
  assert.match(layout, /\.hover-sidebar-shell \.main-content \{[\s\S]*?max-width: none;[\s\S]*?width: 100%/)
  assert.match(layout, /translateX\(calc\(-100% - 16px\)\)/)
  assert.match(layout, /\.sidebar\.is-open\)[^{]*\{\s*transform: translateX\(0\)/)
})

test('edge, pointer, keyboard, route, and reduced-motion behavior are explicit', async () => {
  const [layout, navbar, sidebar] = await Promise.all([
    read('src/layouts/DashboardLayout.vue'),
    read('src/components/Navbar.vue'),
    read('src/components/Sidebar.vue'),
  ])

  assert.match(layout, /SIDEBAR_CLOSE_DELAY = 220/)
  assert.match(layout, /class="sidebar-edge-trigger"/)
  assert.match(layout, /@click="openSidebar\(true\)"/)
  assert.match(layout, /@mouseenter="handleEdgeEnter"/)
  assert.match(layout, /@mouseleave="handleEdgeLeave"/)
  assert.match(layout, /@mouseenter="handleSidebarEnter"/)
  assert.match(layout, /@mouseleave="handleSidebarLeave"/)
  assert.match(layout, /event\.key === 'Escape'/)
  assert.match(layout, /watch\(\(\) => route\.fullPath, \(\) => closeSidebar\(\)\)/)
  assert.match(layout, /removeEventListener\('keydown', handleEscape\)/)
  assert.match(layout, /@media \(prefers-reduced-motion: reduce\)[\s\S]*?transition-duration: 0\.01ms/)
  assert.match(navbar, /\.menu-button\.is-visible \{\s*display: flex/)
  assert.match(sidebar, /@media \(max-width: 860px\)[\s\S]*?translateX\(-100%\)/)
})

test('Registrar, Admin, desktop builds, and mobile stay on their existing sidebar paths', async () => {
  const layout = await read('src/layouts/DashboardLayout.vue')

  assert.doesNotMatch(layout, /\['Student', 'Professor', 'Registrar Staff'/)
  assert.doesNotMatch(layout, /clientPlatform === 'desktop'.*usesHoverSidebar/)
  assert.match(layout, /v-if="isSidebarOpen && !usesHoverSidebar" class="sidebar-backdrop"/)
  assert.match(layout, /@media \(max-width: 860px\)[\s\S]*?\.shell-content \{\s*margin-left: 0/)
})
