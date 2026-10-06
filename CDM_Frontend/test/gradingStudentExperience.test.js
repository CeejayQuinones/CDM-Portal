import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const student = readFileSync(new URL('../src/modules/grading/StudentGradesView.vue', import.meta.url), 'utf8')
const messages = readFileSync(new URL('../src/modules/grading/GradeConversationPanel.vue', import.meta.url), 'utf8')
const professor = readFileSync(new URL('../src/modules/grading/ProfessorGradeMessages.vue', import.meta.url), 'utf8')
const service = readFileSync(new URL('../src/modules/grading/gradingService.js', import.meta.url), 'utf8')
const access = readFileSync(new URL('../src/config/accessControl.js', import.meta.url), 'utf8')
const history = readFileSync(new URL('../src/modules/student-management/StudentAcademicHistory.vue', import.meta.url), 'utf8')
const staffHistory = readFileSync(new URL('../src/modules/grading/StudentGradeHistoryView.vue', import.meta.url), 'utf8')
const router = readFileSync(new URL('../src/router/index.js', import.meta.url), 'utf8')
const css = readFileSync(new URL('../src/modules/grading/grading.css', import.meta.url), 'utf8')

test('Student published grade experience exposes term history, completion, unavailable GWA, and export', () => {
  for (const text of ['My Grades', 'Published / Enrolled', 'Total Units', 'Not available', 'Academic Year', 'Semester', 'No published grades', 'incomplete', 'Message Professor', 'Export Grade Report']) assert.match(student, new RegExp(text, 'i'))
  assert.match(service, /\/grading\/student\/grades/)
  assert.match(service, /responseType: 'blob'/)
  assert.doesNotMatch(student, /Passed|Failed|Dean's List|Probation/)
})

test('grade messaging provides server threads, image attachment, unread state, replies, and unsend', () => {
  for (const text of ['No messages yet', 'Optional image', 'maximum 5 MB', 'Send Message', 'Unsend', 'Read']) assert.match(messages, new RegExp(text, 'i'))
  for (const text of ['No conversations', 'unread_count', 'Select a conversation']) assert.match(professor, new RegExp(text, 'i'))
  for (const endpoint of ['/professor/conversations', '/conversations/', '/messages/', '/attachment']) assert.match(service, new RegExp(endpoint.replaceAll('/', '\\/')))
})

test('Registrar Student Records includes published grade history and CSV export', () => {
  for (const text of ['Published Academic Grades', 'Official released submission snapshots only', 'Final Grade', 'Grade Point', 'Export CSV']) assert.match(history, new RegExp(text, 'i'))
  assert.match(service, /staffStudentGrades/)
  assert.match(service, /staffStudentExport/)
})

test('role navigation and responsive semantic styling cover the final grading workflow', () => {
  for (const text of ['My Grades', 'Grade Messages', 'Student Grade History']) assert.match(access, new RegExp(text))
  assert.match(access, /grading: \[ROLES\.STUDENT, ROLES\.PROFESSOR, ROLES\.REGISTRAR_STAFF, ROLES\.ADMIN\]/)
  assert.match(css, /var\(--bg-surface/)
  assert.match(css, /grade-chat-bubble/)
  assert.match(css, /@media\(max-width:760px\)/)
  assert.match(css, /data-label/)
})

test('Student My Grades uses shared light and dark semantic surfaces', () => {
  for (const token of ['--bg-surface', '--bg-surface-alt', '--bg-input', '--bg-hover', '--text-primary', '--text-secondary', '--border-color', '--warning-bg', '--danger-bg', '--skeleton-base']) {
    assert.match(css, new RegExp(token))
  }
  for (const selector of ['student-grade-summary', 'student-term-filter', 'student-grade-table', 'grade-chat-history', 'grading-skeleton']) {
    assert.match(css, new RegExp(selector))
  }
  assert.match(student, /student-grades-skeleton/)
  assert.doesNotMatch(css, /#[fF]{3,6}|#f8f8f8|#f1f1f1/)
  assert.match(css, /\.grading-page :is\(input, select, textarea\)/)
  assert.match(css, /\.grading-modal > header/)
  assert.match(css, /\.grading-release-form fieldset/)
})

test('Registrar and Admin Student Grade History uses a dedicated grading route and published-grade API', () => {
  assert.match(access, /path: '\/grading\/student-history'/)
  assert.doesNotMatch(access, /label: 'Student Grade History', path: '\/student-management'/)
  assert.match(router, /name: 'grading-student-history'/)
  assert.match(router, /StudentGradeHistoryView/)
  for (const text of ['Student Grade History', 'Student name / number', 'Program', 'Year Level', 'Section', 'View Grades', 'Published At', 'Export CSV']) {
    assert.match(staffHistory, new RegExp(text, 'i'))
  }
  assert.match(staffHistory, /staffStudentGrades/)
  assert.match(staffHistory, /staffStudentExport/)
  assert.match(history, /Published Academic Grades/)
})
