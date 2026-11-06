import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { buildGradeTableModel, GRADE_COLUMN_WIDTHS } from '../src/modules/grading/gradeTableModel.js'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')
const assessment = (id, category = 'A deliberately long assessment category name') => ({
  id,
  category,
  label: `A deliberately long assessment label ${id}`,
  max_score: 100,
  status: 'active',
})

test('gradesheet geometry remains deterministic from zero through twenty assessments', () => {
  for (const count of [0, 1, 5, 10, 20]) {
    const model = buildGradeTableModel(Array.from({ length: count }, (_, index) => assessment(index + 1)))
    const fixedWidth = GRADE_COLUMN_WIDTHS.sequence
      + GRADE_COLUMN_WIDTHS.studentNumber
      + GRADE_COLUMN_WIDTHS.studentName
      + GRADE_COLUMN_WIDTHS.rawTotal
      + (GRADE_COLUMN_WIDTHS.result * 4)
      + GRADE_COLUMN_WIDTHS.remarks

    assert.equal(model.assessmentColumns.length, count)
    assert.equal(model.columnCount, count + 9)
    assert.equal(model.totalWidth, fixedWidth + (count * GRADE_COLUMN_WIDTHS.assessment))
    assert.equal(model.assessmentGroups.reduce((total, group) => total + group.columns.length, 0), count)
  }
})

test('website gradesheet renders aligned two-row headers without sticky column metadata', async () => {
  const [view, modelSource] = await Promise.all([
    read('src/modules/grading/GradingView.vue'),
    read('src/modules/grading/gradeTableModel.js'),
  ])

  assert.match(view, /<colgroup>[\s\S]*v-for="column in gradeTableModel\.columns"/)
  assert.match(view, /:colspan="gradeTableModel\.studentInformationColumns\.length"[^>]*>Student Information/)
  assert.match(view, /v-for="column in gradeTableModel\.studentInformationColumns"/)
  assert.doesNotMatch(modelSource, /stickyLeft|sticky:/)
  assert.match(view, /class="student-number"/)
  assert.doesNotMatch(view.slice(view.indexOf('class="professor-grade-sheet grouped-grade-sheet registrar-inspired-grid"'), view.indexOf('</table>', view.indexOf('class="professor-grade-sheet grouped-grade-sheet registrar-inspired-grid"'))), /class="[^"]*sticky/)
})

test('website gradesheet scrolls as one fixed-layout table and wraps essential labels', async () => {
  const css = await read('src/modules/grading/grading.css')
  const canonicalStart = css.indexOf('/* The colgroup and gradeTableModel are the sole source')
  const canonical = css.slice(canonicalStart, css.indexOf('.grade-entry-criteria {', canonicalStart))

  assert.match(canonical, /\.registrar-inspired-grid \{[\s\S]*min-width: var\(--grade-table-width\);[\s\S]*table-layout: fixed;[\s\S]*width: var\(--grade-table-width\);/)
  assert.match(css, /\.grade-entry-sheet \.grade-sheet-scroll \{[\s\S]*scrollbar-gutter: stable;/)
  assert.match(canonical, /\.grade-category-heading,[\s\S]*overflow-wrap: anywhere;[\s\S]*white-space: normal;/)
  assert.match(canonical, /\.grade-sheet-assessment > span \{[\s\S]*overflow-wrap: anywhere;[\s\S]*white-space: normal;/)
  assert.doesNotMatch(canonical, /--grade-sticky-left|position: sticky|text-overflow: ellipsis/)
})

test('rendering repair remains confined to the website Professor grade-entry branch', async () => {
  const view = await read('src/modules/grading/GradingView.vue')

  assert.match(view, /<template v-if="isWebsitePresentation">[\s\S]*registrar-inspired-grid/)
  assert.match(view, /<template v-else><section class="grading-window"/)
})
