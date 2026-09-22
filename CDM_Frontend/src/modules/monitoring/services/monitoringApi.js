import { apiClient } from '../../../services/apiClient'

const unwrap = async (request) => (await request).data.data

export const fetchEarlyWarnings = (params = {}) => unwrap(apiClient.get('/monitoring/early-warnings', { params }))
export const fetchMyRisk = () => unwrap(apiClient.get('/monitoring/my-risk'))
export const fetchStudyPlans = () => unwrap(apiClient.get('/monitoring/study-plans'))
export const fetchStudentStudyPlan = (studentId) => unwrap(apiClient.get(`/monitoring/students/${studentId}/study-plan`))
export const fetchAdviserAlerts = () => unwrap(apiClient.get('/monitoring/adviser-alerts'))
export const fetchAiStatus = () => unwrap(apiClient.get('/monitoring/ai-status'))

export const askAiHelp = async (studentId, question = '', messages = []) => {
  const { data } = await apiClient.post(`/monitoring/students/${studentId}/ai-help`, {
    question: question || null,
    messages,
  })
  return data.data
}

export const sendRiskNotification = (studentId, message) =>
  unwrap(apiClient.post(`/monitoring/students/${studentId}/risk-notifications`, { message }))
export const fetchMyRiskNotifications = () => unwrap(apiClient.get('/monitoring/my-risk-notifications'))
export const markRiskNotificationRead = (notificationId) =>
  unwrap(apiClient.patch(`/monitoring/risk-notifications/${notificationId}/read`))

export const fetchPerformanceRecords = (studentId) =>
  unwrap(apiClient.get(`/monitoring/students/${studentId}/performance-records`))

export const createPerformanceRecord = (studentId, formData) =>
  unwrap(
    apiClient.post(`/monitoring/students/${studentId}/performance-records`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }),
  )

export const generateRecordStudyPlan = (studentId, recordId) =>
  unwrap(apiClient.post(`/monitoring/students/${studentId}/performance-records/${recordId}/generate-plan`))

export const sendRecordStudyPlan = (studentId, recordId, payload) =>
  unwrap(apiClient.post(`/monitoring/students/${studentId}/performance-records/${recordId}/send-plan`, payload))

export const fetchStudentSentPlans = (studentId) =>
  unwrap(apiClient.get(`/monitoring/students/${studentId}/sent-plans`))

export const fetchMySentPlans = () => unwrap(apiClient.get('/monitoring/my-sent-plans'))

export const markSentPlanRead = (planId) => unwrap(apiClient.patch(`/monitoring/sent-plans/${planId}/read`))

export const fetchStudyStudio = () => unwrap(apiClient.get('/monitoring/study-studio'))

export const generateStudyFlashcards = (topic) =>
  unwrap(apiClient.post('/monitoring/study-studio/flashcards', { topic: topic || null }))

export const generateStudyQuiz = (topic) =>
  unwrap(apiClient.post('/monitoring/study-studio/quiz', { topic: topic || null }))

export const generateStudyStudioPlan = (topic) =>
  unwrap(apiClient.post('/monitoring/study-studio/plan', { topic: topic || null }))
