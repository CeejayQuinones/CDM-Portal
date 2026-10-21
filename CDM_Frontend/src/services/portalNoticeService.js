import { apiClient } from './apiClient'

export async function getPortalNotices() {
  const response = await apiClient.get('/portal-notices')

  return response.data.data
}
