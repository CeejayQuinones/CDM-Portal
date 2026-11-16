import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('criteria can be dragged without moving the grade sheet', async () => {
  const [view, css] = await Promise.all([
    read('src/modules/grading/GradingView.vue'),
    read('src/modules/grading/grading.css'),
  ])
  assert.match(view, /@pointerdown="startCriteriaDrag"/)
  assert.match(view, /@pointermove="moveCriteriaDrag"/)
  assert.match(view, /@keydown="moveCriteriaWithKeys"/)
  assert.match(view, /setPointerCapture\(event\.pointerId\)/)
  assert.match(css, /\.criteria-drag-header \{/)
  assert.match(css, /touch-action: none/)
  assert.match(css, /\.grade-entry-workspace-grid \.grade-entry-criteria \{[^}]*position: absolute/)
})

test('assessment modal creates custom categories and opens criteria for their weight', async () => {
  const view = await read('src/modules/grading/GradingView.vue')
  assert.match(view, /\+ Create new category/)
  assert.match(view, /create_category:addedCategory/)
  assert.match(view, /Set its weight in Show criteria after adding/)
  assert.match(view, /addedCategory\)openCriteria\(\)/)
  assert.match(view, /unweightedCategories\.length/)
  assert.match(view, /Set category weight/)
  assert.match(view, /Math\.max\(0,\.\.\.assessments\.value\.map/)
  assert.doesNotMatch(view, /Use next available/)
})
