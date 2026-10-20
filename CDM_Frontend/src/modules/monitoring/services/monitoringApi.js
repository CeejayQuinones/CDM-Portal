import { apiClient } from '../../../services/apiClient'

const unwrap = async (request) => (await request).data.data

export const fetchMonitoringOverview = (params = {}) => unwrap(apiClient.get('/monitoring/overview', { params }))
export const fetchEarlyWarnings = (params = {}) => unwrap(apiClient.get('/monitoring/early-warnings', { params }))
export const fetchMyRisk = (params = {}) => unwrap(apiClient.get('/monitoring/my-risk', { params }))
export const fetchStudyPlans = (params = {}) => unwrap(apiClient.get('/monitoring/study-plans', { params }))
export const fetchStudentStudyPlan = (studentId) => unwrap(apiClient.get(`/monitoring/students/${studentId}/study-plan`))
export const fetchAdviserAlerts = (params = {}) => unwrap(apiClient.get('/monitoring/adviser-alerts', { params }))
export const fetchAiHelpStatus = () => unwrap(apiClient.get('/monitoring/ai-status'))
export const askAiHelp = (studentId, question) => unwrap(apiClient.post(`/monitoring/students/${studentId}/ai-help`, { question }))
export const sendRiskNotification = (studentId, message = '', title = '') => unwrap(apiClient.post(`/monitoring/students/${studentId}/risk-notifications`, { message: message || null, title: title || null }))
export const fetchMyRiskNotifications = () => unwrap(apiClient.get('/monitoring/my-risk-notifications'))
export const markRiskNotificationRead = (notificationId) => unwrap(apiClient.patch(`/monitoring/risk-notifications/${notificationId}/read`))
