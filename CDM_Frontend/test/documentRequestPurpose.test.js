import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import {
  DOCUMENT_REQUEST_PURPOSES,
  documentRequestPurpose,
  purposePayload,
} from '../src/modules/document-request/documentRequestPurpose.js'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const student = read('../src/modules/document-request/StudentDocumentRequestView.vue')
const active = read('../src/modules/document-request/RegistrarDocumentRequestView.vue')
const history = read('../src/modules/document-request/RegistrarDocumentRequestHistoryView.vue')
const queue = read('../src/modules/document-request/RegistrarRequestQueue.vue')
const styles = read('../src/modules/document-request/documentRequest.css')

test('purpose choices and payload preserve the supported API contract', () => {
  assert.deepEqual([...DOCUMENT_REQUEST_PURPOSES], ['Scholarship', 'Evaluation', 'Others'])
  assert.deepEqual(purposePayload('Scholarship', 'ignored'), {
    purpose_category: 'Scholarship',
    purpose_details: null,
  })
  assert.deepEqual(purposePayload('Others', '  Visa application  '), {
    purpose_category: 'Others',
    purpose_details: 'Visa application',
  })
})

test('purpose presentation supports structured and legacy request records', () => {
  assert.equal(documentRequestPurpose({ purpose_category: 'Evaluation' }), 'Evaluation')
  assert.equal(documentRequestPurpose({ purpose_category: 'Others', purpose_details: 'Board examination' }), 'Others — Board examination')
  assert.equal(documentRequestPurpose({ purpose: 'Employment' }), 'Employment')
})

test('student form requires a purpose and conditionally reveals bounded Others details', () => {
  assert.match(student, /Reason \/ Purpose of Request/)
  assert.match(student, /<option value="" disabled>Select a purpose<\/option>/)
  assert.match(student, /v-if="purposeCategory === 'Others'"/)
  assert.match(student, /Please specify your purpose/)
  assert.match(student, /maxlength="500"/)
  assert.match(student, /purposeDetails\.value = ''/)
  assert.match(student, /!purposeIsValid/)
})

test('student and Registrar views display structured purpose with legacy fallback', () => {
  for (const source of [student, active, history, queue]) {
    assert.match(source, /documentRequestPurpose/)
  }
})

test('conditional field transition respects reduced motion', () => {
  assert.match(styles, /\.purpose-field-enter-active/)
  assert.match(styles, /prefers-reduced-motion: reduce/)
})
