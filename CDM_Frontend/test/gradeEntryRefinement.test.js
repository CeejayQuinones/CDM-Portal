import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('grouped grade headers reserve readable widths and expose stable group boundaries', async () => {
  const [view, css] = await Promise.all([
    read('src/modules/grading/GradingView.vue'),
    read('src/modules/grading/grading.css'),
  ])

  assert.match(view, /<colgroup>/)
  assert.match(view, /v-for="column in gradeTableModel\.columns"/)
  assert.match(view, /:colspan="group\.columns\.length"/)
  assert.match(view, /:data-group-start="column\.groupStart\|\|undefined"/)
  assert.match(css, /The colgroup and gradeTableModel are the sole source/)
  assert.match(css, /table-layout: fixed;/)
  assert.match(css, /\[data-group-start='true'\][\s\S]*border-left: 2px solid/)
  assert.match(css, /thead tr:first-child th \{[\s\S]*height: 46px;/)
  assert.match(css, /thead tr:nth-child\(2\) th \{[\s\S]*height: 64px;/)
})

test('student identity, score, total, and result columns use balanced spreadsheet proportions', async () => {
  const model = await read('src/modules/grading/gradeTableModel.js')
  const css = await read('src/modules/grading/grading.css')

  for (const declaration of [
    'sequence: 48',
    'studentNumber: 124',
    'studentName: 200',
    'assessment: 116',
    'rawTotal: 104',
    'result: 116',
    'remarks: 128',
  ]) assert.match(model, new RegExp(declaration))
  assert.match(css, /\.registrar-inspired-grid th,[\s\S]*height: 46px;/)
  assert.doesNotMatch(model, /stickyLeft|sticky:/)
  assert.doesNotMatch(css, /--grade-sticky-left/)
})

test('website toolbar preserves one primary assessment action and structured secondary actions', async () => {
  const [view, css] = await Promise.all([
    read('src/modules/grading/GradingView.vue'),
    read('src/modules/grading/grading.css'),
  ])

  assert.match(view, /class="grading-button primary"[^>]*>\+ Add Assessment/)
  for (const label of ['Edit Columns', 'Bulk Entry', 'Criteria']) {
    assert.match(view, new RegExp(`class="grading-button secondary"[^>]*>${label}`))
  }
  assert.match(css, /\.website-professor-grading \.grade-sheet-toolbar \{[\s\S]*grid-template-columns: minmax\(540px, 1\.3fr\) minmax\(360px, \.7fr\);/)
  assert.match(css, /@media \(max-width: 1180px\)[\s\S]*\.website-professor-grading \.grade-sheet-toolbar[\s\S]*grid-template-columns: minmax\(0, 1fr\);/)
  assert.match(css, /@media \(max-width: 820px\)[\s\S]*\.website-professor-grading \.grade-sheet-toolbar[\s\S]*grid-template-columns: 1fr;/)
})

test('save strip distinguishes saved, dirty, and invalid score states without changing save behavior', async () => {
  const [view, css] = await Promise.all([
    read('src/modules/grading/GradingView.vue'),
    read('src/modules/grading/grading.css'),
  ])

  assert.match(view, /:data-state="invalidScoreCount\?'invalid':dirtyScoreCount\?'dirty':'saved'"/)
  assert.match(view, /dirtyScoreCount\.value===1\?'score':'changes'/)
  assert.match(view, /All score changes are saved\./)
  assert.match(view, /class="grade-save-actions"/)
  assert.match(view, /@click="discardScoreChanges"/)
  assert.match(view, /@click="saveAllScores"/)
  assert.match(css, /\.grade-sheet-savebar\[data-state='dirty'\]/)
  assert.match(css, /\.grade-sheet-savebar\[data-state='invalid'\]/)
})

test('criteria remains editable and stacks without compressing the spreadsheet', async () => {
  const [view, css] = await Promise.all([
    read('src/modules/grading/GradingView.vue'),
    read('src/modules/grading/grading.css'),
  ])

  assert.match(view, /v-for="item in criteriaRows"/)
  assert.match(view, /item\.maximum_score/)
  assert.match(view, /item\.weight\.weight_percentage/)
  assert.match(view, /@click="saveWeights"/)
  assert.match(css, /grid-template-columns: minmax\(0, 1fr\) minmax\(270px, 310px\);/)
  assert.match(css, /@media \(max-width: 1180px\)[\s\S]*grid-template-columns: minmax\(0, 1fr\);/)
})

test('refinement stays inside the website Professor grading presentation', async () => {
  const [view, css] = await Promise.all([
    read('src/modules/grading/GradingView.vue'),
    read('src/modules/grading/grading.css'),
  ])

  assert.match(view, /isWebsitePresentation&&professor/)
  assert.match(view, /<template v-else><section class="grading-window"/)
  assert.match(css, /\.website-professor-grading \.grade-sheet-toolbar/)
  assert.match(css, /\.website-professor-grading \.grade-entry-sheet \.grade-sheet-scroll/)
})
