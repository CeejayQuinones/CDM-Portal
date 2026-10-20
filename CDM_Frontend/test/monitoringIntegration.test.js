import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const source = async (path) => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('monitoring navigation permits students, professors, registrar staff, and admin', async () => {
  const access = await source('src/config/accessControl.js')
  assert.match(access, /monitoring: \[ROLES\.STUDENT, ROLES\.PROFESSOR, ROLES\.REGISTRAR_STAFF, ROLES\.ADMIN\]/)
  assert.doesNotMatch(access, /monitoring: \[[^\]]*ROLES\.GUEST/)
  assert.match(access, /label: 'Academic Monitoring'/)
})

test('monitoring API uses scoped backend monitoring endpoints', async () => {
  const api = await source('src/modules/monitoring/services/monitoringApi.js')
  assert.match(api, /\/monitoring\/overview/)
  assert.match(api, /\/monitoring\/my-risk/)
  assert.match(api, /\/monitoring\/students\/\$\{studentId\}\/ai-help/)
  assert.match(api, /\/monitoring\/students\/\$\{studentId\}\/risk-notifications/)
  assert.match(api, /\/monitoring\/my-risk-notifications/)
  assert.match(api, /\/monitoring\/risk-notifications\/\$\{notificationId\}\/read/)
  assert.match(api, /\/monitoring\/gradebook/)
  assert.match(api, /\/monitoring\/gradebook\/import/)
  assert.match(api, /\/monitoring\/study-studio/)
  assert.match(api, /deletePerformanceRecord/)
  assert.match(api, /\/monitoring\/performance-records\/\$\{recordId\}/)
  assert.doesNotMatch(api, /document-requests|physical-records/)
})

test('academic monitoring adds gradebook, registrar approval, and student study studio', async () => {
  const view = await source('src/modules/monitoring/MonitoringView.vue')
  assert.match(view, /Academic risk overview/)
  assert.match(view, /ProfessorGradebook/)
  assert.match(view, /RegistrarGradePanel/)
  assert.match(view, /StudentStudyStudio/)
  assert.match(view, /AiHelpPanel/)
  assert.match(view, /AI Study Studio/)
  assert.match(view, /Gradebook \(Spreadsheet\)/)
  assert.match(view, /Student Records & Plans/)
  assert.match(view, /flex-wrap: wrap/)
  const studio = await source('src/modules/monitoring/components/StudentStudyStudio.vue')
  assert.match(studio, /deletePerformanceRecord/)
  assert.match(studio, /confirmDeleteFile/)
  assert.match(studio, /Read first/)
  assert.match(studio, /displayName/)
  assert.match(studio, /overflow-wrap: anywhere/)
  const gradebook = await source('src/modules/monitoring/components/ProfessorGradebook.vue')
  assert.match(gradebook, /deletePerformanceRecord/)
  assert.match(gradebook, /deleteSelectedScore/)
  assert.match(gradebook, /The file is read first/)
  assert.match(view, /isStudent \|\| isProfessor/)
  assert.match(view, /sendRiskNotification/)
  assert.match(view, /Notify student/)
  assert.match(view, /Low grade notice/)
  assert.match(view, /Student risk roster/)
  assert.match(view, /filterByRisk/)
  assert.doesNotMatch(view, /if \(isAdmin\.value\)[\s\S]{0,280}Study Plans/)
})

test('phase three AI Help is advisory, session-only, responsive, and safely rendered', async () => {
  const panel = await source('src/modules/monitoring/components/AiHelpPanel.vue')
  const api = await source('src/modules/monitoring/services/monitoringApi.js')

  for (const text of [
    'AI-generated academic guidance',
    'Published evidence only',
    'study strategies',
    'time-management',
    'English, Tagalog, or Taglish',
    'Deterministic guidance',
    'official results are incomplete',
  ]) assert.match(panel, new RegExp(text, 'i'))

  assert.match(panel, /askAiHelp/)
  assert.match(panel, /sending\.value = true/)
  assert.match(panel, /temporarily unavailable/)
  assert.match(panel, /risk_level === 'insufficient'/)
  assert.match(panel, /\{\{ message\.text \}\}/)
  assert.doesNotMatch(panel, /v-html|localStorage|sessionStorage/)
  assert.match(panel, /var\(--bg-surface\)/)
  assert.match(panel, /@media \(max-width: 680px\)/)
  assert.match(api, /\/monitoring\/ai-status/)
  assert.match(api, /\/monitoring\/students\/\$\{studentId\}\/ai-help/)
})

test('phase two study plans stay advisory and adapt to risk and incomplete data', async () => {
  const panel = await source('src/modules/monitoring/components/StudyPlansPanel.vue')
  for (const text of [
    'Suggested Academic Support Plan',
    'Focus subjects',
    'Suggested weekly schedule',
    'More Published grade data is needed',
    'Refresh plan',
  ]) assert.match(panel, new RegExp(text, 'i'))

  assert.match(panel, /fetchStudyPlans/)
  assert.match(panel, /fetchStudentStudyPlan/)
  assert.match(panel, /var\(--bg-surface\)/)
  assert.match(panel, /@media \(max-width: 680px\)/)
  assert.doesNotMatch(panel, /pass|fail|GWA|average_grade/i)
})

test('staff adviser alerts are computed and can send a scoped support notice', async () => {
  const panel = await source('src/modules/monitoring/components/AdviserAlertsPanel.vue')
  for (const text of [
    'Computed from Published results',
    'Urgent',
    'Needs attention',
    'Open Student',
    'Send Notice',
    'No current academic-risk alerts based on Published results',
  ]) assert.match(panel, new RegExp(text, 'i'))

  assert.match(panel, /fetchAdviserAlerts/)
  assert.match(panel, /sendRiskNotification/)
  assert.match(panel, /notice\.duplicate/)
  assert.match(panel, /var\(--bg-surface\)/)
  assert.match(panel, /@media \(max-width: 760px\)/)
})

test('student notices preserve server unread state and mark read only when opened', async () => {
  const panel = await source('src/modules/monitoring/components/MyNoticesPanel.vue')
  for (const text of [
    'My Notices',
    'unread',
    'Open Monitoring',
    'View Published Grades',
    'You have no academic support notices',
  ]) assert.match(panel, new RegExp(text, 'i'))

  assert.match(panel, /fetchMyRiskNotifications/)
  assert.match(panel, /const openNotice = async/)
  assert.match(panel, /markRiskNotificationRead\(notice\.id\)/)
  assert.doesNotMatch(panel, /localStorage/)
  assert.match(panel, /var\(--bg-surface\)/)
  assert.match(panel, /@media \(max-width: 680px\)/)
})
