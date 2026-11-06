import { apiClient } from './apiClient'

export async function getCampusNotices(params = {}) {
  const response = await apiClient.get('/campus-notices', { params })
  return response.data.data
}

export async function saveCampusNotice(payload, id = null) {
  const response = id
    ? await apiClient.put(`/campus-notices/${id}`, payload)
    : await apiClient.post('/campus-notices', payload)
  return response.data.data
}

export async function publishCampusNotice(id) {
  const response = await apiClient.post(`/campus-notices/${id}/publish`)
  return response.data.data
}

export async function archiveCampusNotice(id) {
  const response = await apiClient.post(`/campus-notices/${id}/archive`)
  return response.data.data
}
