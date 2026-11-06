import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import {
  assessmentLabelAfterCategoryChange,
  groupReadinessIssues,
  nextAssessmentLabel,
} from '../src/modules/grading/assessmentPresentation.js'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('assessment labels start at one and advance after the highest suffix', () => {
  assert.equal(nextAssessmentLabel('Quiz', []), 'Quiz 1')
  assert.equal(nextAssessmentLabel('Quiz', [
    { category: 'Quiz', label: 'Quiz 1' },
    { category: 'Quiz', label: 'Quiz 3' },
  ]), 'Quiz 4')
  assert.equal(nextAssessmentLabel('Activity', [{ category: 'Activity', label: 'Activity 2' }]), 'Activity 3')
})

test('assessment label matching is case-insensitive, trimmed, category-specific, and duplicate-safe', () => {
  const assessments = [
    { category: ' quiz ', label: '  QUIZ 2  ' },
    { category: 'Quiz', label: 'quiz 5' },
    { category: 'Activity', label: 'Quiz 6' },
    { category: 'Major Exam', label: 'Major Exam 1' },
  ]

  assert.equal(nextAssessmentLabel('Quiz', assessments), 'Quiz 7')
  assert.equal(nextAssessmentLabel('Major Exam', assessments), 'Major Exam 2')
  assert.equal(nextAssessmentLabel('Recitation', assessments), 'Recitation 1')
})

test('category changes preserve custom labels and replace only automatic labels', () => {
  const assessments = [{ category: 'Activity', label: 'Activity 1' }]
  assert.equal(assessmentLabelAfterCategoryChange({
    currentLabel: 'Preliminary Quiz',
    category: 'Activity',
    assessments,
    automatic: false,
  }), 'Preliminary Quiz')
  assert.equal(assessmentLabelAfterCategoryChange({
    currentLabel: 'Quiz 1',
    category: 'Activity',
    assessments,
    automatic: true,
  }), 'Activity 2')
  assert.equal(nextAssessmentLabel('Activity', assessments), 'Activity 2')
})

test('readiness issues retain every message and group conservatively by period', () => {
  const errors = [
    'Midterm requires an active Quiz assessment.',
    'Finals category weights must contain all categories and total 100%.',
    '2026-001 is missing Midterm Quiz 1.',
    '2026-002 is missing Finals Major Exam 1.',
    'Midterm and Finals weights must total 100%.',
    'The official class roster is empty.',
  ]
  const assessments = [
    { period: 'midterm', label: 'Midterm Quiz 1' },
    { period: 'finals', label: 'Finals Major Exam 1' },
  ]
  const groups = Object.fromEntries(groupReadinessIssues(errors, assessments).map(group => [group.key, group]))

  assert.equal(groups.midterm.issues.length, 2)
  assert.equal(groups.finals.issues.length, 2)
  assert.equal(groups.other.label, 'Other requirements')
  assert.deepEqual(groups.other.issues, [
    'Midterm and Finals weights must total 100%.',
    'The official class roster is empty.',
  ])
  assert.deepEqual(Object.values(groups).flatMap(group => group.issues).sort(), [...errors].sort())
})

test('website Professor UI exposes automatic naming and a dedicated accessible submission workspace', async () => {
  const [view, submission, css, service] = await Promise.all([
    read('src/modules/grading/GradingView.vue'),
    read('src/modules/grading/ProfessorGradeSubmissionView.vue'),
    read('src/modules/grading/grading.css'),
    read('src/modules/grading/gradingService.js'),
  ])

  assert.match(view, /label:isWebsitePresentation\?suggestedAssessmentLabel\(category\):''/)
  assert.match(view, /assessmentLabelIsAutomatic\.value=isWebsitePresentation/)
  assert.match(view, /Use next available/)
  assert.match(view, /Custom label preserved when the category changes/)
  assert.match(view, /await gradingService\.addAssessment/)
  assert.match(view, /await openClass\(workspace\.value\.class\)/)
  assert.match(service, /addAssessment: \(sheet, payload\) => apiClient\.post/)

  assert.match(view, /name:'grading-submission'/)
  assert.match(view, /<section v-if="!isWebsitePresentation" class="grading-readiness">/)
  assert.doesNotMatch(view, /v-if="isWebsitePresentation" class="submission-readiness-panel"/)
  assert.match(submission, /class="submission-readiness-panel expanded"/)
  assert.match(submission, /issueGroups\.filter/)
  assert.match(submission, /<details v-for=/)
  assert.match(submission, /<summary>/)
  assert.match(submission, /Review \{\{group\.label\}\} assessments/)
  assert.match(submission, /@click="saveFinalWeights"/)
  assert.match(submission, /:disabled="saving\|\|!canSubmit"/)
  assert.match(submission, /readiness\.value\?\.ready&&submissionWindow\.value==='Open'/)
  assert.match(submission, /window\.confirm/)

  assert.match(css, /\.submission-readiness-panel/)
  assert.match(css, /summary:focus-visible/)
  assert.match(css, /var\(--bg-surface\)/)
  assert.match(css, /@media \(max-width: 420px\)/)
  assert.match(css, /prefers-reduced-motion: reduce/)
})
