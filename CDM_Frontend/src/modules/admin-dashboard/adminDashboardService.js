import { apiClient } from '../../services/apiClient'

export async function fetchAdminUsers(params = {}) {
  const response = await apiClient.get('/admin/users', { params })
  return response.data.data
}
