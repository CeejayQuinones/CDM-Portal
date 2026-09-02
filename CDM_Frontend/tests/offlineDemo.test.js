import test from 'node:test'
import assert from 'node:assert/strict'

const values = new Map()
globalThis.localStorage = {
  getItem: (key) => values.get(key) ?? null,
  setItem: (key, value) => values.set(key, String(value)),
  removeItem: (key) => values.delete(key),
}

const { createOfflineApiClient } = await import('../src/services/offline/offlineApi.js')
const { resetOfflineDemoData } = await import('../src/services/offline/offlineSeeder.js')
const api = createOfflineApiClient()

test('offline demo persists a cross-role request and appointment workflow', async () => {
  await resetOfflineDemoData()
  await api.post('/login', { username: 'student@demo.local', password: 'demo1234' })
  const created = (await api.post('/document-requests', { document_type_id: 3, purpose: 'Demo transcript' })).data.data
  assert.equal(created.status, 'pending')
  const appointmentDate = '2030-09-02'
  await api.post(`/document-requests/${created.id}/appointments`, { appointment_date: appointmentDate, appointment_time: '11:00:00' })

  await api.post('/login', { username: 'registrar@demo.local', password: 'demo1234' })
  await api.patch(`/registrar/document-requests/${created.id}`, { action: 'approve' })
  await api.patch(`/registrar/document-requests/${created.id}`, { action: 'ready_for_release' })
  const appointments = (await api.get('/registrar/appointments')).data.data.data
  const appointment = appointments.find((row) => row.document_request_id === created.id)
  await api.patch(`/registrar/appointments/${appointment.id}`, { status: 'confirmed' })
  const released = (await api.patch(`/registrar/document-requests/${created.id}`, { action: 'release', appointment_id: appointment.id })).data.data
  assert.equal(released.status, 'released')
  assert.equal(released.appointments[0].status, 'completed')
})

test('cancelled appointments reopen their offline slot', async () => {
  await resetOfflineDemoData()
  await api.post('/login', { username: 'student@demo.local', password: 'demo1234' })
  const appointmentDate = '2030-09-02'
  const request = (await api.post('/document-requests', { document_type_id: 2, purpose: 'Slot test' })).data.data
  const appointment = (await api.post(`/document-requests/${request.id}/appointments`, { appointment_date: appointmentDate, appointment_time: '15:00:00' })).data.data
  await api.patch(`/appointments/${appointment.id}/cancel`, { reason: 'Schedule conflict' })
  const slots = (await api.get('/appointment-slots', { params: { date: appointmentDate } })).data.data.slots
  assert.equal(slots.find((slot) => slot.time === '15:00:00').available, true)
})
