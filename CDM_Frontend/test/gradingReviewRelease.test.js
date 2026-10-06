import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const view = readFileSync(new URL('../src/modules/grading/GradingView.vue', import.meta.url), 'utf8')
const service = readFileSync(new URL('../src/modules/grading/gradingService.js', import.meta.url), 'utf8')
const css = readFileSync(new URL('../src/modules/grading/grading.css', import.meta.url), 'utf8')

test('Professor Phase 2 workflow exposes readiness, submission, locks, return, and resubmission', () => {
  for (const text of ['Submission readiness', 'Submit to Registrar', 'Resubmit to Registrar', 'Returned:', 'Final Grade', 'Final weighting saved']) assert.match(view, new RegExp(text, 'i'))
  assert.match(view, /\['draft','returned'\]/)
  assert.match(view, /readiness\?\.errors/)
  assert.match(service, /\/readiness/)
  assert.match(service, /\/submit/)
})

test('Registrar Phase 2 workflow exposes review, approve, return, and release scheduling', () => {
  for (const text of ['Grade Approval', 'Pending Review', 'Review Grade Sheet', 'Approve', 'Reject &amp; Return', 'Release &amp; Schedule', 'Create Release Schedule', 'Release Now']) assert.match(view, new RegExp(text, 'i'))
  for (const endpoint of ['/grading/reviews', '/grading/releases', '/approve', '/return', '/execute']) assert.match(service, new RegExp(endpoint.replaceAll('/', '\\/')))
})

test('review and release surfaces preserve semantic dark mode and responsive behavior', () => {
  assert.match(css, /var\(--bg-surface/)
  assert.match(css, /grading-review-filters/)
  assert.match(css, /grading-release-form/)
  assert.match(css, /@media/)
})
