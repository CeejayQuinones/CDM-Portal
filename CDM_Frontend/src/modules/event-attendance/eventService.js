import { apiClient } from '../../services/apiClient'

export const eventService = {
  async list(params = {}) { return (await apiClient.get('/events', { params })).data.data },
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
  async manualAttendance(id, payload) { return (await apiClient.post(`/events/${id}/attendance/manual`, payload)).data.data },
  async correctAttendance(eventId, attendanceId, payload) { return (await apiClient.patch(`/events/${eventId}/attendance/${attendanceId}`, payload)).data.data },
}

export const eventErrorMessage = (error) =>
  error?.response?.data?.message || Object.values(error?.response?.data?.errors || {})?.[0]?.[0] || 'Unable to complete the request. Please try again.'
