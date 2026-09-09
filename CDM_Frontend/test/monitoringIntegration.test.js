import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const source = async (path) => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('monitoring navigation is limited to students and professors', async () => {
  const access = await source('src/config/accessControl.js')
  assert.match(access, /monitoring: \[ROLES\.STUDENT, ROLES\.PROFESSOR\]/)
  assert.doesNotMatch(access, /monitoring: \[[^\]]*REGISTRAR_STAFF/)
})

test('monitoring API uses scoped backend monitoring endpoints', async () => {
  const api = await source('src/modules/monitoring/services/monitoringApi.js')
  assert.match(api, /\/monitoring\/my-risk/)
  assert.match(api, /\/monitoring\/students\/\$\{studentId\}\/ai-help/)
  assert.match(api, /\/monitoring\/students\/\$\{studentId\}\/risk-notifications/)
  assert.doesNotMatch(api, /document-requests|physical-records/)
})
