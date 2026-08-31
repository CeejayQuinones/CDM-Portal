import { apiClient } from '../../services/apiClient'

const unwrap = async (request) => (await request).data.data

export const documentRequestService = {
  documentTypes: () => unwrap(apiClient.get('/document-types')),
  myRequests: () => unwrap(apiClient.get('/document-requests')),
  createRequest: (payload) => unwrap(apiClient.post('/document-requests', payload)),
  cancelRequest: (requestId, payload) =>
    unwrap(apiClient.patch(`/document-requests/${requestId}/cancel`, payload)),
  appointmentAvailability: (month) =>
    unwrap(apiClient.get('/appointment-availability', { params: { month } })),
  slots: (date) => unwrap(apiClient.get('/appointment-slots', { params: { date } })),
  book: (requestId, payload) => unwrap(apiClient.post(`/document-requests/${requestId}/appointments`, payload)),
  cancelAppointment: (appointmentId, payload) =>
    unwrap(apiClient.patch(`/appointments/${appointmentId}/cancel`, payload)),
  registrarRecentActivity: (params) => unwrap(apiClient.get('/registrar/document-request-activity', { params })),
  registrarRequests: (params) => unwrap(apiClient.get('/registrar/document-requests', { params })),
  registrarHistory: (params) => unwrap(apiClient.get('/registrar/document-requests/history', { params })),
  registrarRequest: (id) => unwrap(apiClient.get(`/registrar/document-requests/${id}`)),
  updateRequest: (id, payload) => unwrap(apiClient.patch(`/registrar/document-requests/${id}`, payload)),
  registrarAppointments: (params) => unwrap(apiClient.get('/registrar/appointments', { params })),
  updateAppointment: (id, payload) => unwrap(apiClient.patch(`/registrar/appointments/${id}`, payload)),
  appointmentAvailabilitySettings: () => unwrap(apiClient.get('/registrar/appointment-availability/settings')),
  updateAppointmentAvailabilitySettings: async (payload) =>
    (await apiClient.patch('/registrar/appointment-availability/settings', payload)).data,
  appointmentBlockedDates: () => unwrap(apiClient.get('/registrar/appointment-blocked-dates')),
  createAppointmentBlockedDate: async (payload) =>
    (await apiClient.post('/registrar/appointment-blocked-dates', payload)).data,
  updateAppointmentBlockedDate: async (id, payload) =>
    (await apiClient.patch(`/registrar/appointment-blocked-dates/${id}`, payload)).data,
  deleteAppointmentBlockedDate: async (id) =>
    (await apiClient.delete(`/registrar/appointment-blocked-dates/${id}`)).data,
  registrarDocumentTypes: () => unwrap(apiClient.get('/registrar/document-types')),
  createDocumentType: (payload) => unwrap(apiClient.post('/registrar/document-types', payload)),
  updateDocumentType: (id, payload) => unwrap(apiClient.patch(`/registrar/document-types/${id}`, payload)),
}
