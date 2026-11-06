import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import {
  GRADE_POINT_OPTIONS,
  buildGradeScalePayload,
  gradePointForApi,
  gradePointForEdit,
  isSupportedGradePoint,
  GRADE_REMARK_OPTIONS,
} from '../src/modules/grading/gradeScaleOptions.js'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('Registrar grade point choices are the 11 approved two-decimal values', () => {
  assert.deepEqual(GRADE_POINT_OPTIONS, [
    '1.00', '1.25', '1.50', '1.75', '2.00', '2.25',
    '2.50', '2.75', '3.00', '4.00', '5.00',
  ])
  assert.ok(GRADE_POINT_OPTIONS.every(value => /^\d\.\d{2}$/.test(value)))
})

test('saved values preselect consistently and API submission remains numeric', () => {
  assert.equal(gradePointForEdit('1.50'), '1.50')
  assert.equal(gradePointForEdit(2), '2.00')
  assert.equal(gradePointForApi('2.25'), 2.25)
  assert.equal(typeof gradePointForApi('2.25'), 'number')
  assert.deepEqual(buildGradeScalePayload({
    min_percentage: '75',
    max_percentage: '79.99',
    grade_point: '2.25',
    remark: 'Passed',
    is_active: true,
  }), {
    min_percentage: 75,
    max_percentage: 79.99,
    grade_point: 2.25,
    remark: 'Passed',
    is_active: true,
  })
})

test('unsupported and invalid grade points are retained for display but rejected for submission', () => {
  for (const value of ['1.004', '2.249', true, null, undefined]) {
    assert.throws(() => gradePointForApi(value), /supported grade point/)
  }
  assert.equal(gradePointForEdit('3.50'), '3.50')
  assert.equal(isSupportedGradePoint('3.50'), false)
  assert.throws(() => gradePointForApi('3.50'), /supported grade point/)
  assert.throws(() => gradePointForApi('invalid'), /supported grade point/)
  assert.throws(() => gradePointForApi(''), /supported grade point/)
})

test('remark choices preserve saved case and reject unsupported values before sending', () => {
  assert.deepEqual(GRADE_REMARK_OPTIONS, ['PASSED', 'FAILED'])
  const form = { min_percentage: 75, max_percentage: 100, grade_point: '1.00', is_active: true }
  for (const remark of ['PASSED', 'FAILED', 'Passed']) {
    assert.equal(buildGradeScalePayload({ ...form, remark }).remark, remark)
  }
  for (const remark of ['', 'INCOMPLETE', null]) {
    assert.throws(() => buildGradeScalePayload({ ...form, remark }), error => error.field === 'remark')
  }
  assert.equal(buildGradeScalePayload({ ...form, remark: 'Legacy' }, { approvedGradePointsOnly: false }).remark, 'Legacy')
})

test('Electron grading scale dialog uses the select and preserves existing field validation', async () => {
  const [view, css] = await Promise.all([
    read('src/modules/grading/GradingView.vue'),
    read('src/modules/grading/grading.css'),
  ])

  assert.match(view, /<select v-if="isDesktopPresentation" v-model="scaleForm\.grade_point" required/)
  assert.match(view, /<option disabled value="">Select grade point<\/option>/)
  assert.match(view, /Previously saved: \{\{unsupportedGradePoint\}\} \(unsupported\)/)
  assert.match(view, /grade_point:gradePointForEdit\(item\.grade_point\)/)
  assert.match(view, /buildGradeScalePayload\(scaleForm\.value,\{approvedGradePointsOnly:isDesktopPresentation\}\)/)
  for (const field of ['min_percentage', 'max_percentage', 'grade_point', 'remark']) {
    assert.match(view, new RegExp(`scaleErrors\\.${field}`))
  }
  assert.match(css, /\.grading-scale-percentage-row/)
  assert.match(css, /\.grading-scale-detail-row/)
  assert.match(css, /\.grading-scale-active/)
})
