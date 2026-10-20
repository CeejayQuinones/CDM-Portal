import { apiClient } from '../../services/apiClient'

async function getDashboard(path) {
  const response = await apiClient.get(path)

  return response.data.data
}

export const getStudentDashboard = () => getDashboard('/student/dashboard')
export const getProfessorDashboard = () => getDashboard('/professor/dashboard')
export const getAdminDashboard = () => getDashboard('/admin/dashboard')

