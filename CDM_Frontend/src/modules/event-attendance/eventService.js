import { apiClient } from '../../services/apiClient'

export const eventService = {
  async list(params = {}) { return (await apiClient.get('/events', { params })).data.data },
  async show(id) { return (await apiClient.get(`/events/${id}`)).data.data },
  async options() { return (await apiClient.get('/events/options')).data.data },
  async create(payload) { return (await apiClient.post('/events', payload)).data.data },
  async update(id, payload) { return (await apiClient.put(`/events/${id}`, payload)).data.data },
  async transition(id, action, version) { return (await apiClient.post(`/events/${id}/${action}`, { version })).data.data },
}

export const eventErrorMessage = (error) =>
  error?.response?.data?.message || Object.values(error?.response?.data?.errors || {})?.[0]?.[0] || 'Unable to complete the request. Please try again.'
