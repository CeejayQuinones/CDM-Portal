import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8')
const student = read('../src/modules/document-request/StudentDocumentRequestView.vue')
const registrar = read('../src/modules/document-request/RegistrarDocumentRequestView.vue')
const appointments = read('../src/modules/document-request/RegistrarAppointmentsView.vue')
const service = read('../src/modules/document-request/documentRequestService.js')
const offline = read('../src/services/offline/offlineApi.js')
const navigation = read('../src/config/navbarContexts.js')

test('student workflow is request-only and explains registrar-assigned appointments', () => {
  assert.match(student, /Registrar-assigned appointment workflow/)
  assert.match(student, /Appointment[\s\S]*Not assigned yet/)
  assert.match(student, /Check your registered email for your verification code/)
  assert.match(student, /const canBookSelectedRequest = computed\(\(\) => \{\s*return false/)
})

test('registrar assigns a date before approval and receives approved queue data', () => {
  assert.match(registrar, /api\.assignAppointment/)
  assert.match(registrar, /modalActionBusy \|\| !currentAppointment/)
  assert.match(registrar, /queues\.approved\.data/)
  assert.match(service, /document-requests\/\$\{id\}\/appointment/)
})

test('today workspace verifies server-side before complete or cancel', () => {
  assert.match(appointments, /Today's Appointments/)
  assert.match(appointments, /api\.verifyCode/)
  assert.match(appointments, /v-if="verifiedRequest"/)
  assert.match(appointments, /finalize\('complete'\)/)
  assert.match(appointments, /finalize\('cancel'\)/)
})

test('availability is month scoped with persisted per-date overrides', () => {
  assert.match(service, /registrar\/appointment-availability\/calendar/)
  assert.match(service, /appointment-availability\/capacity/)
  assert.match(appointments, /day\.booked.*day\.capacity/)
})

test('offline demo implements the same transitions and labels its synthetic code', () => {
  assert.match(offline, /r\.status='approved'/)
  assert.match(offline, /code_verified_at/)
  assert.match(offline, /r\.status=action==='complete'\?'completed':'cancelled'/)
  assert.match(registrar, /DEMO ONLY — claim code/)
})

test('ready-to-release navigation is removed', () => {
  assert.doesNotMatch(navigation, /Ready to Release/)
})
