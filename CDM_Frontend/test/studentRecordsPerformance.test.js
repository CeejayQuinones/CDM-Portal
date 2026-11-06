import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import {
  clearStudentRecordsCache,
  invalidateStudentRecordsCache,
  readStudentRecordsCache,
  writeStudentRecordsCache,
} from '../src/modules/student-management/studentRecordsCache.js'

const read = path => readFileSync(new URL(path, import.meta.url), 'utf8')

test('Student Records cache is short-lived, account-scoped, and prefix-invalidatable', () => {
  const realNow = Date.now
  let now = 1_000
  Date.now = () => now
  try {
    clearStudentRecordsCache()
    writeStudentRecordsCache(10, 'physical:cabinets', ['A'])
    assert.deepEqual(readStudentRecordsCache(10, 'physical:cabinets'), ['A'])
    assert.equal(readStudentRecordsCache(11, 'physical:cabinets'), undefined)
    writeStudentRecordsCache(10, 'report:master', { total: 1 })
    invalidateStudentRecordsCache(10, 'physical:')
    assert.equal(readStudentRecordsCache(10, 'physical:cabinets'), undefined)
    assert.deepEqual(readStudentRecordsCache(10, 'report:master'), { total: 1 })
    now += 30_001
    assert.equal(readStudentRecordsCache(10, 'report:master'), undefined)
  } finally {
    Date.now = realNow
    clearStudentRecordsCache()
  }
})

test('Student Records navigation reuses only recent mount data and skips the forced route delay', () => {
  const shell = read('../src/layouts/DesktopAppShell.vue')
  const records = read('../src/modules/student-management/StudentRecordsView.vue')
  const physical = read('../src/modules/student-management/PhysicalRecordsView.vue')
  const reports = read('../src/modules/student-management/StudentRecordReportsView.vue')

  assert.match(shell, /studentRecordsRoute \? '' : 'desktop-workspace-route'/)
  assert.match(records, /fetchStudents\(page, true\)/)
  assert.match(records, /fetchProductivity\(true\)/)
  assert.match(physical, /loadCabinets\(true\)/)
  assert.match(reports, /loadOptions\(true\), loadReport\(1, true\)/)
  assert.match(physical, /const detailRequest = cachedSlot \? null : api\.cabinetSlot/)
})
