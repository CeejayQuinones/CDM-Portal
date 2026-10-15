import { apiClient } from '../../services/apiClient'

const unwrap = async (request) => (await request).data.data
const DOCUMENT_TYPES_TTL_MS = 5 * 60 * 1_000
let cachedDocumentTypes = null
let documentTypesExpiresAt = 0
let documentTypesRequest = null

async function documentTypes() {
  if (cachedDocumentTypes && Date.now() < documentTypesExpiresAt) return cachedDocumentTypes
  if (documentTypesRequest) return documentTypesRequest

  documentTypesRequest = unwrap(apiClient.get('/document-types'))
  try {
    cachedDocumentTypes = await documentTypesRequest
    documentTypesExpiresAt = Date.now() + DOCUMENT_TYPES_TTL_MS
    return cachedDocumentTypes
  } finally {
    documentTypesRequest = null
  }
}

function invalidateDocumentTypes() {
  cachedDocumentTypes = null
  documentTypesExpiresAt = 0
}

async function createDocumentType(payload) {
  const result = await unwrap(apiClient.post('/registrar/document-types', payload))
  invalidateDocumentTypes()
  return result
}

async function updateDocumentType(id, payload) {
  const result = await unwrap(apiClient.patch(`/registrar/document-types/${id}`, payload))
  invalidateDocumentTypes()
  return result
}

export const documentRequestService = {
  documentTypes,
  myRequests: () => unwrap(apiClient.get('/document-requests')),
  createRequest: (payload) => unwrap(apiClient.post('/document-requests', payload)),
  cancelRequest: (requestId, payload) =>
    unwrap(apiClient.patch(`/document-requests/${requestId}/cancel`, payload)),
  appointmentAvailability: (month) =>
    unwrap(apiClient.get('/appointment-availability', { params: { month } })),
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
}
