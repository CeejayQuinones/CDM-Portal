import { apiClient } from '../../services/apiClient'

export async function getRegistrarDashboard() {
  const response = await apiClient.get('/registrar/dashboard')

  return response.data.data
}
