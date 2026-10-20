import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const read = path => readFileSync(new URL(path, import.meta.url), 'utf8')
const profile = read('../src/modules/student-management/StudentProfileView.vue')
const records = read('../src/modules/student-management/StudentRecordsView.vue')
const history = read('../src/modules/student-management/StudentAcademicHistory.vue')
const documents = read('../src/modules/student-management/StudentDocumentsView.vue')
const physical = read('../src/modules/student-management/PhysicalRecordsView.vue')

test('academic identity fields stay read-only in Student Records', () => {
  assert.doesNotMatch(profile, /v-model="form\.student_number"/)
  assert.doesNotMatch(profile, /v-model\.number="form\.course_id"/)
  assert.doesNotMatch(profile, /v-model\.number="form\.year_level"/)
  assert.doesNotMatch(records, /change_year_level|change_course/)
  assert.match(profile, /v-model\.trim="form\.first_name"/)
  assert.match(profile, /v-model\.trim="form\.contact_number"/)
  assert.match(profile, /v-model="form\.student_status"/)
})

test('Student Records failure states provide direct retry actions', () => {
  assert.match(records, /fetchStudents\(pagination\.current_page \|\| 1\)[\s\S]*Retry/)
  assert.match(records, /fetchBulkOptions\(true\)[\s\S]*Retry/)
  assert.match(history, /loadHistory\(\)[\s\S]*Retry/)
  assert.match(history, /@click="loadGrades">Retry/)
  assert.match(documents, /loadDocuments[\s\S]*Retry/)
  assert.match(physical, /loadCabinets[\s\S]*Retry/)
})

test('staff history exposes real Student Records activity without raw storage paths', () => {
  assert.match(history, /record activity/)
  assert.match(history, /data\.record_activity/)
  assert.match(history, /No Student Records activity has been recorded\./)
  assert.match(history, /activity\.staff_name/)
  assert.match(history, /activity\.occurred_at/)
  assert.doesNotMatch(history, /file_path|storage_path|raw_prompt/)
})

test('Student Documents renders handed-off document metadata from the private records response', () => {
  assert.match(documents, /document\.name/)
  assert.match(documents, /document\.has_file/)
  assert.match(documents, /document\.submitted_date/)
  assert.match(documents, /document\.verification_status/)
  assert.match(documents, /document\.verified_by/)
  assert.match(documents, /document\.remarks/)
  assert.doesNotMatch(documents, /document\.file_path/)
})
