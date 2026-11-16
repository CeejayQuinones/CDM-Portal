import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('Professor grade entry has audited save history without the live-preview banner', async () => {
  const [view, service, css] = await Promise.all([
    read('src/modules/grading/GradingView.vue'),
    read('src/modules/grading/gradingService.js'),
    read('src/modules/grading/grading.css'),
  ])

  assert.doesNotMatch(view, /Live preview · results update as you type/)
  assert.match(view, /<details class="grade-save-history">/)
  assert.match(view, /restoreSavedScores\(entry\)/)
  assert.match(view, /dirtyScoreCount\|\|editingColumns\|\|recoveryPending/)
  assert.match(service, /score-history/)
  assert.match(view, /<Transition name="criteria-drop">/)
  assert.doesNotMatch(view, /criteriaPanel\.value\?\.scrollIntoView/)
  assert.doesNotMatch(view, /is-criteria-collapsed/)
  assert.match(view, /focus\(\{preventScroll:true\}\)/)
  assert.match(css, /\.criteria-drop-enter-from/)
  assert.match(css, /clip-path: inset\(0 0 100% 0\)/)
  assert.doesNotMatch(css, /grading-criteria-reveal/)
  assert.match(css, /prefers-reduced-motion: reduce/)
})
