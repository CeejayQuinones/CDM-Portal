import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('authenticated shell uses compact safe-area-aware phone layout and drawer', async () => {
  const [layout, navbar, sidebar, main, index] = await Promise.all([
    read('src/layouts/DashboardLayout.vue'),
    read('src/components/Navbar.vue'),
    read('src/components/Sidebar.vue'),
    read('src/assets/styles/main.css'),
    read('index.html'),
  ])

  assert.match(index, /viewport-fit=cover/)
  assert.match(layout, /@media \(max-width: 480px\)/)
  assert.match(layout, /env\(safe-area-inset-bottom\)/)
  assert.match(navbar, /min-height: 56px/)
  assert.match(navbar, /env\(safe-area-inset-top\)/)
  assert.match(sidebar, /width: min\(86vw, 320px\)/)
  assert.match(sidebar, /height: 100dvh/)
  assert.match(sidebar, /@click="emit\('close'\)"/)
  assert.match(main, /@media \(max-width: 768px\)/)
  assert.match(main, /select,[\s\S]*height: 44px/)
  assert.match(main, /overscroll-behavior-inline: contain/)
})

test('mobile Events filters are compact and preserve one accessible label per control', async () => {
  const [view, css] = await Promise.all([
    read('src/modules/event-attendance/EventAttendanceView.vue'),
    read('src/modules/event-attendance/event.css'),
  ])

  const toolbar = view.match(/<section[^>]*class="event-toolbar"[\s\S]*?<\/section>/)?.[0] || ''
  for (const label of ['Search', 'Status', 'Audience', 'Date']) {
    assert.equal(toolbar.match(new RegExp(`<span>${label}<\\/span>`, 'g'))?.length, 1)
  }
  assert.match(css, /\.event-toolbar \{[^}]*grid-template-columns: repeat\(2,minmax\(0,1fr\)\)/)
  assert.match(css, /\.event-toolbar label \{ flex: 0 0 auto;/)
  assert.match(css, /@media \(max-width: 480px\)[\s\S]*\.event-toolbar \{ grid-template-columns: minmax\(0,1fr\)/)
  assert.match(css, /\.event-table-wrap \{[^}]*overflow-x: auto/)
  assert.match(css, /\.event-page \{[^}]*min-width: 0;[^}]*width: 100%/)
  assert.match(css, /\.qr-panel,\.qr-surface,\.participant-qr,\.student-scanner \{ max-width: 100%; min-width: 0 \}/)
  assert.match(css, /safe-area-inset-right/)
  assert.match(css, /\.event-profile-disclosure,\.event-profile,\.profile-event-tools,\.profile-event-tools > label,[^\n]*min-width: 0/)
  assert.match(css, /\.profile-event-tools select \{[^}]*max-width: 100%;[^}]*min-width: 0;[^}]*width: 100%/)
  assert.match(css, /\.scanner-viewport \{ aspect-ratio: 4 \/ 3; max-height: 40dvh; min-height: 0 \}/)
})

test('mobile tabs, dialogs, dashboards and decoration use shared responsive behavior', async () => {
  const [main, backdrop, dashboard, dialogs] = await Promise.all([
    read('src/assets/styles/main.css'),
    read('src/components/PortalAnimatedBackdrop.vue'),
    read('src/modules/dashboard/guideDashboard.css'),
    read('src/modules/document-request/documentRequestPrimitives.css'),
  ])

  assert.match(main, /\.grading-tabs,[\s\S]*flex-wrap: nowrap;[\s\S]*overflow-x: auto/)
  assert.match(backdrop, /@media \(max-width: 700px\)[\s\S]*--portal-decoration-opacity: 0\.055/)
  assert.match(backdrop, /\.cluster-ibe,[\s\S]*\.cluster-campus \{ display: none/)
  assert.match(dashboard, /@media \(max-width: 560px\)[\s\S]*repeat\(2, minmax\(0, 1fr\)\)/)
  assert.match(dialogs, /max-height: 90dvh/)
  assert.match(main, /@media \(prefers-reduced-motion: reduce\)/)
})
