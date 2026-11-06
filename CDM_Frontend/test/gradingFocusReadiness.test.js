import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { summarizeReadinessGroups } from '../src/modules/grading/assessmentPresentation.js'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('readiness summary includes midterm, finals, and unclassified blockers', () => {
  assert.deepEqual(summarizeReadinessGroups([
    { key: 'midterm', issues: ['One', 'Two'] },
    { key: 'finals', issues: ['Three'] },
    { key: 'other', issues: ['Four', 'Five'] },
  ]), { midterm: 2, finals: 1, other: 2, total: 5 })
})

test('score inputs defer all visible focus styling to one stable cell wrapper', async () => {
  const [view, styles] = await Promise.all([
    read('src/modules/grading/GradingView.vue'),
    read('src/modules/grading/grading.css'),
  ])

  assert.match(view, /type="number"[\s\S]*@input="updateScore\(s,column\.assessment,\$event\.target\.value\)"/)
  assert.match(view, /@keydown="handleScoreKeydown"/)
  assert.match(view, /if\(event\.key==='Enter'\)[\s\S]*moveScoreSelection\(1,0\)/)
  assert.match(view, /class="grade-score-cell"/)
  assert.match(styles, /\.website-professor-grading \.grade-score-cell input \{[\s\S]*appearance: textfield;[\s\S]*border: 0 !important;[\s\S]*box-shadow: none !important;/)
  assert.match(styles, /input::-webkit-inner-spin-button,[\s\S]*input::-webkit-outer-spin-button \{[\s\S]*-webkit-appearance: none;/)
  assert.match(styles, /input:focus-visible,[\s\S]*outline: 0 !important;/)
  assert.match(styles, /\.website-professor-grading \.registrar-inspired-grid \.grade-score-cell\.is-active \{[\s\S]*inset 0 0 0 2px var\(--accent\);[\s\S]*z-index: 6;/)
})

test('submission readiness is a distinct Professor grading route', async () => {
  const [view, submission, router] = await Promise.all([
    read('src/modules/grading/GradingView.vue'),
    read('src/modules/grading/ProfessorGradeSubmissionView.vue'),
    read('src/router/index.js'),
  ])

  assert.match(view, /openSubmissionPage/)
  assert.match(view, /name:'grading-submission'/)
  assert.match(submission, /GRADE SUBMISSION/)
  assert.match(submission, /class="submission-readiness-panel expanded"/)
  assert.match(router, /path: 'grading\/submission'/)
  assert.match(router, /platforms: \['web'\]/)
})

test('submission page retains authoritative details and submission controls', async () => {
  const view = await read('src/modules/grading/ProfessorGradeSubmissionView.vue')

  assert.match(view, /class="submission-status-overview"/)
  assert.match(view, /issueGroups\.filter/)
  assert.match(view, /@click="saveFinalWeights"/)
  assert.match(view, /:disabled="saving\|\|!canSubmit"/)
  assert.match(view, /@click="submitSheet"/)
  assert.match(view, /canSubmit=computed\(\(\)=>readiness\.value\?\.ready&&submissionWindow\.value==='Open'/)
})

test('submission layout is stable and respects reduced motion', async () => {
  const styles = await read('src/modules/grading/grading.css')

  assert.match(styles, /\.grade-submission-context \{[\s\S]*display: flex;/)
  assert.match(styles, /\.grade-entry-workspace-grid \{[\s\S]*grid-template-columns:/)
  assert.match(styles, /@media \(prefers-reduced-motion: reduce\)[\s\S]*\.submission-readiness-panel \*[\s\S]*transition: none !important;/)
})

test('focus and readiness refinements remain website-only and use semantic theme tokens', async () => {
  const [view, styles] = await Promise.all([
    read('src/modules/grading/ProfessorGradeSubmissionView.vue'),
    read('src/modules/grading/grading.css'),
  ])

  assert.match(view, /website-professor-grading/)
  assert.match(view, /submission-readiness-panel expanded/)
  assert.match(styles, /\.website-professor-grading \.grade-score-cell/)
  assert.match(styles, /var\(--bg-surface\)/)
  assert.match(styles, /var\(--warning-bg\)/)
  assert.match(styles, /var\(--accent\)/)
})
