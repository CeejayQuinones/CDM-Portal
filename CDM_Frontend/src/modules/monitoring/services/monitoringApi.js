import { apiClient } from '../../../services/apiClient'

const unwrap = async (request) => (await request).data.data

export const fetchEarlyWarnings = () => unwrap(apiClient.get('/monitoring/early-warnings'))
export const fetchMyRisk = () => unwrap(apiClient.get('/monitoring/my-risk'))
export const fetchStudyPlans = () => unwrap(apiClient.get('/monitoring/study-plans'))
export const fetchStudentStudyPlan = (studentId) => unwrap(apiClient.get(`/monitoring/students/${studentId}/study-plan`))
export const fetchAdviserAlerts = () => unwrap(apiClient.get('/monitoring/adviser-alerts'))
export const askAiHelp = (studentId, question) => unwrap(apiClient.post(`/monitoring/students/${studentId}/ai-help`, { question }))
export const sendRiskNotification = (studentId, message) => unwrap(apiClient.post(`/monitoring/students/${studentId}/risk-notifications`, { message }))
export const fetchMyRiskNotifications = () => unwrap(apiClient.get('/monitoring/my-risk-notifications'))
export const markRiskNotificationRead = (notificationId) => unwrap(apiClient.patch(`/monitoring/risk-notifications/${notificationId}/read`))
