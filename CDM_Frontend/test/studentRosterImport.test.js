import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const page = read('../src/modules/student-management/StudentRosterImportView.vue')
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

test('roster preview uploads the actual file and confirm requires explicit review', () => {
  assert.match(page, /body\.append\('file', file\.value, file\.value\.name\)/)
  assert.match(page, /apiClient\.post\('\/students\/roster-import\/preview', body\)/)
  assert.match(page, /preview\.value\?\.preview_token && reviewed\.value && !busy\.value/)
  assert.match(page, /runWithStepUp\(\(\) => apiClient\.post\('\/students\/roster-import\/confirm'/)
  assert.match(page, /function clear\(\)/)
})
