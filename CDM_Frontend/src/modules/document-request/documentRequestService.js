import { apiClient } from '../../services/apiClient'

const unwrap = async (request) => (await request).data.data

export const documentRequestService = {
  documentTypes: () => unwrap(apiClient.get('/document-types')),
  myRequests: () => unwrap(apiClient.get('/document-requests')),
  createRequest: (payload) => unwrap(apiClient.post('/document-requests', payload)),
  slots: (date) => unwrap(apiClient.get('/appointment-slots', { params: { date } })),
  book: (requestId, payload) => unwrap(apiClient.post(`/document-requests/${requestId}/appointments`, payload)),
  appointmentOverview: () => unwrap(apiClient.get('/appointment-overview')),
  registrarRequests: (params) => unwrap(apiClient.get('/registrar/document-requests', { params })),
  registrarHistory: (params) => unwrap(apiClient.get('/registrar/document-requests/history', { params })),
  registrarRequest: (id) => unwrap(apiClient.get(`/registrar/document-requests/${id}`)),
  updateRequest: (id, payload) => unwrap(apiClient.patch(`/registrar/document-requests/${id}`, payload)),
  registrarAppointments: (params) => unwrap(apiClient.get('/registrar/appointments', { params })),
  updateAppointment: (id, payload) => unwrap(apiClient.patch(`/registrar/appointments/${id}`, payload)),
  registrarDocumentTypes: () => unwrap(apiClient.get('/registrar/document-types')),
  createDocumentType: (payload) => unwrap(apiClient.post('/registrar/document-types', payload)),
  updateDocumentType: (id, payload) => unwrap(apiClient.patch(`/registrar/document-types/${id}`, payload)),
}
