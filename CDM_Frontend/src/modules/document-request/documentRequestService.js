import { apiClient } from '../../services/apiClient'

const unwrap = async (request) => (await request).data.data
const documentTypes = () => unwrap(apiClient.get('/document-types'))

async function createDocumentType(payload) {
  const result = await unwrap(apiClient.post('/registrar/document-types', payload))
  return result
}

async function updateDocumentType(id, payload) {
  const result = await unwrap(apiClient.patch(`/registrar/document-types/${id}`, payload))
  return result
}

async function deleteDocumentType(id) {
  const result = await unwrap(apiClient.delete(`/registrar/document-types/${id}`))
  return result
}

export const documentRequestService = {
  documentTypes,
  myRequests: () => unwrap(apiClient.get('/document-requests')),
  createRequest: (payload) => unwrap(apiClient.post('/document-requests', payload)),
  createRequestBatch: (payload) => unwrap(apiClient.post('/document-request-batches', payload)),
  registrarBatches: (params) => unwrap(apiClient.get('/registrar/document-request-batches', { params })),
  registrarRequestStudents: (params) => unwrap(apiClient.get('/registrar/document-request-students', { params })),
  registrarRequestStudent: (id, params) => unwrap(apiClient.get(`/registrar/document-request-students/${id}`, { params })),
  sameLocationRequests: (id, params) => unwrap(apiClient.get(`/registrar/document-request-students/${id}/same-location`, { params })),
  bulkRejectRequests: (payload) => unwrap(apiClient.post('/registrar/document-requests/bulk-reject', payload)),
  retrievalTasks: (params) => unwrap(apiClient.get('/registrar/physical-retrieval', { params })),
  updateRetrievalTask: (id, action) => unwrap(apiClient.patch(`/registrar/physical-retrieval/${id}`, { action })),
  cancelRequest: (requestId, payload) =>
    unwrap(apiClient.patch(`/document-requests/${requestId}/cancel`, payload)),
  registrarRecentActivity: (params) => unwrap(apiClient.get('/registrar/document-request-activity', { params })),
  registrarRequests: (params) => unwrap(apiClient.get('/registrar/document-requests', { params })),
  registrarHistory: (params) => unwrap(apiClient.get('/registrar/document-requests/history', { params })),
  registrarRequest: (id) => unwrap(apiClient.get(`/registrar/document-requests/${id}`)),
  updateRequest: (id, payload) => unwrap(apiClient.patch(`/registrar/document-requests/${id}`, payload)),
  assignAppointment: (id, payload) => unwrap(apiClient.post(`/registrar/document-requests/${id}/appointment`, payload)),
  verifyCode: (verification_code) => unwrap(apiClient.post('/registrar/document-requests/verify-code', { verification_code })),
  resendClaimCode: (id) => unwrap(apiClient.post(`/registrar/document-requests/${id}/resend-claim-code`)),
  registrarAppointments: (params) => unwrap(apiClient.get('/registrar/appointments', { params })),
  updateAppointment: (id, payload) => unwrap(apiClient.patch(`/registrar/appointments/${id}`, payload)),
  appointmentAvailabilitySettings: () => unwrap(apiClient.get('/registrar/appointment-availability/settings')),
  updateAppointmentAvailabilitySettings: async (payload) =>
    (await apiClient.patch('/registrar/appointment-availability/settings', payload)).data,
  registrarCalendar: (month) => unwrap(apiClient.get('/registrar/appointment-availability/calendar', { params: { month } })),
  updateDateCapacity: (date, capacity) => unwrap(apiClient.put(`/registrar/appointment-availability/capacity/${date}`, { capacity })),
  appointmentBlockedDates: () => unwrap(apiClient.get('/registrar/appointment-blocked-dates')),
  createAppointmentBlockedDate: async (payload) =>
    (await apiClient.post('/registrar/appointment-blocked-dates', payload)).data,
  updateAppointmentBlockedDate: async (id, payload) =>
    (await apiClient.patch(`/registrar/appointment-blocked-dates/${id}`, payload)).data,
  deleteAppointmentBlockedDate: async (id) =>
    (await apiClient.delete(`/registrar/appointment-blocked-dates/${id}`)).data,
  registrarDocumentTypes: () => unwrap(apiClient.get('/registrar/document-types')),
  createDocumentType,
  updateDocumentType,
  deleteDocumentType,
}
