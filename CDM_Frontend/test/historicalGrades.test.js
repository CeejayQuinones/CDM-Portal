import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc'
import axios, { AxiosHeaders } from 'axios'
import { createHistoricalGradesImportForm } from '../src/modules/student-management/historicalGradesImportForm.js'
import { clearJsonContentTypeForFormData } from '../src/services/clearJsonContentTypeForFormData.js'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const panel = read('../src/modules/student-management/HistoricalGradesPanel.vue')
const records = read('../src/modules/student-management/StudentRecordsView.vue')
const profile = read('../src/modules/student-management/StudentProfileView.vue')
const grades = read('../src/modules/grading/StudentGradesView.vue')

test('Registrar historical grades panel compiles with review-before-save flows', () => {
  const { descriptor, errors } = parse(panel, { filename: 'HistoricalGradesPanel.vue' })
  assert.deepEqual(errors, [])
  compileScript(descriptor, { id: 'historical-grades-test' })
  assert.deepEqual(compileTemplate({ source: descriptor.template.content, filename: 'HistoricalGradesPanel.vue', id: 'historical-grades-test' }).errors, [])
  for (const label of ['Download Excel template', 'Import grades', 'Add previous grades', 'Validate and preview', 'Confirm all grades', 'Cancel', 'reason', 'Source Reference']) {
    assert.match(panel, new RegExp(label, 'i'))
  }
  assert.match(panel, /v-if="preview\.preview_token"[^>]*@click="confirmImport"/)
  assert.match(panel, /preview\.counts\.unknown_students/)
  assert.match(panel, /preview\.counts\.unknown_subjects/)
  assert.match(panel, /preview\.counts\.manual_review/)
  assert.match(panel, /fileInput\.value\.value = ''/)
  assert.match(panel, /createHistoricalGradesImportForm\(file\.value\)/)
})

test('historical XLSX preview keeps the selected file bytes in multipart form data', async () => {
  const bytes = 'synthetic-xlsx-file-bytes'
  const selectedFile = new File([bytes], 'new.xlsx', { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
  const form = createHistoricalGradesImportForm(selectedFile)
  assert.equal(form.get('file').name, 'new.xlsx')
  assert.equal(await form.get('file').text(), bytes)

  // Reproduce the prior 422 path: Axios converts FormData to JSON when the
  // instance's default application/json header survives request interception.
  const oldHeaders = new AxiosHeaders({ 'Content-Type': 'application/json' })
  const oldBody = axios.defaults.transformRequest[0].call({}, form, oldHeaders)
  assert.equal(typeof oldBody, 'string')
  assert.equal(oldHeaders.getContentType(), 'application/json')
  assert.doesNotMatch(oldBody, /multipart\/form-data|synthetic-xlsx-file-bytes/)

  const headers = new AxiosHeaders({ 'Content-Type': 'application/json', Accept: 'application/json' })
  const config = clearJsonContentTypeForFormData({ data: form, headers })
  assert.equal(config.headers.getContentType(), undefined)
  const requestBody = axios.defaults.transformRequest[0].call({}, config.data, config.headers)
  assert.equal(requestBody, form)

  // Fetch generates the multipart boundary just as the browser does for XHR.
  const request = new Request('https://cdm.example.test/api/registrar/historical-grades/preview', {
    method: 'POST', headers: config.headers.toJSON(), body: requestBody,
  })
  assert.match(request.headers.get('content-type'), /^multipart\/form-data; boundary=/)
  const wireBody = new TextDecoder().decode(await request.arrayBuffer())
  assert.match(wireBody, /name="file"; filename="new\.xlsx"/)
  assert.match(wireBody, /synthetic-xlsx-file-bytes/)
})

test('historical grades stay contextual to Student Records and read-only in Student Grades', () => {
  assert.match(records, /HistoricalGradesPanel v-if="auth\.currentRole === ROLES\.REGISTRAR_STAFF"/)
  assert.match(profile, /HistoricalGradesPanel v-if="canManagePhysicalRecords"[^>]*:student-id="student\.id"/)
  assert.match(grades, /Verified historical grades/)
  assert.match(grades, /data\.historical_grades/)
  assert.match(grades, /VIEWING PURPOSES ONLY/)
  assert.doesNotMatch(grades, /@click="(?:save|edit|delete)Historical/)
})
