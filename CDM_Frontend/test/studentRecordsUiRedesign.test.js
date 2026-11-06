import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const read = path => readFileSync(new URL(path, import.meta.url), 'utf8')
const management = read('../src/modules/student-management/StudentRecordsView.vue')
const physical = read('../src/modules/student-management/PhysicalRecordsView.vue')
const reports = read('../src/modules/student-management/StudentRecordReportsView.vue')

test('Student Management uses one prominent directory search and contextual bulk actions', () => {
  assert.equal((management.match(/class="listing-search"/g) || []).length, 1)
  assert.doesNotMatch(management, /class="quick-search-panel"/)
  assert.match(management, /class="student-metrics"/)
  assert.match(management, /class="student-directory-layout"/)
  assert.match(management, /v-if="selectedCount" class="bulk-toolbar"/)
})

test('Physical Records keeps cabinet navigation and drawer capacity in one workspace', () => {
  assert.match(physical, /class="physical-storage-workspace"/)
  assert.match(physical, /class="cabinet-browser"/)
  assert.match(physical, /class="drawer-workspace"/)
  assert.match(physical, /drawerPercent\(slot\)/)
  assert.match(physical, /openSlot\(slot\.id, 1, true/)
  assert.doesNotMatch(physical, /class="cabinet-card cabinet-overview"/)
})

test('Reports keeps secondary filters optional and groups export formats', () => {
  assert.match(reports, /moreFiltersOpen = ref\(false\)/)
  assert.match(reports, /id="report-more-filters"/)
  assert.match(reports, /class="export-menu"/)
  for (const format of ['csv', 'excel', 'pdf']) assert.match(reports, new RegExp(`exportFile\\('${format}'\\)`))
  assert.match(reports, /@media print/)
})
