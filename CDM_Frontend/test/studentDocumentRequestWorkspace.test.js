import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const routerSource = readFileSync(new URL('../src/router/index.js', import.meta.url), 'utf8')
const accessSource = readFileSync(new URL('../src/config/accessControl.js', import.meta.url), 'utf8')
const styles = readFileSync(
  new URL('../src/modules/document-request/documentRequest.css', import.meta.url),
  'utf8',
)

test('legacy Student Appointments route redirects to the unified appointment panel', () => {
  assert.match(
    routerSource,
    /name: 'student-document-appointments',[\s\S]*?redirect: \(to\)[\s\S]*?name: 'student-document-requests'[\s\S]*?panel: 'appointment'/,
  )
  assert.doesNotMatch(routerSource, /import StudentAppointmentsView/)
})

test('student sidebar exposes one Document Requests entry', () => {
  const menuStart = accessSource.indexOf("name: 'document-requests-menu'")
  const menuEnd = accessSource.indexOf("name: 'student-management-menu'", menuStart)
  const documentMenu = accessSource.slice(menuStart, menuEnd)

  assert.match(documentMenu, /name: 'student-document-requests'/)
  assert.doesNotMatch(documentMenu, /name: 'student-document-appointments'/)
})

test('student workspace motion uses restrained timings and honors reduced motion', () => {
  assert.match(styles, /\.student-summary-card[\s\S]*?transform 200ms ease/)
  assert.match(styles, /\.student-detail-swap-enter-active[\s\S]*?opacity 250ms ease/)
  assert.match(styles, /\.student-history-row[\s\S]*?transform 180ms ease/)
  assert.match(styles, /@media \(prefers-reduced-motion: reduce\)/)
  assert.match(styles, /transition-duration: 1ms !important/)
})

test('student workflow modals use a restrained fade and scale transition', () => {
  assert.match(styles, /\.student-modal-enter-active,[\s\S]*?opacity 220ms ease/)
  assert.match(styles, /translateY\(8px\) scale\(0\.985\)/)
  assert.match(styles, /\.student-booking-modal[\s\S]*?max-width: 880px/)
  assert.match(styles, /\.student-request-modal[\s\S]*?max-width: 720px/)
})
