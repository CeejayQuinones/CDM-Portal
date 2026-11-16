import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const page = read('../src/modules/student-management/StudentRosterImportView.vue')
const migrationReview = read('../src/modules/student-management/LegacyMigrationReviewPanel.vue')
const records = read('../src/modules/student-management/StudentRecordsView.vue')
const routes = read('../src/router/index.js')

test('Registrar roster import page compiles and is reachable from Student Records', () => {
  const { descriptor, errors } = parse(page, { filename: 'StudentRosterImportView.vue' })
  assert.deepEqual(errors, [])
  compileScript(descriptor, { id: 'roster-import-test' })
  assert.deepEqual(compileTemplate({ source: descriptor.template.content, filename: 'StudentRosterImportView.vue', id: 'roster-import-test' }).errors, [])
  assert.match(records, /to="\/student-management\/import"/)
  assert.match(routes, /name: 'student-roster-import'/)
})

test('legacy migration review compiles and requires step-up for approval without direct commit', () => {
  const { descriptor, errors } = parse(migrationReview, { filename: 'LegacyMigrationReviewPanel.vue' })
  assert.deepEqual(errors, [])
  compileScript(descriptor, { id: 'legacy-review-test' })
  assert.deepEqual(compileTemplate({ source: descriptor.template.content, filename: 'LegacyMigrationReviewPanel.vue', id: 'legacy-review-test' }).errors, [])
  assert.match(page, /<LegacyMigrationReviewPanel\s*\/>/)
  assert.match(migrationReview, /apiClient\.get\('\/registrar\/legacy-migration\/batches'\)/)
  assert.match(migrationReview, /runWithStepUp\(\(\) => apiClient\.post\(`/)
  assert.match(migrationReview, /source_sha256: batch\.source_sha256/)
  assert.doesNotMatch(migrationReview, /\/commit`/)
})

test('roster preview uploads the actual file and confirm requires explicit review', () => {
  assert.match(page, /body\.append\('file', file\.value, file\.value\.name\)/)
  assert.match(page, /apiClient\.post\('\/students\/roster-import\/preview', body\)/)
  assert.match(page, /preview\.value\?\.preview_token && reviewed\.value && !busy\.value/)
  assert.match(page, /runWithStepUp\(\(\) => apiClient\.post\('\/students\/roster-import\/confirm'/)
  assert.match(page, /function clear\(\)/)
})
