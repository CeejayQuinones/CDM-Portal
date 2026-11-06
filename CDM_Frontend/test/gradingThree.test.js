import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('Grade Entry follows the compact reference layout with real grouped assessment data', async () => {
  const [view, styles] = await Promise.all([
    read('src/modules/grading/GradingView.vue'),
    read('src/modules/grading/grading.css'),
  ])

  assert.match(view, />GRADE ENTRY</)
  assert.match(view, /class="grade-sheet-toolbar"/)
  assert.match(view, /\+ Add Assessment/)
  assert.match(view, />Edit Columns</)
  assert.match(view, />Bulk Entry</)
  assert.match(view, />Criteria</)
  assert.match(view, /buildGradeTableModel/)
  assert.match(view, /:colspan="group\.columns\.length"/)
  assert.match(view, /class="grade-entry-workspace-grid"/)
  assert.match(view, /class="grade-entry-sheet"/)
  assert.match(view, /class="grade-entry-criteria"/)
  assert.match(styles, /\.website-professor-grading \.grade-entry-workspace-grid \{[\s\S]*grid-template-columns: minmax\(0, 1fr\) minmax\(270px, 310px\)/)
})

test('Grade Entry preserves score validation, one save action, column management, and navigation protection', async () => {
  const view = await read('src/modules/grading/GradingView.vue')

  assert.match(view, /@click="saveAllScores"/)
  assert.match(view, /clearSavedScoreBatch/)
  assert.match(view, /remain.*unsaved/)
  assert.match(view, /@click="requestAssessmentArchive/)
  assert.match(view, /onBeforeRouteLeave/)
  assert.match(view, /unsaved score changes/)
  assert.match(view, /Save or discard pending score changes before opening Grade Submission/)
})

test('Grade Submission is website-only and Professor-only', async () => {
  const [router, access] = await Promise.all([
    read('src/router/index.js'),
    read('src/config/accessControl.js'),
  ])

  assert.match(router, /path: 'grading\/submission'[\s\S]*name: 'grading-submission'[\s\S]*roles: ROUTE_ROLES\['grading-submission'\][\s\S]*platforms: \['web'\]/)
  assert.match(access, /'grading-submission': \[ROLES\.PROFESSOR\]/)
  assert.match(access, /activeRoutes: \['grading-submission'\]/)
})

test('Grade Submission uses existing readiness, weights, and submit APIs with confirmation', async () => {
  const [submission, service] = await Promise.all([
    read('src/modules/grading/ProfessorGradeSubmissionView.vue'),
    read('src/modules/grading/gradingService.js'),
  ])

  assert.match(submission, /gradingService\.readiness/)
  assert.match(submission, /gradingService\.saveFinalWeights/)
  assert.match(submission, /gradingService\.submit/)
  assert.match(submission, /window\.confirm/)
  assert.match(submission, /Missing requirements/)
  assert.match(submission, /Final grade weights/)
  assert.match(service, /saveFinalWeights: \(sheet, payload\) => apiClient\.put/)
  assert.match(service, /submit: \(sheet, version\) => apiClient\.post/)
})

test('Grading 3.0 uses semantic tokens and contained responsive overflow', async () => {
  const styles = await read('src/modules/grading/grading.css')

  assert.match(styles, /\.grouped-grade-sheet thead th \{[\s\S]*var\(--accent-soft\)[\s\S]*var\(--bg-surface\)/)
  assert.match(styles, /\.grade-entry-sheet \{[\s\S]*min-width: 0;/)
  assert.match(styles, /\.grade-sheet-scroll \{[\s\S]*overflow: auto;/)
  assert.match(styles, /@media \(max-width: 840px\)[\s\S]*\.grade-entry-workspace-grid \{[\s\S]*grid-template-columns: minmax\(0, 1fr\)/)
  assert.match(styles, /@media \(max-width: 390px\)/)
})
