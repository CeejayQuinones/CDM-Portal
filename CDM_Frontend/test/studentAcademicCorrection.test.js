import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { parse, compileTemplate } from '@vue/compiler-sfc'

const source = readFileSync(new URL('../src/modules/student-management/StudentProfileView.vue', import.meta.url), 'utf8')

test('academic correction uses catalog choices and keeps derived enrollment fields read-only', () => {
  const { descriptor, errors } = parse(source, { filename: 'StudentProfileView.vue' })
  assert.deepEqual(errors, [])
  assert.deepEqual(compileTemplate({ source: descriptor.template.content, filename: 'StudentProfileView.vue', id: 'academic-correction' }).errors, [])
  assert.match(source, /\/academic-options/)
  assert.match(source, /\/academic`/)
  assert.match(source, /runWithStepUp\(\(\) => apiClient\.patch/)
  assert.match(source, /academicOptions\.courses/)
  assert.match(source, /selectedAcademicCourse\?\.curriculums/)
  assert.match(source, /selectedAcademicCourse\?\.department/)
  assert.match(source, /academicOptions\.identity_locked/)
  assert.match(source, /Reason for correction/)
})
