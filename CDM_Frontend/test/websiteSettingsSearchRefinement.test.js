import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { compileScript, compileStyle, parse } from '@vue/compiler-sfc'

const source = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('expanded destination search has one outer focus surface and no inner focus rectangle', async () => {
  const topbar = await source('src/components/website/WebsiteTopbar.vue')
  const { descriptor, errors } = parse(topbar)

  assert.deepEqual(errors, [])
  assert.doesNotThrow(() => compileScript(descriptor, { id: 'settings-search-topbar', inlineTemplate: true }))
  assert.deepEqual(compileStyle({ source: descriptor.styles[0].content, filename: 'WebsiteTopbar.vue', id: 'settings-search-topbar', scoped: true }).errors, [])
  assert.match(topbar, /\.search-wrap\.open \.search-surface:focus-within \{[^}]*border-color: var\(--website-emerald\);[^}]*box-shadow: 0 0 0 3px color-mix/)
  assert.match(topbar, /\.search-surface \.search-input-content input,\.search-surface \.search-input-content input:focus,\.search-surface \.search-input-content input:focus-visible \{[^}]*border: 0;[^}]*outline: 0;[^}]*box-shadow: none;/)
  assert.match(topbar, /caret-color: var\(--website-emerald\)/)
  assert.match(topbar, /input::placeholder \{ color: var\(--website-muted\)/)
  assert.doesNotMatch(topbar, /\.search-input-content input:focus-visible \{[^}]*(?:outline: [^0]|box-shadow: (?!none))/)
})

test('search behavior retains morph, authorized results, keyboard movement, dismissal, and focus restoration', async () => {
  const topbar = await source('src/components/website/WebsiteTopbar.vue')

  assert.match(topbar, /width 290ms cubic-bezier\(\.22,1,\.36,1\)/)
  assert.match(topbar, /authorizedSearchDestinations\(props\.modules\)/)
  assert.match(topbar, /event\.key === 'ArrowDown' \|\| event\.key === 'ArrowUp'/)
  assert.match(topbar, /event\.key === 'Enter'/)
  assert.match(topbar, /event\.key === 'Escape'[\s\S]*closeSearch\(true\)/)
  assert.match(topbar, /searchTrigger\.value\?\.focus\(\)/)
  assert.match(topbar, /pointerdown/)
  assert.match(topbar, /prefers-reduced-motion: reduce/)
})

test('website Settings exposes the complete Student workspace and only supported Professor categories', async () => {
  const settings = await source('src/views/SettingsView.vue')

  const studentSections = "['Profile', 'Account', 'Student Status', 'Record Requirements', 'Document Status', 'Contact Information', 'Notifications', 'Appearance', 'Security']"
  const professorSections = "['Profile', 'Account', 'Appearance', 'Security']"
  assert.ok(settings.includes(studentSections))
  assert.ok(settings.includes(professorSections))
  assert.doesNotMatch(professorSections, /Student Status|Record Requirements|Document Status|Contact Information|Notifications|Academic Preferences/)
  assert.match(settings, /settings-profile-banner/)
  assert.match(settings, /Current account/)
  assert.match(settings, /settings-category-intro/)
  assert.match(settings, /activeMeta\.description/)
  assert.match(settings, /Official · Read only/)
  assert.match(settings, /meaningfulEntries[\s\S]*value !== ''/)
})

test('Settings preserves supported profile, security, appearance, and account-scoped behavior', async () => {
  const settings = await source('src/views/SettingsView.vue')

  assert.match(settings, /studentProfile\.websiteAvatarUrl/)
  assert.match(settings, /studentProfile\.setProfile\(profile, studentUserId\.value\)/)
  assert.match(settings, /Change Photo/)
  assert.match(settings, /class="photo-remove"[\s\S]*@click="removeAvatar"/)
  assert.match(settings, /preferred_display_name: form\.value\.preferred_display_name, bio: form\.value\.bio/)
  assert.match(settings, /apiClient\.post\('\/change-password'/)
  assert.match(settings, /autocomplete="current-password"/)
  assert.match(settings, /autocomplete="new-password"/)
  assert.match(settings, /storedPortalAppearance\(authStore\.currentRole, accountId\.value\)/)
  assert.match(settings, /applyPortalAppearance\(form\.value\.appearance, authStore\.currentRole, accountId\.value\)/)
  assert.match(settings, /@change="applyAppearance"/)
  assert.doesNotMatch(settings, /\/professor\/settings\/profile/)
})

test('Settings category navigation and forms are responsive, themed, reduced-motion safe, and web isolated', async () => {
  const [settings, layout] = await Promise.all([
    source('src/views/SettingsView.vue'),
    source('src/layouts/DashboardLayout.vue'),
  ])
  const { descriptor, errors } = parse(settings)

  assert.deepEqual(errors, [])
  assert.doesNotThrow(() => compileScript(descriptor, { id: 'settings-workspace', inlineTemplate: true }))
  for (const style of descriptor.styles) assert.deepEqual(compileStyle({ source: style.content, filename: 'SettingsView.vue', id: 'settings-workspace', scoped: style.scoped }).errors, [])
  for (const breakpoint of ['1100px', '768px', '480px', '340px']) assert.match(settings, new RegExp(`max-width: ${breakpoint}`))
  assert.match(settings, /max-width: 768px[\s\S]*overflow-x: auto[\s\S]*scroll-snap-type: inline proximity/)
  assert.match(settings, /\.settings:not\(\.website-settings-workspace\) aside[\s\S]*transform: translateX\(-105%\)/)
  assert.match(settings, /var\(--website-surface-elevated\)/)
  assert.match(settings, /var\(--website-ink\)/)
  assert.match(settings, /var\(--website-muted\)/)
  assert.match(settings, /@media \(prefers-reduced-motion: reduce\)/)
  assert.match(layout, /<WebsiteAppShell v-if="isWebsitePresentation" \/>/)
  assert.match(layout, /<div v-else class="app-shell"/)
})
