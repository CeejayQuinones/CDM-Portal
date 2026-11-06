import { apiClient } from './apiClient'

let noticesRequest = null

export async function getPortalNotices() {
  if (noticesRequest) return noticesRequest

  const request = apiClient.get('/portal-notices').then(response => response.data.data)
  noticesRequest = request

  try {
    return await request
  } finally {
    if (noticesRequest === request) noticesRequest = null
  }
}

export async function acknowledgePortalNotice(noticeId) {
  const response = await apiClient.post(`/portal-notices/${noticeId}/acknowledge`)

  return response.data.data
}
