import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { readFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import test from 'node:test'
import { build } from 'esbuild'
import { compileScript, compileStyle, parse } from '@vue/compiler-sfc'
import { studentDashboardProfileContext, STUDENT_DASHBOARD_PROFILE_EVENT } from '../src/config/studentDashboardProfile.js'
import { authorizedSearchDestinations, filterWebsiteDestinations, moveSearchSelection } from '../src/config/websiteSearch.js'

const source = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('destination search remains authorization-scoped and wraps keyboard selection', () => {
  const destinations = authorizedSearchDestinations([
    { label: 'Dashboard', destinations: [{ name: 'student-dashboard', path: '/student-dashboard', label: 'Overview' }] },
    { label: 'Enrollment', destinations: [{ name: 'enrollment-status', path: '/enrollment', label: 'Status', description: 'Current enrollment' }] },
  ])
  assert.deepEqual(destinations.map(item => item.name), ['student-dashboard', 'enrollment-status'])
  assert.deepEqual(filterWebsiteDestinations(destinations, 'enroll').map(item => item.name), ['enrollment-status'])
  assert.deepEqual(filterWebsiteDestinations(destinations, 'admin'), [], 'search cannot reveal routes absent from the authorized module list')
  assert.equal(moveSearchSelection(-1, 'next', 2), 0)
  assert.equal(moveSearchSelection(1, 'next', 2), 0)
  assert.equal(moveSearchSelection(0, 'previous', 2), 1)
  assert.equal(moveSearchSelection(0, 'next', 0), -1)
})

test('student profile context contains only existing account and Dashboard fields', () => {
  const first = studentDashboardProfileContext({
    name: 'Maya Santos', student_number: '2024-1001', program: 'Information Technology', program_code: 'BSIT', year_level: 2, section: 'A',
  }, { email: 'maya@example.test', contact_number: '09170000000', address: 'Not shown' })
  assert.deepEqual(first, {
    studentNumber: '2024-1001', officialName: 'Maya Santos', email: 'maya@example.test', contactNumber: '09170000000',
    program: 'BSIT', programName: 'Information Technology', yearLevel: 'Year 2', section: 'A',
  })
  const second = studentDashboardProfileContext({ name: 'Noah Reyes', student_number: '2025-1002' }, { email: 'noah@example.test' })
  assert.equal(second.studentNumber, '2025-1002')
  assert.equal(second.email, 'noah@example.test')
  assert.doesNotMatch(JSON.stringify(second), /maya|2024-1001/i)
  assert.equal(Object.hasOwn(first, 'address'), false, 'unsupported optional identity fields are not exposed')
})

test('Dashboard profile expands in normal layout and responds to the authorized Profile action', async () => {
  const root = fileURLToPath(new URL('../', import.meta.url))
  const stored = new Map([['cdm_portal_auth', JSON.stringify({
    token: 'student-token', currentRole: 'Student',
    currentUser: { id: 7, username: 'maya', profile: { first_name: 'Maya', last_name: 'Santos', email: 'maya@example.test' } },
  })]])
  globalThis.localStorage = {
    getItem: key => stored.get(key) ?? null,
    setItem: (key, value) => stored.set(key, value),
    removeItem: key => stored.delete(key),
  }
  const browserEvents = new EventTarget()
  globalThis.window = browserEvents

  const bundle = await build({
    absWorkingDir: root, bundle: true, write: false, platform: 'node', format: 'cjs',
    define: { 'import.meta.hot': 'undefined' },
    stdin: { resolveDir: root, contents: `
      export { createRenderer, h, nextTick, ref } from 'vue'
      export { createPinia } from 'pinia'
      export { default as StudentDashboardProfile } from './src/components/website/StudentDashboardProfile.vue'
      export { useStudentProfileStore } from './src/stores/studentProfile.js'
    ` },
    plugins: [{ name: 'dashboard-profile', setup(builder) {
      builder.onLoad({ filter: /\.vue$/ }, async ({ path }) => {
        const { descriptor } = parse(await readFile(path, 'utf8'), { filename: path })
        return { contents: compileScript(descriptor, { id: path, inlineTemplate: true }).content, loader: 'js' }
      })
      builder.onLoad({ filter: /\/services\/apiClient\.js$/ }, () => ({ contents: `
        import { resolveApiAssetUrl } from '../utils/apiAssetUrl.js'
        export const apiClient = {}
        export const apiAssetUrl = path => resolveApiAssetUrl(path, 'https://api.example.test/api', 'https://portal.example.test/')
      `, loader: 'js' }))
    } }],
  })
  const compiled = { exports: {} }
  new Function('require', 'module', 'exports', bundle.outputFiles[0].text)(createRequire(import.meta.url), compiled, compiled.exports)
  const { createRenderer, h, nextTick, ref, createPinia, StudentDashboardProfile, useStudentProfileStore } = compiled.exports
  const node = (type, text = '') => ({ type, text, props: {}, children: [], parent: null, focus() { this.focused = true } })
  const detach = child => { if (child.parent) child.parent.children.splice(child.parent.children.indexOf(child), 1) }
  const renderer = createRenderer({
    createElement: node, createText: text => node('#text', text), createComment: text => node('#comment', text),
    setText: (target, text) => { target.text = text }, setElementText: (target, text) => { target.text = text; target.children = [] },
    patchProp: (target, key, _, value) => { target.props[key] = value },
    insert: (child, parent, anchor = null) => { detach(child); parent.children.splice(anchor ? parent.children.indexOf(anchor) : parent.children.length, 0, child); child.parent = parent },
    remove: detach, parentNode: target => target.parent, nextSibling: target => target.parent?.children[target.parent.children.indexOf(target) + 1],
  })
  const find = (target, predicate) => predicate(target) ? target : target.children.map(child => find(child, predicate)).find(Boolean)
  const tree = node('root')
  const pinia = createPinia()
  useStudentProfileStore(pinia).setProfile({ avatar_url: '/storage/student-avatars/maya.png', preferred_display_name: 'May' }, 7)
  renderer.createApp({ setup() {
    const expanded = ref(false)
    return () => h(StudentDashboardProfile, {
      identity: { name: 'Maya Santos', student_number: '2024-1001', program_code: 'BSIT', year_level: 2, section: 'A' },
      expanded: expanded.value,
      'onUpdate:expanded': value => { expanded.value = value },
    })
  } }).use(pinia).mount(tree)
  await nextTick()

  const section = find(tree, item => String(item.props.class || '').includes('student-dashboard-profile'))
  const toggle = find(section, item => item.props.class === 'student-profile-expand')
  assert.equal(toggle.props['aria-expanded'], false)
  assert.equal(toggle.props['aria-label'], 'Expand student profile')
  assert.ok(find(section, item => item.text === 'May'))
  assert.equal(find(section, item => item.type === 'img').props.src.includes('maya.png'), true)
  assert.ok(find(section, item => item.text === '2024-1001'), 'compact identity uses the current Student record')
  assert.ok(find(section, item => item.text === 'BSIT'), 'compact identity uses the current program')
  assert.equal(find(section, item => item.text === 'Not available'), undefined, 'missing optional fields never create placeholder tiles')
  toggle.props.onClick()
  await nextTick()
  assert.equal(toggle.props['aria-expanded'], true)
  assert.equal(toggle.props['aria-label'], 'Collapse student profile')
  assert.match(section.props.class, /expanded/)
  toggle.props.onClick()
  await nextTick()
  browserEvents.dispatchEvent(new CustomEvent(STUDENT_DASHBOARD_PROFILE_EVENT, { detail: { expanded: true, focus: true } }))
  await nextTick()
  assert.equal(toggle.props['aria-expanded'], true)
  assert.equal(toggle.focused, true)
})

test('website-only shell preserves rail geometry while separating Profile, Settings, and search', async () => {
  const [topbar, rail, shell, profile, dashboard, dashboardStyles, layout] = await Promise.all([
    source('src/components/website/WebsiteTopbar.vue'), source('src/components/website/WebsiteNavigationRail.vue'),
    source('src/layouts/WebsiteAppShell.vue'), source('src/components/website/StudentDashboardProfile.vue'),
    source('src/modules/dashboard/StudentWebsiteDashboard.vue'), source('src/modules/dashboard/websiteDashboard.css'),
    source('src/layouts/DashboardLayout.vue'),
  ])
  for (const [name, text] of [['topbar', topbar], ['rail', rail], ['profile', profile]]) {
    const { descriptor, errors } = parse(text)
    assert.deepEqual(errors, [], `${name} parses`)
    assert.doesNotThrow(() => compileScript(descriptor, { id: name, inlineTemplate: true }))
    for (const style of descriptor.styles) assert.deepEqual(compileStyle({ source: style.content, filename: name, id: name, scoped: style.scoped }).errors, [])
  }
  assert.doesNotMatch(rail, /rail-avatar|WebsiteUserAvatar|open-account/)
  assert.match(rail, /width: 248px/)
  assert.match(rail, /\.website-navigation-rail\.collapsed \{ width: 72px/)
  assert.doesNotMatch(rail, /rail-selection/)
  assert.match(topbar, />Profile<\/button>/)
  assert.match(topbar, /name: 'settings'[\s\S]*>Settings<\/RouterLink>/)
  assert.match(topbar, /role === 'Professor'[\s\S]*professor-settings/)
  assert.match(shell, /router\.push\(\{ name: 'student-dashboard' \}\)/)
  assert.match(shell, /STUDENT_DASHBOARD_PROFILE_EVENT/)
  assert.match(profile, /grid-template-rows: 0fr/)
  assert.match(profile, /grid-template-rows: 1fr/)
  assert.match(profile, /380ms cubic-bezier\(\.22,1,\.36,1\)/)
  assert.match(profile, /--profile-forest: #064e3b/)
  assert.match(profile, /--profile-deep: #042017/)
  assert.match(profile, /--profile-emerald: var\(--website-emerald-bright,#10b981\)/)
  assert.match(profile, /--profile-gold: var\(--website-gold,#d9a93e\)/)
  assert.match(profile, /linear-gradient\(138deg,var\(--profile-forest\),var\(--profile-deep\)\)/)
  assert.doesNotMatch(profile, /background: linear-gradient\(145deg,var\(--website-surface-elevated\),var\(--website-surface-soft\)\)/)
  assert.match(profile, /class="student-profile-facts"/)
  assert.match(profile, /class="student-profile-details"/)
  assert.match(profile, /\.filter\(row => available\(row\.value\)\)/)
  assert.doesNotMatch(profile, /row\.value \|\| 'Not available'/)
  assert.match(profile, /div:last-child:nth-child\(odd\)/)
  assert.match(profile, /data-student-theme='dark'/)
  assert.match(profile, /data-student-theme='system'/)
  assert.match(dashboard, /<StudentDashboardProfile/)
  assert.match(dashboard, /student-dashboard-main-grid[\s\S]*dashboard-profile-cell[\s\S]*student-dashboard-stat-grid/)
  assert.match(dashboard, /'profile-expanded': profileExpanded/)
  assert.doesNotMatch(dashboard, /dashboard-overview|Academic overview|student-identity-strip/)
  assert.match(dashboardStyles, /grid-template-areas: 'profile stats'/)
  assert.match(dashboardStyles, /\.dashboard-profile-cell \{ grid-area: profile; min-width: 0; display: flex; \}/)
  assert.match(dashboardStyles, /profile-expanded[\s\S]*grid-template-areas: 'profile' 'stats'/)
  assert.match(dashboardStyles, /profile-expanded \.student-dashboard-stat-grid \{ grid-template-columns: repeat\(4,minmax\(0,1fr\)\)/)
  assert.match(dashboardStyles, /max-width: 820px[\s\S]*grid-template-areas: 'profile' 'stats'/)
  assert.match(dashboard, /getBoundingClientRect\(\)/)
  assert.match(dashboard, /element\.animate\(/)
  assert.doesNotMatch(dashboard, /setTimeout|setInterval/)
  assert.match(profile, /class="student-profile-expand"/)
  assert.match(profile, /aria-label="expanded \? 'Collapse student profile' : 'Expand student profile'"/)
  assert.match(profile, /M8 3H3v5M16 3h5v5M8 21H3v-5M16 21h5v-5/)
  assert.match(profile, /M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7/)
  assert.doesNotMatch(profile, /student-profile-chevron|m7 10 5 5 5-5/i)
  assert.match(topbar, /width 290ms cubic-bezier\(\.22,1,\.36,1\)/)
  assert.match(topbar, /ArrowDown.*ArrowUp/)
  assert.match(topbar, /pointerdown/)
  assert.match(topbar, /prefers-reduced-motion: reduce/)
  assert.match(topbar, /calc\(100vw - 126px\)/)
  assert.match(shell, /website-route-enter-active,\.website-route-leave-active \{ transition: opacity 150ms ease;/)
  assert.match(layout, /<WebsiteAppShell v-if="isWebsitePresentation" \/>/)
  assert.match(layout, /<div v-else class="app-shell"/)
})
