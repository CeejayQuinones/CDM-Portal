import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const layoutSource = readFileSync(new URL('../src/layouts/DashboardLayout.vue', import.meta.url), 'utf8')
const themeSource = readFileSync(new URL('../src/assets/styles/student-portal.css', import.meta.url), 'utf8')
const studentRequestSource = readFileSync(
  new URL('../src/modules/document-request/StudentDocumentRequestView.vue', import.meta.url),
  'utf8',
)

test('authenticated theme keeps the Student shell and adds the shared staff preference shell', () => {
  assert.match(layoutSource, /authStore\.currentRole === 'Student'/)
  assert.match(layoutSource, /'student-portal-shell': isStudentTheme/)
  assert.match(layoutSource, /\['Registrar Staff', 'Admin'\]\.includes\(authStore\.currentRole\)/)
  assert.match(layoutSource, /'registrar-theme-shell': isStaffTheme/)
  assert.match(layoutSource, /student-portal\.css/)
  assert.doesNotMatch(themeSource, /\.registrar-/)
})

test('student theme reuses the public homepage CDM palette and surface language', () => {
  for (const color of ['#106a2e', '#0d7856', '#f4d35e', '#1f1f1f', '#f1f1f1']) {
    assert.ok(themeSource.includes(color), `Expected student theme to reuse ${color}`)
  }
  assert.match(themeSource, /--student-card-shadow: 0 10px 30px/)
  assert.match(themeSource, /linear-gradient\(135deg, #f5f8f4 0%, #edf4ef 100%\)/)
})

test('student requests use the unified table and accessible dialog pattern', () => {
  assert.match(studentRequestSource, /<DocumentRequestPageHeader/)
  assert.match(studentRequestSource, /class="dr-summary-row student-request-summary"/)
  assert.match(studentRequestSource, /class="dr-table student-requests-table"/)
  assert.match(studentRequestSource, /<DocumentRequestStatusBadge/)
  assert.match(studentRequestSource, /<DocumentRequestDialog/)
})

test('student cards, forms, statuses, and modals receive scoped theme rules', () => {
  assert.match(themeSource, /\.student-portal-shell \.main-content \.student-summary-card/)
  assert.match(themeSource, /\.student-portal-shell \.main-content input/)
  assert.match(themeSource, /\.badge\.ready_for_release/)
  assert.match(themeSource, /\.appointment-details-modal\.student-booking-modal/)
  assert.match(themeSource, /@media \(prefers-reduced-motion: reduce\)/)
})
