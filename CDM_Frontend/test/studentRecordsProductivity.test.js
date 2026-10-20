import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const read = path => readFileSync(new URL(path, import.meta.url), 'utf8')
const records = read('../src/modules/student-management/StudentRecordsView.vue')
const profile = read('../src/modules/student-management/StudentProfileView.vue')
const history = read('../src/modules/student-management/StudentAcademicHistory.vue')

test('Student Records productivity tools use server APIs and user-scoped session state', () => {
  assert.match(records, /\/students\/quick-search/)
  assert.match(records, /Find a Student/)
  assert.match(records, /Recently Viewed/)
  assert.match(records, /Needs Attention/)
  assert.match(records, /Today/)
  assert.match(records, /student-records\/saved-filters/)
  assert.match(records, /Rename \/ Update/)
  assert.match(records, /Visible Columns/)
  assert.match(records, /student-records-session:\$\{auth\.currentUser\?\.id/)
  assert.match(records, /sessionStorage\.setItem/)
})

test('keyboard navigation and compact result states support the Electron workflow', () => {
  assert.match(records, /event\.ctrlKey \|\| event\.metaKey/)
  assert.match(records, /event\.key\.toLowerCase\(\) === 'k'/)
  assert.match(records, /event\.key === 'Escape'/)
  assert.match(records, /Enter at least two characters/)
  assert.match(records, /No matching Students found/)
  assert.match(records, /@media \(max-width: 1000px\)/)
  assert.match(records, /@media \(max-width: 620px\)/)
  assert.match(records, /var\(--color-surface\)/)
  assert.match(records, /:focus-visible/)
})

test('profile shows completeness, read-only duplicate warnings, quick actions, and physical locator history', () => {
  for (const label of ['Academic History', 'Physical Record', 'Documents', 'Activity History', 'Reports']) {
    assert.match(profile, new RegExp(label))
  }
  assert.match(profile, /record_completeness/)
  assert.match(profile, /Possible duplicate identity — review only/)
  assert.match(profile, /duplicate_warnings/)
  assert.match(profile, /physical_record_locator\?\.last_moved_at/)
  assert.match(profile, /physical_record_locator\?\.last_moved_by/)
  assert.match(history, /defineExpose\(\{ showRecordActivity/)
})
