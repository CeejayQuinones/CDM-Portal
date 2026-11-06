import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('Emerald Academic module styling is activated only by the web client shell', async () => {
  const [layout, shell, styles, platform] = await Promise.all([
    read('src/layouts/DashboardLayout.vue'),
    read('src/layouts/WebsiteAppShell.vue'),
    read('src/assets/styles/emerald-academic-web.css'),
    read('src/config/clientPlatform.js'),
  ])

  assert.match(layout, /const isWebsitePresentation = clientPlatform === 'web'/)
  assert.match(layout, /<WebsiteAppShell v-if="isWebsitePresentation"/)
  assert.match(layout, /<div v-else class="app-shell"/)
  assert.match(shell, /class="website-ui-2-shell website-academic-shell"/)
  assert.match(shell, /website-academic-client/)
  assert.match(shell, /emerald-academic-web\.css/)
  assert.match(platform, /\['web', 'desktop', 'mobile'\]/)
  assert.match(styles, /\.website-academic-shell/)
  assert.match(styles, /\.website-academic-client \.dr-dialog/)
  assert.doesNotMatch(styles, /^\s*\.dr-dialog/m)
})

test('remaining Student, Guest, and Professor workspaces share academic surfaces and controls', async () => {
  const [styles, header, enrollment, settings] = await Promise.all([
    read('src/assets/styles/emerald-academic-web.css'),
    read('src/components/AcademicPageHeader.vue'),
    read('src/modules/enrollment/EnrollmentStudent.vue'),
    read('src/views/SettingsView.vue'),
  ])

  for (const selector of [
    '.enrollment-workspace',
    '.grading-table-wrap',
    '.admission-workflow',
    '.exam-layout',
    '.student-requests-panel',
    '.settings',
  ]) assert.match(styles, new RegExp(selector.replaceAll('.', '\\.')))

  assert.match(styles, /position: sticky/)
  assert.match(styles, /overflow-x: auto/)
  assert.match(styles, /input:disabled/)
  assert.match(styles, /aria-invalid/)
  assert.match(styles, /prefers-reduced-motion: reduce/)
  assert.match(header, /variant === 'page' \? 'page-header'/)
  for (const source of [enrollment, settings]) assert.match(source, /<AcademicPageHeader/)
})

test('CDM Buddy remains outside route transitions and retains its page-aware behavior', async () => {
  const [layout, buddy, guidance] = await Promise.all([
    read('src/layouts/WebsiteAppShell.vue'),
    read('src/components/CDMBuddy.vue'),
    read('src/config/buddyModuleGuidance.js'),
  ])

  assert.match(layout, /<\/Transition>\s*<\/RouterView>\s*<\/div>\s*<CDMBuddy \/>/)
  assert.match(buddy, /buddyMuteStorageKey/)
  assert.match(buddy, /position\.onPointerDown/)
  assert.match(guidance, /buddyModuleForRoute/)
})

test('Event Management is not reintroduced by the website redesign', async () => {
  const [layout, styles] = await Promise.all([
    read('src/layouts/DashboardLayout.vue'),
    read('src/assets/styles/emerald-academic-web.css'),
  ])
  assert.doesNotMatch(`${layout}\n${styles}`, /event-attendance|event-promotions|Event Reports|Upcoming Events/)
})
