import { apiClient } from '../../services/apiClient'

export const eventService = {
  async promotions(params = {}) { return (await apiClient.get('/event-promotions', { params })).data.data },
  async promotion(id) { return (await apiClient.get(`/event-promotions/${id}`)).data.data },
  async list(params = {}) { const response = (await apiClient.get('/events', { params })).data; return { ...response.data, event_access: response.event_access || {} } },
  async show(id) { return (await apiClient.get(`/events/${id}`)).data.data },
  async options() { return (await apiClient.get('/events/options')).data.data },
  async create(payload) { return (await apiClient.post('/events', payload)).data.data },
  async update(id, payload) { return (await apiClient.put(`/events/${id}`, payload)).data.data },
  async transition(id, action, version) { return (await apiClient.post(`/events/${id}/${action}`, { version })).data.data },
  async attendance(id, params = {}) { return (await apiClient.get(`/events/${id}/attendance`, { params })).data.data },
  async openAttendance(id) { return (await apiClient.post(`/events/${id}/attendance/session`)).data.data },
  async closeAttendance(id, version) { return (await apiClient.post(`/events/${id}/attendance/session/close`, { version })).data.data },
  async attendanceToken(id) { return (await apiClient.post(`/events/${id}/attendance/token`)).data.data },
  async scanAttendance(id, token) { return (await apiClient.post(`/events/${id}/attendance/scan`, { token })).data },
  async participantQr(id, mode) { return (await apiClient.post(`/events/${id}/attendance/participant-qr`, { mode })).data.data },
  async operatorScan(id, token, workflow) { return (await apiClient.post(`/events/${id}/attendance/operator-scan`, { token, workflow })).data },
  async manualAttendance(id, payload) { return (await apiClient.post(`/events/${id}/attendance/manual`, payload)).data.data },
  async correctAttendance(eventId, attendanceId, payload) { return (await apiClient.patch(`/events/${eventId}/attendance/${attendanceId}`, payload)).data.data },
  async personnel(id, params = {}) { return (await apiClient.get(`/events/${id}/personnel`, { params })).data.data },
  async assignPersonnel(id, payload) { return (await apiClient.post(`/events/${id}/personnel`, payload)).data.data },
  async updatePersonnel(eventId, assignmentId, payload) { return (await apiClient.put(`/events/${eventId}/personnel/${assignmentId}`, payload)).data.data },
  async revokePersonnel(eventId, assignmentId) { return (await apiClient.post(`/events/${eventId}/personnel/${assignmentId}/revoke`)).data.data },
  async reports(params = {}) { return (await apiClient.get('/event-reports', { params })).data.data },
  async report(id, params = {}) { return (await apiClient.get(`/event-reports/${id}`, { params })).data.data },
  async exportReport(id, params = {}) { return apiClient.get(`/event-reports/${id}/export`, { params: { ...params, format: 'csv' }, responseType: 'blob' }) },
}

export const eventErrorMessage = (error) =>
  error?.response?.data?.message || Object.values(error?.response?.data?.errors || {})?.[0]?.[0] || 'Unable to complete the request. Please try again.'
