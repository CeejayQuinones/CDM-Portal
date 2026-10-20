import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const source = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('one animated backdrop is mounted in the shared authenticated shell', async () => {
  const [layout, router] = await Promise.all([
    source('src/layouts/DashboardLayout.vue'),
    source('src/router/index.js'),
  ])

  assert.equal(layout.match(/<PortalAnimatedBackdrop/g)?.length, 1)
  assert.match(layout, /<Sidebar[^>]*\/>[\s\S]*?<div class="shell-content portal-page-shell">[\s\S]*?<PortalAnimatedBackdrop[^>]*>[\s\S]*?<Navbar/)
  assert.match(layout, /<PortalAnimatedBackdrop :role="authStore\.currentRole"/)
  assert.match(layout, /\.shell-content\s*\{[\s\S]*?position: relative;[\s\S]*?max-width: 100%;[\s\S]*?min-width: 0;[\s\S]*?min-height: 100vh/)
  assert.match(layout, /\.portal-page-shell > \.main-content\s*\{[\s\S]*?z-index: 1/)

  assert.match(router, /path: '\/login',[\s\S]*?component: AuthLayout/)
  assert.match(router, /path: '\/',[\s\S]*?component: DashboardLayout/)
  assert.match(router, /name: 'student-dashboard'/)
  assert.match(router, /name: 'professor-dashboard'/)
  assert.match(router, /name: 'registrar-dashboard'/)
  assert.match(router, /name: 'admin-dashboard'/)
})

test('the shared backdrop owns the generated canvas while Student Dashboard has no duplicate', async () => {
  const [backdrop, dashboard, dashboardCss] = await Promise.all([
    source('src/components/PortalAnimatedBackdrop.vue'),
    source('src/modules/dashboard/StudentDashboardView.vue'),
    source('src/modules/dashboard/guideDashboard.css'),
  ])

  assert.match(backdrop, /\.portal-animated-backdrop\s*\{[\s\S]*?position: absolute;[\s\S]*?inset: 0;[\s\S]*?min-width: 0;[\s\S]*?min-height: 100%;[\s\S]*?pointer-events: none/)
  assert.match(backdrop, /linear-gradient\(145deg, #fbf8ed/)
  assert.match(backdrop, /data-student-theme='dark'/)
  assert.match(backdrop, /data-student-theme='dark'[\s\S]*?background: #070a08/)
  assert.match(backdrop, /background-image:[\s\S]*?linear-gradient\(rgb\(20 130 80 \/ 9%\) 1px, transparent 1px\),[\s\S]*?linear-gradient\(90deg, rgb\(20 130 80 \/ 9%\) 1px, transparent 1px\)/)
  assert.match(backdrop, /background-size: 64px 64px/)
  assert.match(backdrop, /data-student-theme='dark'[\s\S]*?\.portal-decoration-cluster \{[\s\S]*?display: none/)
  assert.doesNotMatch(backdrop, /data-student-theme='dark'[^}]*radial-gradient/)
  assert.match(backdrop, /cluster-ics \{ top: clamp\(18rem, 42vh, 28rem\)/)
  assert.doesNotMatch(backdrop, /#1d2118|gray|grey/i)
  assert.doesNotMatch(`${backdrop}\n${dashboard}\n${dashboardCss}`, /url\([^)]*\.(?:png|jpe?g|webp)/i)
  assert.doesNotMatch(dashboard, /PortalAnimatedBackdrop|dashboard-decoration-group|student-page-backdrop|IntersectionObserver|addEventListener\('scroll'/)
  assert.doesNotMatch(dashboardCss, /\.guide-dashboard\s*\{[^}]*\b(?:background|padding|border-radius|box-shadow)\s*:/)
})
