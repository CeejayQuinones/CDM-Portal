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
  assert.doesNotMatch(api, /document-requests|physical-records/)
})

test('phase one overview presents published-only risk evidence and explicit data states', async () => {
  const view = await source('src/modules/monitoring/MonitoringView.vue')
  for (const text of [
    'Academic risk overview',
    'High',
    'Moderate',
    'Stable',
    'Insufficient data',
    'Academic trend',
    'Subjects analyzed',
    'Data completeness',
    'Why this level was assigned',
    'Published subject signals',
    'How Academic Monitoring works',
  ]) assert.match(view, new RegExp(text, 'i'))

  assert.match(view, /fetchMonitoringOverview/)
  assert.match(view, /var\(--bg-surface\)/)
  assert.match(view, /var\(--text-primary\)/)
  assert.match(view, /@media \(max-width: 680px\)/)
  for (const text of ['Early Warnings', 'Study Plan', 'My Notices', 'Adviser Alerts', 'AI Help']) {
    assert.match(view, new RegExp(text))
  }
  assert.match(view, /StudyPlansPanel/)
  assert.match(view, /AdviserAlertsPanel/)
  assert.match(view, /MyNoticesPanel/)
  assert.match(view, /AiHelpPanel/)
  assert.doesNotMatch(view, /AiHelpChatbot|average_grade|GWA|Passed|Failed/)
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
