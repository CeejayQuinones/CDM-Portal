import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const view = read('../src/modules/student-management/StudentRecordReportsView.vue')
const access = read('../src/config/accessControl.js')
const router = read('../src/router/index.js')

test('Student Record Reports navigation and route are restricted to Registrar and Admin', () => {
  assert.match(access, /'student-record-reports': \[ROLES\.REGISTRAR_STAFF, ROLES\.ADMIN\]/)
  assert.match(access, /label: 'Reports',[\s\S]*path: '\/student-management\/reports'/)
  assert.match(router, /name: 'student-record-reports'/)
  assert.match(router, /roles: ROUTE_ROLES\['student-record-reports'\]/)
})

test('report workspace provides selector, filters, summary, export, and print actions', () => {
  for (const report of ['Student Master List', 'Student Academic Summary', 'Physical Records Status', 'Student Record Activity Summary']) {
    assert.match(view, new RegExp(report))
  }
  for (const state of ['Loading report...', 'No records match the selected filters.', 'Unable to load report.', 'Retry']) {
    assert.match(view, new RegExp(state.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')))
  }
  assert.match(view, /apiClient\.get\(`\/student-record-reports\/\$\{reportType\.value\}`/)
  assert.match(view, /responseType: 'blob'/)
  assert.match(view, /Export CSV/)
  assert.match(view, /Export Excel/)
  assert.match(view, /Export PDF/)
  assert.match(view, /export\/\$\{format\}/)
  assert.match(view, /exporting === 'excel'/)
  assert.match(view, /exporting === 'pdf'/)
  assert.match(view, /window\.print\(\)/)
  assert.match(view, /summaryCards/)
  assert.match(view, /resetFilters/)
})

test('print, dark theme, and responsive rules keep reports usable in Electron sizes', () => {
  assert.match(view, /@media print/)
  assert.match(view, /\.no-print\{display:none!important\}/)
  assert.match(view, /Colegio de Montalban/)
  assert.match(view, /Registrar Office/)
  assert.match(view, /var\(--bg-surface/)
  assert.match(view, /overflow:auto/)
  assert.match(view, /@media\(max-width:1100px\)/)
  assert.match(view, /@media\(max-width:640px\)/)
})
