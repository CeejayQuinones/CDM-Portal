import { apiClient } from '../../../services/apiClient'

export const fetchEarlyWarnings = async () => {
  const { data } = await apiClient.get('/monitoring/early-warnings')
  return data.data
}

export const fetchMyRisk = async () => {
  const { data } = await apiClient.get('/monitoring/my-risk')
  return data.data
}

export const generateSupportPlan = async (studentId) => {
  const { data } = await apiClient.post(`/monitoring/students/${studentId}/support-plan`)
  return data.data
}

export const fetchStudyPlans = async () => {
  const { data } = await apiClient.get('/monitoring/study-plans')
  return data.data
}

export const fetchStudentStudyPlan = async (studentId) => {
  const { data } = await apiClient.get(`/monitoring/students/${studentId}/study-plan`)
  return data.data
}

export const fetchAdviserAlerts = async () => {
  const { data } = await apiClient.get('/monitoring/adviser-alerts')
  return data.data
}

export const fetchAiStatus = async () => {
  const { data } = await apiClient.get('/monitoring/ai-status')
  return data.data
}

export const askAiHelp = async (studentId, question = '', messages = []) => {
  const { data } = await apiClient.post(`/monitoring/students/${studentId}/ai-help`, {
    question: question || null,
    messages,
  })
  return data.data
}
